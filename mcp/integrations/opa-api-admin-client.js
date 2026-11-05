import { readFileSync } from 'node:fs';

// Administrative Opa API operations validated against the official API reference.
// This module is NOT an unrestricted HTTP proxy.
const actions = Object.freeze({
  'observation.add': { method: 'POST', suffix: 'observacao' },
  'tag.add': { method: 'POST', suffix: 'etiqueta' },
  'reason.add': { method: 'POST', suffix: 'motivo' },
  'message.text': { method: 'POST', absolute: '/api/v1/atendimento/mensagem/send' },
  'tag.remove': { method: 'DELETE', suffix: 'etiqueta' },
});
const ID = /^[a-f0-9]{24}$/i;
function requireId(value) {
  if (typeof value !== 'string' || !ID.test(value)) throw new Error('ID Opa invalido');
  return value;
}
export function createOpaApiAdminClient({
  baseUrl = process.env.OPA_API_BASE_URL,
  tokenFile = '/run/secrets/opa-api-token',
} = {}) {
  if (!baseUrl) throw new Error('OPA_API_BASE_URL ausente');
  const base = new URL(baseUrl);
  if (base.protocol !== 'https:' || base.pathname !== '/' ||
      base.username || base.password || base.search || base.hash) {
    throw new Error('URL da API Opa invalida');
  }
  async function execute(action, attendanceId, value, additionalId) {
    const route = actions[action];
    if (!route) throw new Error('Operacao Opa nao permitida');
    const id = requireId(attendanceId);
    let path, body;
    switch (action) {
      case 'observation.add':
        if (typeof value !== 'string' || !value.trim() || value.length > 2000) throw new Error('Observacao invalida');
        path = '/api/v1/atendimento/' + id + '/observacao';
        body = { message: value.trim() };
        break;
      case 'tag.add':
      case 'reason.add':
        path = '/api/v1/atendimento/' + id + '/' + route.suffix;
        body = { [action === 'tag.add' ? 'tagId' : 'reasonId']: requireId(value) };
        break;
      case 'message.text':
        if (typeof value !== 'string' || !value.trim() || value.length > 2000) throw new Error('Mensagem invalida');
        path = route.absolute;
        body = { customerServiceId: id, content: { type: 'text', text: value.trim() } };
        break;
      case 'tag.remove':
        path = '/api/v1/atendimento/' + id + '/etiqueta/' + requireId(additionalId);
        break;
    }
    const token = readFileSync(tokenFile, 'utf8').trim();
    if (!token || token.length < 32 || /\s/.test(token)) throw new Error('Token API Opa invalido');
    const response = await fetch(new URL(path, base), {
      method: route.method, redirect: 'manual', signal: AbortSignal.timeout(10000),
      headers: {
        Authorization: 'Bearer ' + token,
        Accept: 'application/json',
        ...(body ? { 'Content-Type': 'application/json' } : {}),
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    if (!response.ok) throw new Error('Opa API HTTP ' + response.status);
    // DELETE may have an empty body. Never return raw HTML to a caller.
    const raw = await response.text();
    if (!raw.trim()) return { success: true, httpStatus: response.status };
    if (!(response.headers.get('content-type') || '').includes('application/json'))
      throw new Error('Opa API resposta nao JSON');
    return JSON.parse(raw);
  }
  return { execute };
}
