#!/usr/bin/env python3
"""Stage a distinct IXC Webservice card in the existing ACS MCP panel.
Preserves the LIVE custom JS and never changes Docker, auth or APIs.
"""
from pathlib import Path
import subprocess
import sys

live = Path("/var/www/gacs/assets/js/mcp-connections-panel.js")
dest = Path("/tmp/acs-mcp-ixc-stage/mcp-connections-panel.js")
if not live.is_file():
    sys.exit("STOP: painel ativo nao encontrado")
s = live.read_text()
marker = "JRC_IXC_CENTRAL_CARD_V1"
if marker in s:
    sys.exit("STOP: IXC card ja aplicado")
if "JRC_MCP_CARDS_V3" not in s:
    sys.exit("STOP: createSystemCard da versao atual nao encontrada")
anchor = "    const cards = panel.querySelectorAll('.jrc-mcp-integration-card, .jrc-mcp-instance-card');"
if s.count(anchor) != 1:
    sys.exit("STOP: ponto de insercao nao encontrado de modo unico")
addition = r'''
    // JRC_IXC_CENTRAL_CARD_V1 — deployment is not functional verification.
    // Display IXC separately from the former native IXC OAuth integration.
    if (main && !grid.querySelector('[data-mcp-system="ixc-webservice"]')) {
      grid.appendChild(createSystemCard('ixc-webservice',
        'IXC Provedor — API Webservice',
        'Integracao no MCP central hospedado no ACS',
        'bi-database-check',
        ['API: /webservice/v1', 'Keycloak: ixc-mcp-admin',
         '3 consultas preparadas', 'Operacoes de escrita: pendentes',
         'Teste OAuth pelo ChatGPT: pendente'],
        'Implantado'
      ));
    }
'''
s = s.replace(anchor, addition + "\n" + anchor, 1)
dest.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
dest.write_text(s)
subprocess.run(["node", "--check", str(dest)], check=True)
print("IXC CARD STAGED_OK")
print("Arquivo:", dest)
print("Producao intacta.")
