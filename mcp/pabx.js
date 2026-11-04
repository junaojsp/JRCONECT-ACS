// JR Conect PABX - Bridge REST HTTPS de somente leitura.
import { existsSync, readFileSync } from 'node:fs';

const PABX_URL = 'https://pabx.jrconect.com/api/mcp/index.php';
const TOKEN_PATH = '/run/secrets/pabx-read-token';
const MAX_RESPONSE_BYTES = 256 * 1024;

const READ_TOOLS = Object.freeze([
  ['pabx_status', 'status', 'Consulta a versao, uptime e canais ativos do PABX Issabel 5.'],
  ['pabx_filas', 'queues', 'Consulta o estado das filas de atendimento do Asterisk.'],
  ['pabx_ramais', 'extensions', 'Consulta os endpoints PJSIP no PABX.'],
  ['pabx_troncos', 'trunks', 'Consulta os registros de troncos PJSIP.'],
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
  if (data?.ok !== true || data.action !== action ||
      !Array.isArray(data.result) || data.result.some(x => typeof x !== 'string')) {
    throw new Error('resposta_invalida');
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
  return true;
}
