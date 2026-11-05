#!/usr/bin/env python3
"""Stage a read-only SHIELD adapter in the live ACS MCP without changing production.

Two MCP tool catalogs on the same /mcp endpoint:
- Opa bearer: unchanged existing tools.
- OAuth JWT whose subject is explicitly allowlisted: existing tools plus 4 SHIELD GETs.
Other OAuth users retain the existing catalog.
"""
from pathlib import Path
import re
import subprocess
import sys

root = Path("/opt/jrconect-mcp")
src = root / "server.js"
compose = root / "docker-compose.yml"
out = root / ".shield-readonly-stage"
uuid = "b33b099a-c5c8-499b-bc0d-4cd9d4d9a315"

for name in ("shield-session-client.js", "shield-tools.js"):
    if not (root / "integrations" / name).is_file():
        sys.exit("STOP: missing integration " + name)
if not (root / "secrets" / "shield.env").is_file():
    sys.exit("STOP: host SHIELD credential file missing")

s = src.read_text()
c = compose.read_text()

# Check the live server; GitHub mcp/server.js is stale and must not be deployed.
anchors = [
    "const handler = createMcpHandler(() => {",
    "const mcpHandler = toNodeHandler(handler);",
    "    const payload = await validarToken(match[1]);",
    "    registerPabxTools(mcp);",
]
for a in anchors:
    if s.count(a) != 1:
        sys.exit("STOP: live server structure changed: " + repr(a))
if "shieldMcpHandler" in s or "createShieldClient" in s:
    sys.exit("STOP: SHIELD registration might already be active")

imports = """import { createShieldClient } from './integrations/shield-session-client.js';
import { registerShieldTools } from './integrations/shield-tools.js';
"""
s = imports + s
s = s.replace(
    "const handler = createMcpHandler(() => {",
    "const makeMcpHandler = (includeShield) => createMcpHandler(() => {",
    1
)
s = s.replace(
    "    registerPabxTools(mcp);",
    """    // Opa and non-allowlisted OAuth callers never receive SHIELD tools.
    if (includeShield) registerShieldTools(mcp, createShieldClient());
    registerPabxTools(mcp);""",
    1
)
s = s.replace(
    "const mcpHandler = toNodeHandler(handler);",
    """const handler = makeMcpHandler(false);
const shieldHandler = makeMcpHandler(true);
const mcpHandler = toNodeHandler(handler);
const shieldMcpHandler = toNodeHandler(shieldHandler);""",
    1
)
# Route only the Keycloak validated and UUID-allowlisted caller to SHIELD catalog.
# The shared Opa handler and the independent PABX/Financeiro endpoints remain unchanged.
needle = "    const payload = await validarToken(match[1]);"
s = s.replace(
    needle,
    needle + """
    if (process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub &&
        /^[a-f0-9-]{36}$/i.test(process.env.SHIELD_MCP_ALLOWED_SUB || '')) {
      req.mcpUser = { sub: payload.sub, client: payload.azp };
      return shieldMcpHandler(req, res);
    }""",
    1
)

# Secrets are mounted as files. No secrets in image or Git.
vol = "      - ./wiki-token.secret:/run/secrets/wiki-token:ro"
if c.count(vol) != 1:
    sys.exit("STOP: expected compose volume not present")
if "/run/secrets/shield-env" in c or "SHIELD_MCP_ALLOWED_SUB" in c:
    sys.exit("STOP: SHIELD integration already configured")
c = c.replace(vol, vol + "\n      - ./secrets/shield.env:/run/secrets/shield-env:ro", 1)
env = "    environment:\n"
if c.count(env) != 1:
    sys.exit("STOP: compose environment differs")
c = c.replace(env, env + '      SHIELD_MCP_ALLOWED_SUB: "' + uuid + '"\n', 1)

# Refuse to build with the original root-owned secret in Docker context.
ignore = (root / ".dockerignore").read_text().splitlines()
if "secrets/" not in ignore:
    sys.exit("STOP: .dockerignore must exclude secrets/")

out.mkdir(mode=0o700, exist_ok=True)
(out / "server.js").write_text(s)
(out / "docker-compose.yml").write_text(c)
subprocess.run(["node", "--check", str(out / "server.js")], check=True)
subprocess.run(["node", "--check", str(root / "integrations" / "shield-session-client.js")], check=True)
subprocess.run(["node", "--check", str(root / "integrations" / "shield-tools.js")], check=True)
print("STAGED_OK: SHIELD READ ONLY +4 tools in /mcp for allowlisted OAuth subject.")
print("Opa bearer and other MCP routes unchanged. No deployment or restart.")
print("Staged directory: " + str(out))
