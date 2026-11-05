import { readFileSync } from 'node:fs';

const routes = Object.freeze({ summary: '/internal/mcp/finance/summary', accounts: '/internal/mcp/finance/accounts', suppliers: '/internal/mcp/finance/suppliers', categories: '/internal/mcp/finance/categories' });

export function createFinanceClient({ baseUrl = 'https://financeiro.jrconect.com', tokenFile = '/run/secrets/finance-token' } = {}) {
  const base = new URL(baseUrl);
  if (base.protocol !== 'https:' || base.username || base.password || base.search || base.hash) throw new Error('Financeiro requires HTTPS');
  return {
    async get(name, filters = {}) {
      if (!Object.hasOwn(routes, name)) throw new Error('Finance endpoint not allowed');
      const url = new URL(routes[name], base);
      if (name === 'accounts') {
        for (const key of ['status', 'due_from', 'due_to', 'limit']) {
          if (filters[key] !== undefined && filters[key] !== null && String(filters[key]) !== '') url.searchParams.set(key, String(filters[key]));
        }
      }
      const token = readFileSync(tokenFile, 'utf8').trim();
      if (token.length < 32) throw new Error('Finance MCP token missing');
      const response = await fetch(url, { method: 'GET', redirect: 'manual', signal: AbortSignal.timeout(10000), headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' } });
      if (!response.ok) throw new Error('Finance API HTTP ' + response.status);
      if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Finance API returned non-JSON');
      return response.json();
    },
  };
}
