#!/usr/bin/env python3
"""Prepare an information-only integration inventory for the LIVE ACS MCP panel.

Never deploys automatically. Preserves local panel customizations (Financeiro,
PABX unified grid, status labels). No Docker, OAuth, credential or API changes.
"""
from pathlib import Path
import subprocess
import sys

root = Path("/var/www/gacs")
live = root / "assets/js/mcp-connections-panel.js"
stage = Path("/tmp/acs-mcp-integration-map")
stage.mkdir(mode=0o700, parents=True, exist_ok=True)

if not live.is_file():
    sys.exit("STOP: active ACS panel not found")
s = live.read_text()
anchor = "      renderAdditional(panel, instancesData.instances);"
if s.count(anchor) != 1:
    sys.exit("STOP: live panel load anchor not unique")
if "JRC_MCP_INTEGRATION_MAP_V2" in s:
    sys.exit("STOP: inventory already staged or installed")

injection = r"""
  // JRC_MCP_INTEGRATION_MAP_V2
  // This is a topology inventory, NOT proof of authentication or write access.
  // The ACS has no API to enumerate ChatGPT-only installed plugins.
  function showMcpTopology(panel) {
    const grid = panel.querySelector('.jrc-mcp-integrations-grid');
    const parent = panel.querySelector('.jrc-mcp-primary-card__integrations-head');
    if (!grid || !parent) return;

    let inventory = panel.querySelector('[data-mcp-topology-summary]');
    if (!inventory) {
      inventory = document.createElement('div');
      inventory.setAttribute('data-mcp-topology-summary', 'true');
      inventory.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:9px;margin:12px 0 15px';
      const groups = [
        ['Infraestrutura ACS', 'ACS · hospeda o JR Conect MCPv2', 'Servidor ACS é o host do MCP. O endpoint /mcp-acs é um serviço de monitoramento independente; sua disponibilidade deve ser validada separadamente.'],
        ['Direto no MCP central', 'Wiki · SHIELD · Financeiro · PABX (integração parcial)', 'Ferramentas no endpoint /mcp; operações administrativas exigem autorização.'],
        ['Clientes conectados ao MCP', 'ChatGPT · Opa Suite', 'São consumidores do MCP, não servidores integrados. O Opa não recebe ferramentas administrativas.'],
        ['Somente no ChatGPT', 'Inventário não sincronizado', 'O ACS não consulta a lista de plugins exclusivos do ChatGPT. O Financeiro independente pode ser mantido como legado até validar a migração.']
      ];
      groups.forEach(([title, names, explanation]) => {
        const item = document.createElement('section');
        item.style.cssText = 'border:1px solid #343c48;border-radius:9px;padding:11px 12px;background:#10151d;min-width:0';
        const heading = document.createElement('strong');
        heading.style.cssText = 'display:block;font-size:12px;margin-bottom:7px;color:#e5e7eb';
        heading.textContent = title;
        const systems = document.createElement('div');
        systems.style.cssText = 'font-size:12px;font-weight:600;margin-bottom:5px;color:#dce3ea';
        systems.textContent = names;
        const note = document.createElement('div');
        note.style.cssText = 'font-size:11px;color:#9aa8b7;line-height:1.45';
        note.textContent = explanation;
        item.append(heading, systems, note);
        inventory.appendChild(item);
      });
      parent.insertAdjacentElement('afterend', inventory);
    }

    const cards = panel.querySelectorAll('.jrc-mcp-integration-card, .jrc-mcp-instance-card');
    cards.forEach(card => {
      if (card.querySelector('[data-mcp-topology-label]')) return;
      const nameEl = card.querySelector('.jrc-mcp-integration-card__name, .jrc-mcp-instance-card__name');
      const subtitle = card.querySelector('.jrc-mcp-integration-card__subtitle, .jrc-mcp-instance-card__subtitle');
      if (!nameEl || !subtitle) return;
      const name = (nameEl.textContent || '').toLowerCase();
      let label = '';
      if (name.includes('wiki') || name.includes('shield') || name.includes('financeiro')) {
        label = 'Direto no MCP central';
      } else if (name.includes('pabx')) {
        label = 'MCP central · integração parcial';
      } else if (name.includes('chatgpt') || name.includes('opa suite')) {
        label = 'Cliente do MCP';
      }
      if (!label) return;
      const tag = document.createElement('span');
      tag.setAttribute('data-mcp-topology-label', 'true');
      tag.style.cssText = 'display:inline-block;font-size:10px;color:#b8c6d4;border:1px solid #384451;padding:3px 6px;border-radius:5px;margin-top:5px';
      tag.textContent = label;
      subtitle.insertAdjacentElement('afterend', tag);
    });
  }
  function monitorMcpTopology(panel) {
    if (!panel.__mcpTopologyObserver) {
      const observer = new MutationObserver(() => showMcpTopology(panel));
      observer.observe(panel, {childList: true, subtree: true});
      panel.__mcpTopologyObserver = observer;
    }
    showMcpTopology(panel);
  }

"""
# Function declarations before load; call after both primary and instance render.
position = "  async function load(panel) {"
if s.count(position) != 1:
    sys.exit("STOP: load function not found")
s = s.replace(position, injection + position, 1)
s = s.replace(anchor, anchor + "\n      monitorMcpTopology(panel);", 1)

target = stage / "mcp-connections-panel.js"
target.write_text(s)
subprocess.run(["node", "--check", str(target)], check=True)
print("STAGED_OK: classificacao ACS/centralizados/clientes/ChatGPT preparada.")
print("Producao intacta. Arquivo:", target)
