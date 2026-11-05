#!/usr/bin/env python3
"""Stage IXC Webservice tools into the LIVE MCP server without changing production.

Must run only after the Opa central OAuth installation. Never overwrites
the running server.js, Docker Compose, secrets or Keycloak configuration.
"""
from pathlib import Path
import re
import subprocess
import sys

root = Path("/opt/jrconect-mcp")
sfile = root / "server.js"
stage = Path("/tmp/jrc-ixc-central-stage")
if not sfile.exists():
    sys.exit("STOP: server.js ativo nao encontrado")
s = sfile.read_text()
if "JRC_IXC_CENTRAL_V1" in s:
    sys.exit("STOP: IXC ja aparece no server.js")
if "JRC_OPA_CENTRAL_OAUTH_V1" not in s:
    sys.exit("STOP: atualizacao Opa OAuth nao foi encontrada no server.js ativo")
required = (
    "integrations/ixc-webservice-client.js",
    "integrations/ixc-webservice-tools.js",
    "integrations/ixc-webservice-auth.js",
)
for p in required:
    if not (root / p).is_file():
        sys.exit("STOP: modulo IXC ausente: " + p)

imports = """// JRC_IXC_CENTRAL_V1: IXC Webservice read tools - OAuth only
import { ixcWebserviceAuthorized } from './integrations/ixc-webservice-auth.js';
import { createIxcWebserviceClient } from './integrations/ixc-webservice-client.js';
import { registerIxcWebserviceTools } from './integrations/ixc-webservice-tools.js';
"""
imp = re.search(r"^import .+;$", s, re.M)
if not imp:
    sys.exit("STOP: nenhum import encontrado")
s = s[:imp.start()] + imports + s[imp.start():]

old_header = "const makeMcpHandler = (includeShield, includeFinance = false, includeOpa = false) => createMcpHandler(() => {"
new_header = "const makeMcpHandler = (includeShield, includeFinance = false, includeOpa = false, includeIxc = false) => createMcpHandler(() => {"
if s.count(old_header) != 1:
    sys.exit("STOP: assinatura MCP inesperada; server nao modificado")
s = s.replace(old_header, new_header, 1)
registration = "if (includeFinance) registerFinanceSuite(mcp);"
if s.count(registration) != 1:
    sys.exit("STOP: registro do Financeiro nao encontrado")
s = s.replace(
    registration,
    registration + "\n    if (includeIxc) registerIxcWebserviceTools(mcp, createIxcWebserviceClient());",
    1
)

# Preserve Opa, Financeiro, SHIELD and shared Opa bearer behavior. The four
# combinations of existing Opa/Financeiro permission plus IXC can be exposed
# only through the OAuth subject+role authorization path.
anchor = "const opaFinanceCentralMcpHandler = toNodeHandler(makeMcpHandler(true, true, true));"
if s.count(anchor) != 1:
    sys.exit("STOP: handler Opa/Financeiro nao encontrado")
handlers = """
const ixcCentralMcpHandler = toNodeHandler(makeMcpHandler(true, false, false, true));
const ixcFinanceCentralMcpHandler = toNodeHandler(makeMcpHandler(true, true, false, true));
const ixcOpaCentralMcpHandler = toNodeHandler(makeMcpHandler(true, false, true, true));
const ixcFinanceOpaCentralMcpHandler = toNodeHandler(makeMcpHandler(true, true, true, true));
"""
s = s.replace(anchor, anchor + "\n" + handlers, 1)

route = "if (opaApiAuthorized(payload)) {"
if s.count(route) != 1:
    sys.exit("STOP: rota OAuth Opa nao encontrada")
ixc_route = """if (ixcWebserviceAuthorized(payload)) {
        const finance = financeMcpAuthorized(payload);
        const opa = opaApiAuthorized(payload);
        const selected = finance
          ? (opa ? ixcFinanceOpaCentralMcpHandler : ixcFinanceCentralMcpHandler)
          : (opa ? ixcOpaCentralMcpHandler : ixcCentralMcpHandler);
        return selected(req, res);
      }
      """
s = s.replace(route, ixc_route + route, 1)

stage.mkdir(mode=0o700, parents=True, exist_ok=True)
output = stage / "server.js"
output.write_text(s)
subprocess.run(["node", "--check", str(output)], check=True)
print("IXC STAGED_OK: codigo preparado e sintaxe valida")
print("PRODUCAO INTACTA:", str(sfile))
print("ARQUIVO:", str(output))
print("PENDENTE: token mount, Keycloak, compose, testes e deploy controlado")
