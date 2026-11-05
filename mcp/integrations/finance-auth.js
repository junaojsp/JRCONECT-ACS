/**
 * Authorization policy for the isolated Financeiro MCP endpoint.
 * JWT verification must be performed by the existing Keycloak validarToken().
 * Does not grant access to the Opa bearer token nor reuse PABX authorization.
 */
export function financeMcpAuthorized(payload, env = process.env) {
  if (env.FINANCE_MCP_ENABLED !== 'true') return false;
  if (!payload || payload.azp !== 'chatgpt-mcp') return false;
  if (typeof payload.sub !== 'string' || !/^[a-f0-9-]{36}$/i.test(payload.sub)) return false;
  const allowed = (env.FINANCE_MCP_ALLOWED_SUB || '').split(',').map(v => v.trim()).filter(Boolean);
  if (allowed.length === 0 || !allowed.includes(payload.sub)) return false;
  const roles = Array.isArray(payload.realm_access?.roles) ? payload.realm_access.roles : [];
  if (!roles.includes('finance-mcp-admin')) return false;
  return true;
}
