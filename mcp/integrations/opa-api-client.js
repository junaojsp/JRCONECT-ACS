import { readFileSync } from 'node:fs';
import { request } from 'node:https';

// This endpoint requires a JSON body in a GET request. Node fetch() cannot
// send GET bodies, so use node:https with the exact validated request format.
export function createOpaApiClient({
  baseUrl = process.env.OPA_API_BASE_URL,
  tokenFile = '/run/secrets/opa-api-token',
} = {}) {
  if (!baseUrl) throw new Error('OPA_API_BASE_URL nao configurada');
  const base = new URL(baseUrl);
  if (base.protocol !== 'https:' || base.username || base.password ||
      base.search || base.hash || base.pathname !== '/') {
    throw new Error('URL base Opa invalida');
  }

  function get(kind, id = '') {
    // Only the channels route has been tested on the actual JR Conect instance.
    // Keep other routes disabled until their exact request contracts are checked.
    if (kind !== 'channels' || id) throw new Error('Consulta Opa ainda nao validada');
    const token = readFileSync(tokenFile, 'utf8').trim();
    if (!token || /\s/.test(token)) throw new Error('Token Opa ausente ou invalido');
    const body = JSON.stringify({filter:{status:'A'}, options:{limit:1,skip:0}});
    const url = new URL('/api/v1/canal-comunicacao/', base);
    return new Promise((resolve, reject) => {
      const req = request(url, {
        method: 'GET',
        timeout: 10000,
        headers: {
          Authorization: 'Bearer ' + token,
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'Content-Length': Buffer.byteLength(body),
        },
      }, response => {
        const status = response.statusCode || 0;
        let raw = '';
        response.setEncoding('utf8');
        response.on('data', chunk => {
          raw += chunk;
          if (raw.length > 1024 * 1024) req.destroy(new Error('Resposta Opa muito grande'));
        });
        response.on('end', () => {
          if (status !== 200) return reject(new Error('Opa API HTTP ' + status));
          if (!String(response.headers['content-type'] || '').includes('application/json')) {
            return reject(new Error('Resposta Opa nao JSON'));
          }
          try {
            const parsed = JSON.parse(raw);
            if (parsed.status !== 'success') return reject(new Error('Opa API status nao success'));
            resolve(parsed);
          } catch (e) { reject(e); }
        });
        response.on('error', reject);
      });
      req.on('timeout', () => req.destroy(new Error('Timeout Opa')));
      req.on('error', reject);
      req.end(body);
    });
  }
  return { get };
}
