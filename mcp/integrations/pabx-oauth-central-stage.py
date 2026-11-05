#!/usr/bin/env python3
"""Stage PABX read/admin tools into the production MCP OAuth tool catalog.

Reads only the LIVE /opt/jrconect-mcp/server.js. Does not deploy, restart,
change live credentials, grant rights, or modify existing endpoint routing.
All PABX tools are added ONLY to a Keycloak-authorized handler. Existing
/mcp-admin remains untouched. Fails closed on unexpected server structure.
"""
from pathlib import Path
import subprocess
import sys

root = Path("/opt/jrconect-mcp")
source = root / "server.js"
output = Path("/tmp/jrc-pabx-oauth-stage/server.js")
read_token = root / "secrets/pabx/read-token"
admin_token = root / "secrets/pabx/admin-token"

if not source.is_file():
    sys.exit("STOP: server.js ativo nao encontrado")
for f in (root / "pabx.js", root / "pabx-admin.js", read_token, admin_token):
    if not f.is_file():
        sys.exit("STOP: arquivo obrigatorio ausente: " + str(f))
s = source.read_text()

marker = "JRC_PABX_CENTRAL_OAUTH_V1"
if marker in s:
    sys.exit("STOP: marcador PABX central ja existe")
for existing in ("registerPabxTools", "registerPabxAdminTools", "pabxAdminAuthorized",
                 "financeMcpAuthorized", "opaApiAuthorized", "ixcWebserviceAuthorized"):
    if existing not in s:
        sys.exit("STOP: integracao necessaria ausente no server.js: " + existing)

signature = ("const makeMcpHandler = (includeShield, includeFinance = false, "
             "includeOpa = false, includeIxc = false) => createMcpHandler(() => {")
if s.count(signature) != 1:
    sys.exit("STOP: assinatura makeMcpHandler diferente do esperado")
s = s.replace(
    signature,
    "const makeMcpHandler = (includeShield, includeFinance = false, "
    "includeOpa = false, includeIxc = false, includePabx = false) "
    "=> createMcpHandler(() => {",
    1,
)

# PABX must never be registered in the shared handler used by the Opa bearer.
registration = "registerPabxTools(mcp);"
if s.count(registration) != 1:
    sys.exit("STOP: registro PABX inesperado (nao modificamos o original)")
s = s.replace(
    registration,
    """// JRC_PABX_CENTRAL_OAUTH_V1
    // Both read and admin PABX tools appear ONLY on the PABX-authorized
    // OAuth handler; never on the shared Opa bearer MCP catalog.
    if (includePabx) {
      registerPabxTools(mcp);
      registerPabxAdminTools(mcp);
    }""",
    1,
)

# Additional variants retain whatever Financeiro/Opa/IXC authorization the
# caller already had. No existing handler or /mcp-admin behavior is changed.
declaration = ("const ixcFinanceOpaCentralMcpHandler = "
               "toNodeHandler(makeMcpHandler(true, true, true, true));")
if s.count(declaration) != 1:
    sys.exit("STOP: variante IXC/Financeiro/Opa nao encontrada")
variants = """
// Isolated PABX OAuth variants: index bit 0 Financeiro, bit 1 Opa, bit 2 IXC.
const pabxCentralMcpHandlers = [
  toNodeHandler(makeMcpHandler(true, false, false, false, true)),
  toNodeHandler(makeMcpHandler(true, true,  false, false, true)),
  toNodeHandler(makeMcpHandler(true, false, true,  false, true)),
  toNodeHandler(makeMcpHandler(true, true,  true,  false, true)),
  toNodeHandler(makeMcpHandler(true, false, false, true,  true)),
  toNodeHandler(makeMcpHandler(true, true,  false, true,  true)),
  toNodeHandler(makeMcpHandler(true, false, true,  true,  true)),
  toNodeHandler(makeMcpHandler(true, true,  true,  true,  true)),
];
"""
s = s.replace(declaration, declaration + "\n" + variants, 1)

# At this point in the live server the validated JWT payload is available,
# and the enclosing SHIELD_SUB allowlist has been checked. This branch is
# deliberately BEFORE existing IXC/Financeiro/Opa tool-set selection.
route_anchor = "if (ixcWebserviceAuthorized(payload)) {"
if s.count(route_anchor) != 1:
    sys.exit("STOP: ancora da rota OAuth IXC nao encontrada")
route = """if (pabxAdminAuthorized(payload)) {
          const permissions =
            (financeMcpAuthorized(payload) ? 1 : 0) |
            (opaApiAuthorized(payload) ? 2 : 0) |
            (ixcWebserviceAuthorized(payload) ? 4 : 0);
          return pabxCentralMcpHandlers[permissions](req, res);
        }
        """
s = s.replace(route_anchor, route + route_anchor, 1)

output.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
output.write_text(s)
subprocess.run(["node", "--check", str(output)], check=True)
print("PABX CENTRAL: STAGED_OK")
print("SERVIDOR ATIVO: NAO MODIFICADO")
print("ARQUIVO:", output)
print("FALTAM: compose com 4 overlays, autorizacao OAuth, validacao API e deploy")
