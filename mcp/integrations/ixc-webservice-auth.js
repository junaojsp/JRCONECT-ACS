// Must be called only after validarToken() has verified the Keycloak JWT.
export function ixcWebserviceAuthorized(payload, env = process.env) {
  if (env.IXC_WS_MCP_ENABLED !== 'true') return false;
  if (!payload || payload.azp !== 'chatgpt-mcp') return false;
  const subs = String(env.IXC_WS_ALLOWED_SUB || '').split(',').map(x => x.trim()).filter(Boolean);
  if (!subs.includes(payload.sub)) return false;
  const roles = Array.isArray(payload.realm_access?.roles) ? payload.realm_access.roles : [];
  return roles.includes('ixc-mcp-admin');
}
