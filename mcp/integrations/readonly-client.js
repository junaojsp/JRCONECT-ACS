import { readFileSync } from 'node:fs';

export function createReadOnlyClient({ baseUrl, tokenFile, allowedPaths, timeoutMs = 8000 }) {
  if (!baseUrl || !tokenFile) throw new Error('Integration not configured');
  const origin = new URL(baseUrl);
  if (origin.protocol !== 'https:') throw new Error('HTTPS required');
  const basePath = origin.pathname.replace(/\/$/, '');
  const allow = new Set(allowedPaths);
  return async function get(path) {
    if (!allow.has(path)) throw new Error('Endpoint not allowed');
    const token = readFileSync(tokenFile, 'utf8').trim();
    if (!token) throw new Error('Integration token missing');
    const url = new URL(origin.toString());
    url.pathname = basePath + path;
    url.search = '';
    const response = await fetch(url, {
      method: 'GET',
      redirect: 'error',
      signal: AbortSignal.timeout(timeoutMs),
      headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
    });
    if (!response.ok) throw new Error('Upstream HTTP ' + response.status);
    const type = response.headers.get('content-type') || '';
    if (!type.includes('application/json')) throw new Error('Unexpected upstream content type');
    return response.json();
  };
}
