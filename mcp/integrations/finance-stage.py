#!/usr/bin/env python3
"""Generate staged ACS Financeiro MCP files without touching active services."""
from pathlib import Path
import subprocess
import sys

root = Path("/opt/jrconect-mcp")
out = root / ".finance-mcp-stage"
s = (root / "server.js").read_text()
c = (root / "docker-compose.yml").read_text()

for name in ("finance-auth.js", "finance-suite.js", "finance-client.js", "finance-tools.js", "finance-admin-client.js", "finance-admin-tools.js"):
    if not (root / "integrations" / name).is_file():
        sys.exit("STOP missing " + name)

for anchor in ("const mcpHandler = toNodeHandler(handler);", "createServer(async (req, res) => {"):
    if s.count(anchor) != 1:
        sys.exit("STOP unexpected server anchor: " + anchor)
if "financeTransportHandler" in s or "financeMcpAuthorized" in s:
    sys.exit("STOP finance endpoint may already exist")

imports = """import { financeMcpAuthorized } from './integrations/finance-auth.js';
import { registerFinanceSuite } from './integrations/finance-suite.js';
"""
factory = """
const financeTransportHandler = toNodeHandler(createMcpHandler(() => {
  const financeMcp = new McpServer({ name: 'jrconect-finance-mcp', version: '1.0.0' });
  registerFinanceSuite(financeMcp, {
    read: { tokenFile: '/run/secrets/finance-token' },
    admin: { tokenFile: '/run/secrets/finance-admin-token' },
  });
  return financeMcp;
}));
"""
handler = """
  if (req.url?.split('?')[0] === '/mcp-finance') {
    const bearer = /^Bearer ([^\\s]+)$/i.exec(req.headers.authorization || '');
    if (!bearer) {
      res.writeHead(401, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'unauthorized' }));
      return;
    }
    try {
      const p = await validarToken(bearer[1]);
      if (!financeMcpAuthorized(p)) {
        res.writeHead(403, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'access_denied' }));
        return;
      }
      req.mcpUser = { sub: p.sub, client: p.azp };
      req.url = req.url.replace(/^\\/mcp-finance(?=\\?|$)/, '/mcp');
      return financeTransportHandler(req, res);
    } catch {
      res.writeHead(401, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'invalid_token' }));
      return;
    }
  }
"""
s = imports + s
s = s.replace("const mcpHandler = toNodeHandler(handler);", "const mcpHandler = toNodeHandler(handler);\n" + factory, 1)
s = s.replace("createServer(async (req, res) => {", "createServer(async (req, res) => {\n" + handler, 1)

anchor = "      - ./wiki-token.secret:/run/secrets/wiki-token:ro"
if c.count(anchor) != 1 or "finance-admin-token" in c:
    sys.exit("STOP unexpected production compose mounts")
c = c.replace(anchor, anchor +
    "\n      - ./secrets/finance/read-token:/run/secrets/finance-token:ro" +
    "\n      - ./secrets/finance/admin-token:/run/secrets/finance-admin-token:ro", 1)

env = "    environment:\n"
if c.count(env) != 1:
    sys.exit("STOP unexpected production compose environment")
c = c.replace(env, env +
    '      FINANCE_MCP_ENABLED: "false"\n' +
    '      FINANCE_MCP_ALLOWED_SUB: ""\n', 1)

out.mkdir(mode=0o700, exist_ok=True)
(out / "server.js").write_text(s)
(out / "docker-compose.yml").write_text(c)
subprocess.run(["node", "--check", str(out / "server.js")], check=True)
print("STAGED_OK: syntax passed; no active files changed.")
print("Staged in " + str(out))
print("Finance endpoint stays DISABLED until explicit approval of Keycloak role and UUID.")
