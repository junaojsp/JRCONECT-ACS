#!/usr/bin/env python3
"""Stage a non-operational local Opa API integration card on the live ACS dashboard."""
from pathlib import Path
import subprocess
import sys

live = Path("/var/www/gacs/assets/js/mcp-connections-panel.js")
target = Path("/tmp/acs-mcp-opa-local-stage/mcp-connections-panel.js")
if not live.is_file():
    sys.exit("STOP: painel ACS nao encontrado")
s = live.read_text()
marker = "JRC_OPA_LOCAL_STAGED_CARD_V1"
if marker in s:
    sys.exit("STOP: card Opa local ja preparado/aplicado")
if "JRC_MCP_CARDS_V3" not in s:
    sys.exit("STOP: painel precisa primeiro dos cards do PR 61")

anchor = "    const cards = panel.querySelectorAll('.jrc-mcp-integration-card, .jrc-mcp-instance-card');"
if s.count(anchor) != 1:
    sys.exit("STOP: ancora dos cards ausente ou duplicada")
code = r'''
    // JRC_OPA_LOCAL_STAGED_CARD_V1
    // The modules are installed on the ACS host, NOT connected to the live MCP yet.
    // Do not label this system Online until OAuth tools/list and API access are tested.
    if (main && !grid.querySelector('[data-mcp-system="opa-api-local"]')) {
      grid.appendChild(createSystemCard('opa-api-local',
        'Opa Suite — API administrativa',
        'Adaptador instalado localmente no ACS; ainda nao publicado no MCP',
        'bi-chat-dots',
        ['Local: ACS / integrations', 'API GET validada: HTTP 200',
         '2 consultas + 5 operacoes preparadas', 'OAuth e ferramentas: pendentes'],
        'Preparado'
      ));
    }
'''
s=s.replace(anchor,code+"\n"+anchor,1)
target.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
target.write_text(s)
subprocess.run(["node","--check",str(target)],check=True)
print("OPA LOCAL CARD: STAGED_OK")
print("Sem alteracoes em producao. Arquivo:",target)
