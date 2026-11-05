#!/usr/bin/env python3
"""Prepare SHIELD admin tools from the currently deployed central MCP code.
Does NOT modify live files, credentials, containers, or services.
"""
from pathlib import Path
import subprocess
import sys

root = Path("/opt/jrconect-mcp")
source = root / "server.js"
output = root / ".shield-admin-stage"
need = [
    root / "integrations/shield-admin-client.js",
    root / "integrations/shield-admin-tools.js",
    root / "secrets/shield-mcp.env",
    root / "docker-compose.yml",
]
for file in need:
    if not file.is_file():
        sys.exit("STOP: prerequisite missing: " + str(file))

s = source.read_text()
imports = """import { createShieldAdminClient } from './integrations/shield-admin-client.js';
import { registerShieldAdminTools } from './integrations/shield-admin-tools.js';
"""
anchor = "if (includeShield) registerShieldTools(mcp, createShieldClient());"
checks = [
    anchor,
    "const shieldMcpHandler = toNodeHandler(shieldHandler);",
    "process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub",
    "return shieldMcpHandler(req, res);",
]
for item in checks:
    if s.count(item) != 1:
        sys.exit("STOP: unexpected live SHIELD gateway structure: " + repr(item))
if "registerShieldAdminTools" in s or "createShieldAdminClient" in s:
    sys.exit("STOP: admin SHIELD registration already present")

# Attach admin tools ONLY inside includeShield=true branch. Never in the
# shared Opa handler. The existing JWT sub allowlist remains untouched.
s = imports + s
s = s.replace(anchor, """if (includeShield) {
      registerShieldTools(mcp, createShieldClient());
      registerShieldAdminTools(mcp, createShieldAdminClient());
    }""", 1)

output.mkdir(mode=0o700, exist_ok=True)
candidate = output / "server.js"
candidate.write_text(s)
subprocess.run(["node", "--check", str(candidate)], check=True)
for path in need[:2]:
    subprocess.run(["node", "--check", str(path)], check=True)

print("STAGED_OK: SHIELD +4 leitura e +3 operacoes administrativas.")
print("Acesso administrativo somente pelo handler OAuth ja limitado ao UUID autorizado.")
print("Nenhuma alteracao de container, senhas ou arquivos ativos.")
print("Revisar diff e testar tools/list com JWT antes de deploy.")
print("Candidato:", candidate)
