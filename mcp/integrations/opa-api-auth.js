// Requires the existing validarToken() JWT verification BEFORE this predicate.
// NEVER expose customer data through shared Opa token authentication.
export function opaApiAuthorized(payload, env = process.env) {
  if (env.OPA_API_MCP_ENABLED !== 'true') return false;
  if (!payload || payload.azp !== 'chatgpt-mcp') return false;
  if (typeof payload.sub !== 'string') return false;
  const subs = (env.OPA_API_ALLOWED_SUB || '').split(',').map(x => x.trim()).filter(Boolean);
  const roles = Array.isArray(payload.realm_access?.roles) ? payload.realm_access.roles : [];
  return subs.includes(payload.sub) && roles.includes('opa-mcp-admin');
}
