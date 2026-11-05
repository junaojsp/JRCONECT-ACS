import { readFileSync } from 'node:fs';

// Opa! Suite API; not the Open Policy Agent service.
export function createOpaApiClient({
  baseUrl = process.env.OPA_API_BASE_URL,
  tokenFile = '/run/secrets/opa-api-token',
} = {}) {
  if (!baseUrl) throw new Error('OPA_API_BASE_URL nao configurada');
  const base = new URL(baseUrl);
  if (base.protocol !== 'https:' || base.username || base.password || base.search || base.hash || base.pathname !== '/') {
    throw new Error('URL base da API Opa deve ser um dominio HTTPS sem caminho');
  }
  const paths = Object.freeze({
    channels: '/api/v1/canal-comunicacao/',
    attendance: '/api/v1/atendimento/',
  });
  async function get(kind, id = '') {
    if (!Object.hasOwn(paths, kind)) throw new Error('Rota Opa nao permitida');
    if (kind === 'attendance' && !/^[a-f\d]{24}$/i.test(id)) throw new Error('ID de atendimento invalido');
    const token = readFileSync(tokenFile, 'utf8').trim();
    if (!/^[^\s]{32,}$/.test(token)) throw new Error('Token da API Opa ausente ou invalido');
    const path = paths[kind] + (kind === 'attendance' ? id : '');
    const response = await fetch(new URL(path, base), {
      method: 'GET', redirect: 'manual', signal: AbortSignal.timeout(10000),
      headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
    });
    if (!response.ok) throw new Error('Opa API HTTP ' + response.status);
    if (!(response.headers.get('content-type') || '').includes('application/json')) {
      throw new Error('Opa API retornou conteudo nao JSON');
    }
    const payload = await response.json();
    // Endpoints can expose customer personal information; callers must be
    // authorized by the central Keycloak handler before registration.
    return payload;
  }
  return { get };
}
