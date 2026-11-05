#!/usr/bin/env python3
"""Prepare an ACS PABX status label update against the live customized panel.
Never alter production, Docker, Keycloak, Issabel or MCP code.
"""
from pathlib import Path
import subprocess
import sys

source = Path("/var/www/gacs/assets/js/mcp-connections-panel.js")
stage = Path("/tmp/acs-pabx-validated-stage/mcp-connections-panel.js")
if not source.is_file():
    sys.exit("STOP: JS ativo do painel ACS nao encontrado")
s = source.read_text()
old = "MCP central - integração parcial"
new = "MCP central - consultas validadas"
if new in s and old not in s:
    sys.exit("STOP: status PABX ja esta atualizado")
if s.count(old) != 1:
    sys.exit("STOP: rotulo PABX nao encontrado de forma unica; nenhum arquivo alterado")
# The existing card is already labeled Online. Change only its stale
# description, not statuses of other integration cards.
if "JR Conect" not in s or "PABX" not in s:
    sys.exit("STOP: identidade do PABX ausente no painel")
s = s.replace(old, new, 1)
stage.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
stage.write_text(s)
subprocess.run(["node", "--check", str(stage)], check=True)
print("PABX CARD: STAGED_OK")
print("VALIDADO: consultas reais ao Issabel via MCP central")
print("ADMINISTRACAO: ferramentas expostas; escrita nao testada")
print("SERVIDOR ATIVO: NAO ALTERADO")
print("ARQUIVO:", stage)
