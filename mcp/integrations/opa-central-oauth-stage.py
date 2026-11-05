#!/usr/bin/env python3
"""Create staged Opa integration for the CURRENT production MCP server.js.

Does not modify running files, compose or Keycloak, and never writes a token.
Requires separately managed Keycloak opa-mcp-admin role and OPA_API_ALLOWED_SUB.
"""
from pathlib import Path
import re
import subprocess
import sys

root = Path("/opt/jrconect-mcp")
source = root / "server.js"
out = Path("/tmp/jrc-opa-central-stage")
if not source.exists():
    sys.exit("STOP: server.js ativo nao encontrado")
s = source.read_text()
if "JRC_OPA_CENTRAL_OAUTH_V1" in s:
    sys.exit("STOP: integracao Opa ja presente")
for path in (
    "integrations/opa-api-client.js",
    "integrations/opa-api-tools.js",
    "integrations/opa-api-admin-client.js",
    "integrations/opa-api-admin-tools.js",
    "integrations/opa-api-auth.js",
):
    if not (root/path).exists():
        sys.exit("STOP: arquivo Opa ausente: " + path)
imports = """// JRC_OPA_CENTRAL_OAUTH_V1: protected OAuth-only Opa integrations
import { opaApiAuthorized } from './integrations/opa-api-auth.js';
import { createOpaApiClient } from './integrations/opa-api-client.js';
import { registerOpaApiTools } from './integrations/opa-api-tools.js';
import { createOpaApiAdminClient } from './integrations/opa-api-admin-client.js';
import { registerOpaApiAdminTools } from './integrations/opa-api-admin-tools.js';
"""
# Insert before the first import or following comments, preserving original source.
first_import = re.search(r"^import .+;$", s, flags=re.M)
if not first_import:
    sys.exit("STOP: imports do servidor nao identificados")
s = s[:first_import.start()] + imports + s[first_import.start():]

header = "const makeMcpHandler = (includeShield, includeFinance = false) => createMcpHandler(() => {"
if s.count(header) != 1:
    sys.exit("STOP: signature live do makeMcpHandler difere; sem patch")
s = s.replace(
    header,
    "const makeMcpHandler = (includeShield, includeFinance = false, includeOpa = false) => createMcpHandler(() => {",
    1,
)

# Insert independent Opa tools adjacent to finance registration; never in the
# common handler, because includeOpa is always false there.
finance_register = "if (includeFinance) registerFinanceSuite(mcp);"
if s.count(finance_register) != 1:
    sys.exit("STOP: ponto de registro Financeiro nao localizado; sem patch")
opa_register = """if (includeOpa) {
      registerOpaApiTools(mcp, createOpaApiClient());
      // Writes are strictly disabled by default. Do not enable until API
      // operations are validated and every action has human approval.
      if (process.env.OPA_API_ADMIN_ENABLED === 'true') {
        registerOpaApiAdminTools(mcp, createOpaApiAdminClient());
      }
    }"""
s = s.replace(finance_register, finance_register + "\n    " + opa_register, 1)

finance_handler = "const financeCentralMcpHandler = toNodeHandler(makeMcpHandler(true, true));"
if s.count(finance_handler) != 1:
    sys.exit("STOP: handler Financeiro nao localizado; sem patch")
s = s.replace(finance_handler, finance_handler + """
// Dedicated OAuth handlers: Opa bearer MUST never see these tools.
const opaCentralMcpHandler = toNodeHandler(makeMcpHandler(true, false, true));
const opaFinanceCentralMcpHandler = toNodeHandler(makeMcpHandler(true, true, true));
""", 1)

finance_route = "if (financeMcpAuthorized(payload)) {"
if s.count(finance_route) != 1:
    sys.exit("STOP: rota OAuth do Financeiro nao localizada; sem patch")
s = s.replace(finance_route, """if (opaApiAuthorized(payload)) {
        return (financeMcpAuthorized(payload)
          ? opaFinanceCentralMcpHandler : opaCentralMcpHandler)(req, res);
      }
      """ + finance_route, 1)

out.mkdir(mode=0o700, parents=True, exist_ok=True)
staged = out / "server.js"
staged.write_text(s)
subprocess.run(["node", "--check", str(staged)], check=True)
print("OPA STAGED_OK: server.js preparado, sintaxe valida")
print("Servico em producao NAO foi alterado")
print("Arquivo:", staged)

# Prepare a merged compose overlay with no secrets exposed.
overlay = root / "docker-compose.opa.yml"
if not overlay.is_file():
    sys.exit("STOP: overlay do Opa ausente")
if "OPA_API_MCP_ENABLED" not in overlay.read_text():
    sys.exit("STOP: overlay do Opa inesperado")
print("Compose do Opa encontrado: " + str(overlay))
print("ATENCAO: configuracao ativa ainda depende do Keycloak e do compose no deploy.")
