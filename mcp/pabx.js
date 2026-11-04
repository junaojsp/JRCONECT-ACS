// JR Conect PABX - Bridge REST HTTPS de somente leitura.
import { existsSync, readFileSync } from 'node:fs';
import { z } from 'zod';

const PABX_URL = 'https://pabx.jrconect.com/api/mcp/index.php';
const TOKEN_PATH = '/run/secrets/pabx-read-token';
const MAX_RESPONSE_BYTES = 256 * 1024;

const READ_TOOLS = Object.freeze([
  ['pabx_status', 'status', 'Consulta a versao, uptime e canais ativos do PABX Issabel 5.'],
  ['pabx_filas', 'queues', 'Consulta o estado das filas de atendimento do Asterisk.'],
  ['pabx_ramais', 'extensions', 'Consulta os endpoints PJSIP no PABX.'],
  ['pabx_troncos', 'trunks', 'Consulta os registros de troncos PJSIP.'],
  ['pabx_ixc_arquivos', 'ixc_files', 'Lista os metadados dos arquivos da integracao IXCsoft/Phonevox, sem divulgar codigo, dados pessoais ou credenciais.'],
]);

function configured() {
  return process.env.PABX_API_ENABLED === 'true' && existsSync(TOKEN_PATH);
}

function readBearer() {
  const token = readFileSync(TOKEN_PATH, 'utf8').trim();
  if (!/^[a-f0-9]{64}$/.test(token)) {
    throw new Error('token_invalido');
  }
  return token;
}

async function readLimited(response) {
  if (!response.body) throw new Error('resposta_vazia');
  const reader = response.body.getReader();
  const chunks = [];
  let size = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.byteLength;
      if (size > MAX_RESPONSE_BYTES) {
        await reader.cancel();
        throw new Error('resposta_grande');
      }
      chunks.push(value);
    }
  } finally {
    reader.releaseLock();
  }
  const buffer = Buffer.concat(chunks, size);
  return buffer.toString('utf8');
}

async function queryPabx(action) {
  // A URL e as acoes sao fixas no backend. O cliente MCP nao define host nem comando.
  const token = readBearer();
  const url = new URL(PABX_URL);
  url.searchParams.set('action', action);
  const response = await fetch(url, {
    method: 'GET',
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    redirect: 'error',
    cache: 'no-store',
    signal: AbortSignal.timeout(10000),
  });
  if (response.status !== 200 || !String(response.headers.get('content-type') || '').includes('application/json')) {
    throw new Error('pabx_http_indisponivel');
  }
  const data = JSON.parse(await readLimited(response));
  if (data?.ok !== true || data.action !== action) {
    throw new Error('resposta_invalida');
  }
  if (action === 'ixc_files') {
    if (!data.result || typeof data.result !== 'object' ||
        !data.result.componentes || typeof data.result.componentes !== 'object') {
      throw new Error('inventario_ixc_invalido');
    }
    return data.result;
  }
  if (!Array.isArray(data.result) || data.result.some(x => typeof x !== 'string')) {
    throw new Error('resposta_invalida');
  }
  return data.result;
}


async function queryIxcFileInfo(area, file) {
  if (!['webservice_ixcsoft', 'agi_ixcsoft', 'dialplan_ixcsoft'].includes(area) ||
      typeof file !== 'string' || !/^[A-Za-z0-9_.-]+(?:\\/[A-Za-z0-9_.-]+){0,2}$/.test(file) ||
      file.includes('..')) {
    throw new Error('invalid_file_id');
  }
  const url = new URL(PABX_URL);
  url.searchParams.set('action', 'ixc_file_info');
  url.searchParams.set('area', area);
  url.searchParams.set('file', file);
  const response = await fetch(url, {
    method: 'GET',
    headers: { Authorization: `Bearer ${readBearer()}`, Accept: 'application/json' },
    redirect: 'error',
    cache: 'no-store',
    signal: AbortSignal.timeout(10000),
  });
  if (response.status !== 200 || !String(response.headers.get('content-type') || '').includes('application/json')) {
    throw new Error('pabx_http_indisponivel');
  }
  const data = JSON.parse(await readLimited(response));
  if (data?.ok !== true || data.action !== 'ixc_file_info' ||
      data.result?.area !== area || data.result?.arquivo !== file ||
      !/^[a-f0-9]{64}$/.test(data.result?.sha256 || '')) {
    throw new Error('arquivo_info_invalida');
  }
  return data.result;
}

export function registerPabxTools(mcp) {
  if (!configured()) return false;
  for (const [name, action, description] of READ_TOOLS) {
    mcp.registerTool(name, { description }, async () => {
      try {
        const results = await queryPabx(action);
        return {
          content: [{ type: 'text', text: JSON.stringify({ origem: 'PABX JR Conect', acao: action, resultado: results }) }],
        };
      } catch {
        // Mensagem sem credenciais, IP interno, pilha de erro ou dados de rede.
        return {
          isError: true,
          content: [{ type: 'text', text: 'Falha ao consultar a API HTTPS do PABX. Confirme a configuracao e o acesso no servidor MCP.' }],
        };
      }
    });
  }
  mcp.registerTool('pabx_ixc_arquivo_info', {
    description: 'Consulta hash SHA256 e tamanho de arquivo IXCsoft autorizado no Issabel, sem expor conteudo ou segredos.',
    inputSchema: {
      area: z.enum(['webservice_ixcsoft', 'agi_ixcsoft', 'dialplan_ixcsoft']),
      arquivo: z.string().min(1).max(220),
    },
  }, async ({ area, arquivo }) => {
    try {
      const info = await queryIxcFileInfo(area, arquivo);
      return { content: [{ type: 'text', text: JSON.stringify(info) }] };
    } catch {
      return {
        isError: true,
        content: [{ type: 'text', text: 'Nao foi possivel consultar metadados do arquivo IXCsoft autorizado.' }],
      };
    }
  });
  return true;
}
