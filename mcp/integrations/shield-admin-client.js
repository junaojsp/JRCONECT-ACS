import { readFileSync } from 'node:fs';

const ORIGIN = 'https://jrcshield.jrconect.com';
const allowed = Object.freeze({
  policies: { method: 'PUT', path: '/api/jrcshield/policies' },
  mitigationCreate: { method: 'POST', path: '/api/jrcshield/mitigations' },
  mitigationWithdraw: { method: 'POST', path: '/api/jrcshield/mitigations/withdraw' },
});

export function createShieldAdminClient({ baseUrl = ORIGIN, secretFile = '/run/secrets/shield-env' } = {}) {
  const base = new URL(baseUrl);
  if (base.protocol !== 'https:' || base.username || base.password || base.search || base.hash) throw new Error('Invalid SHIELD URL');
  let session = '';
  let sessionTime = 0;
  let pendingLogin;
  const request = (path, init) => fetch(new URL(path, base), {
    redirect: 'manual', signal: AbortSignal.timeout(10000), ...init,
  });
  async function login() {
    if (session && Date.now() - sessionTime < 600000) return;
    if (!pendingLogin) pendingLogin = (async () => {
      const values = {};
      for (const line of readFileSync(secretFile, 'utf8').split(/\r?\n/)) {
        const match = /^\s*(SHIELD_USERNAME|SHIELD_PASSWORD)=(.*)$/.exec(line);
        if (match) values[match[1]] = match[2].replace(/^["']|["']$/g, '');
      }
      if (!values.SHIELD_USERNAME || !values.SHIELD_PASSWORD) throw new Error('SHIELD credentials missing');
      const response = await request('/auth/login', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ username: values.SHIELD_USERNAME, password: values.SHIELD_PASSWORD }),
      });
      const cookies = response.headers.getSetCookie?.() || [];
      const found = cookies.map(v => /^jrcshield_session=([^;]+)/.exec(v)).find(Boolean);
      if (!found || ![302, 303].includes(response.status)) throw new Error('SHIELD login HTTP ' + response.status);
      session = 'jrcshield_session=' + found[1]; sessionTime = Date.now();
    })().finally(() => { pendingLogin = undefined; });
    return pendingLogin;
  }
  async function execute(operation, payload) {
    const route = allowed[operation];
    if (!route) throw new Error('SHIELD operation not permitted');
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) throw new Error('JSON object required');
    const body = JSON.stringify(payload);
    if (Buffer.byteLength(body) > (operation === 'policies' ? 262144 : 4096)) throw new Error('SHIELD payload too large');
    for (let attempt = 0; attempt < 2; attempt++) {
      await login();
      const response = await request(route.path, {
        method: route.method,
        headers: { Cookie: session, Origin: base.origin, 'Content-Type': 'application/json', Accept: 'application/json' },
        body,
      });
      if (response.status === 401 && attempt === 0) { session = ''; continue; }
      if (!response.ok) throw new Error('SHIELD admin API HTTP ' + response.status);
      if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('SHIELD returned non-JSON');
      return response.json();
    }
    throw new Error('SHIELD authorization failed');
  }
  return { execute };
}
