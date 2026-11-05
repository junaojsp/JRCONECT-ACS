import { readFileSync } from 'node:fs';

export function createShieldClient(options = {}) {
  const base = new URL(options.baseUrl || 'https://jrcshield.jrconect.com');
  if (base.protocol !== 'https:') throw new Error('SHIELD requires HTTPS');
  const secretFile = options.secretFile || '/run/secrets/shield-env';
  const paths = Object.freeze({
    flowStatus: '/api/jrcshield/flow-status',
    historySummary: '/api/jrcshield/history/summary',
    policies: '/api/jrcshield/policies',
    mitigations: '/api/jrcshield/mitigations',
  });
  let cookie = '';
  let lastLogin = 0;
  function readCredentials() {
    const raw = readFileSync(secretFile, 'utf8');
    const values = {};
    for (const line of raw.split(/\r?\n/)) {
      const match = /^\s*(SHIELD_USERNAME|SHIELD_PASSWORD)=(.*)$/.exec(line);
      if (match) values[match[1]] = match[2].replace(/^["']|["']$/g, '');
    }
    if (!values.SHIELD_USERNAME || !values.SHIELD_PASSWORD) throw new Error('Missing SHIELD credentials');
    return values;
  }
  async function login() {
    const credentials = readCredentials();
    const response = await fetch(new URL('/auth/login', base), {
      method: 'POST',
      redirect: 'manual',
      signal: AbortSignal.timeout(8000),
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ username: credentials.SHIELD_USERNAME, password: credentials.SHIELD_PASSWORD }),
    });
    const setCookies = response.headers.getSetCookie?.() || [];
    const found = setCookies.map(x => /^jrcshield_session=([^;]+)/.exec(x)).find(Boolean);
    if (!found || ![200, 302, 303].includes(response.status)) throw new Error('SHIELD login failed: HTTP ' + response.status);
    cookie = 'jrcshield_session=' + found[1];
    lastLogin = Date.now();
  }
  async function get(key) {
    const path = paths[key];
    if (!path) throw new Error('SHIELD route not allowed');
    if (!cookie || Date.now() - lastLogin > 600000) await login();
    for (let attempt = 0; attempt < 2; attempt++) {
      const response = await fetch(new URL(path, base), {
        method: 'GET',
        redirect: 'manual',
        signal: AbortSignal.timeout(8000),
        headers: { Cookie: cookie, Accept: 'application/json' },
      });
      if (response.status === 401 && attempt === 0) { await login(); continue; }
      if (!response.ok) throw new Error('SHIELD API HTTP ' + response.status);
      if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('SHIELD API returned non-JSON');
      return response.json();
    }
    throw new Error('SHIELD authorization failed');
  }
  return { get };
}
