import { readFileSync } from 'node:fs';
export function createFinanceAdminClient({baseUrl='https://financeiro.jrconect.com', tokenFile='/run/secrets/finance-token'}={}) {
  const base = new URL(baseUrl);
  if (base.protocol !== 'https:' || base.username || base.password || base.search || base.hash) throw new Error('Invalid finance URL');
  return {async execute(action, id, payload) {
    const token = readFileSync(tokenFile,'utf8').trim();
    if (token.length < 32) throw new Error('Finance token missing');
    const response = await fetch(new URL('/internal/mcp/finance/admin/execute',base), {
      method:'POST', redirect:'manual', signal:AbortSignal.timeout(10000),
      headers:{Authorization:'Bearer '+token, 'Content-Type':'application/json',Accept:'application/json'},
      body:JSON.stringify({action,id,payload,confirm:'CONFIRMAR_OPERACAO_FINANCEIRA'})
    });
    if (!response.ok) throw new Error('Finance admin API HTTP '+response.status);
    if (!(response.headers.get('content-type')||'').includes('application/json')) throw new Error('Finance admin API returned non-JSON');
    return response.json();
  }};
}
