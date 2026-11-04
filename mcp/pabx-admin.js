// PABX admin - endpoint MCP isolado do MCP compartilhado com o Opa.
// Sem token administrativo no catalogo /mcp; somente /mcp-admin apos JWT e lista fechada de usuario.
import { existsSync, readFileSync } from 'node:fs';
import { z } from 'zod';

const URL_PABX = 'https://pabx.jrconect.com/api/mcp/index.php';
const ADMIN_TOKEN_FILE = '/run/secrets/pabx-admin-token';
const AREA = z.enum(['webservice_ixcsoft', 'agi_ixcsoft', 'dialplan_ixcsoft']);
const SHA256 = z.string().regex(/^[a-f0-9]{64}$/);
const MAX_RESPONSE_BYTES = 180 * 1024;

export function pabxAdminAuthorized(payload) {
  const allowed = (process.env.PABX_ADMIN_ALLOWED_SUB || '')
    .split(',').map(s => s.trim()).filter(Boolean);
  const role = process.env.PABX_ADMIN_ROLE || 'jrconect-pabx-admin';
  const roles = Array.isArray(payload?.realm_access?.roles) ? payload.realm_access.roles : [];
  // Alternativa deliberada, estritamente para 1 sub Keycloak: sem console admin.
  // Continua exigindo validarToken() (issuer, audience, assinatura, azp).
  // O modo padrao permanece com dupla verificacao de role e sub.
  const subjectOnly = process.env.PABX_ADMIN_AUTH_MODE === 'single-subject';
  const uniqueSubject = subjectOnly && allowed.length === 1 &&
    /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(allowed[0]);
  return process.env.PABX_ADMIN_MCP_ENABLED === 'true' &&
    payload?.azp === 'chatgpt-mcp' &&
    typeof payload?.sub === 'string' && allowed.includes(payload.sub) &&
    (subjectOnly ? uniqueSubject : roles.includes(role));
}

function readAdminToken() {
  const t = readFileSync(ADMIN_TOKEN_FILE, 'utf8').trim();
  if (!/^[a-f0-9]{64}$/.test(t)) throw new Error('admin_secret_invalid');
  return t;
}

async function readLimited(response) {
  if (!response.body) throw new Error('no_body');
  const reader = response.body.getReader();
  const chunks = [];
  let size = 0;
  try {
    while (true) {
      const item = await reader.read();
      if (item.done) break;
      size += item.value.byteLength;
      if (size > MAX_RESPONSE_BYTES) {
        await reader.cancel();
        throw new Error('response_too_large');
      }
      chunks.push(item.value);
    }
  } finally {
    reader.releaseLock();
  }
  return Buffer.concat(chunks, size).toString('utf8');
}

async function callAdmin(action, params, body = null) {
  const url = new URL(URL_PABX);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) {
    url.searchParams.set(k, String(v));
  }
  const headers = {
    Authorization: 'Bearer ' + readAdminToken(),
    Accept: 'application/json',
  };
  if (body !== null) {
    headers['Content-Type'] = 'application/json';
    headers['X-JRC-Confirm'] = 'stage_only';
  }
  const response = await fetch(url, {
    method: body === null ? 'GET' : 'POST',
    headers,
    body: body === null ? undefined : JSON.stringify(body),
    cache: 'no-store',
    redirect: 'error',
    signal: AbortSignal.timeout(12000),
  });
  if (response.status !== 200 ||
      !String(response.headers.get('content-type') || '').includes('application/json')) {
    throw new Error('admin_api_unavailable');
  }
  const json = JSON.parse(await readLimited(response));
  if (json?.ok !== true || json.action !== action || !json.result ||
      typeof json.result !== 'object') {
    throw new Error('admin_api_invalid');
  }
  return json.result;
}

function adminError() {
  return {
    isError: true,
    content: [{
      type: 'text',
      text: 'Operacao administrativa indisponivel. Verifique a API, o escopo administrativo e os registros locais sem divulgar tokens.',
    }],
  };
}

export function registerPabxAdminTools(mcp) {
  if (process.env.PABX_ADMIN_MCP_ENABLED !== 'true' || !existsSync(ADMIN_TOKEN_FILE)) {
    return false;
  }
  mcp.registerTool('pabx_ixc_arquivo_previa', {
    description: 'Mostra apenas a versao mascarada do codigo de um script IXCsoft, com hashes de linha. Nao mostra literais ou comentarios e nao altera arquivos.',
    inputSchema: {
      area: AREA,
      arquivo: z.string().min(1).max(220),
      inicio: z.number().int().min(1).max(20000).optional(),
      quantidade: z.number().int().min(1).max(80).optional(),
    },
  }, async ({ area, arquivo, inicio = 1, quantidade = 60 }) => {
    try {
      const result = await callAdmin('ixc_file_preview', {
        area, file: arquivo, start: inicio, count: quantidade,
      });
      if (result.area !== area || result.arquivo !== arquivo || result.conteudo_redigido !== true ||
          !Array.isArray(result.linhas)) {
        throw new Error('preview_invalid');
      }
      return { content: [{ type: 'text', text: JSON.stringify(result) }] };
    } catch {
      return adminError();
    }
  });

  mcp.registerTool('pabx_ixc_edicao_preparar', {
    description: 'Prepara proposta de substituir linhas de um script IXCsoft. Valida hashes e sintaxe e retorna previa. NAO grava na URA: aplicacao exige operador root no console.',
    inputSchema: {
      area: AREA,
      arquivo: z.string().min(1).max(220),
      sha256_original: SHA256,
      alteracoes: z.array(z.object({
        linha: z.number().int().min(1),
        sha256_linha: SHA256,
        novo_texto: z.string().max(400),
      })).min(1).max(20),
      confirmar_previa: z.literal(true),
    },
  }, async ({ area, arquivo, sha256_original, alteracoes }) => {
    try {
      const result = await callAdmin('ixc_file_stage', {}, {
        area, arquivo, sha256_original, alteracoes,
      });
      if (result.aplicado !== false ||
          !/^[a-f0-9]{32}$/.test(result.review_id || '') ||
          !/^[a-f0-9]{64}$/.test(result.review_sha256 || '')) {
        throw new Error('stage_invalid');
      }
      return {
        content: [{
          type: 'text',
          text: JSON.stringify(result),
        }],
      };
    } catch {
      return adminError();
    }
  });

  return true;
}
