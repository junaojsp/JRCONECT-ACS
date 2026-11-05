#!/usr/bin/env python3
"""Prepare per-service ACS MCP cards against the live, customized panel.
Does not change production. No credentials or services touched.
"""
from pathlib import Path
import subprocess
import sys

live = Path("/var/www/gacs/assets/js/mcp-connections-panel.js")
stage = Path("/tmp/acs-mcp-cards-stage")
if not live.is_file():
    sys.exit("STOP: painel ACS ativo nao encontrado")
s = live.read_text()
if "JRC_MCP_CARDS_V3" in s:
    sys.exit("STOP: cards V3 ja aplicados")
if "JRC_MCP_INTEGRATION_MAP_V2" not in s:
    sys.exit("STOP: classificacao do PR 60 nao encontrada")

needle = "    const cards = panel.querySelectorAll('.jrc-mcp-integration-card, .jrc-mcp-instance-card');"
if s.count(needle) != 1:
    sys.exit("STOP: ancora dos cards nao e unica")

code = r'''
    // JRC_MCP_CARDS_V3: identifiers show topology, not administrative verification.
    // Add the host ACS and SHIELD only when the central MCP card is rendered.
    function createSystemCard(key, name, subtitle, icon, details, status) {
      const card = document.createElement('article');
      card.className = 'jrc-mcp-integration-card';
      card.dataset.mcpSystem = key;
      card.style.cssText = 'min-width:0;box-sizing:border-box';
      const iconBox = document.createElement('div');
      iconBox.className = 'jrc-mcp-integration-card__icon';
      const iconNode = document.createElement('i');
      iconNode.className = 'bi ' + icon;
      iconBox.appendChild(iconNode);
      const body = document.createElement('div');
      body.className = 'jrc-mcp-integration-card__body';
      const top = document.createElement('div');
      top.className = 'jrc-mcp-integration-card__top';
      const identity = document.createElement('div');
      const title = document.createElement('div');
      title.className = 'jrc-mcp-integration-card__name';
      title.textContent = name;
      const sub = document.createElement('div');
      sub.className = 'jrc-mcp-integration-card__subtitle';
      sub.textContent = subtitle;
      identity.append(title, sub);
      const state = document.createElement('span');
      state.className = 'jrc-mcp-status jrc-mcp-status--' + (status === 'Online' ? 'online' : 'registered');
      state.textContent = status;
      top.append(identity, state);
      const chips = document.createElement('div');
      chips.className = 'jrc-mcp-integration-card__chips';
      details.forEach(detail => {
        const span = document.createElement('span');
        span.className = 'jrc-mcp-tool';
        span.textContent = detail;
        chips.appendChild(span);
      });
      body.append(top, chips);
      card.append(iconBox, body);
      return card;
    }
    const main = panel.querySelector('.jrc-mcp-primary-card');
    const coreOnline = !!main?.querySelector('.jrc-mcp-primary-card__header .jrc-mcp-status--online');
    if (main && !grid.querySelector('[data-mcp-system="acs"]')) {
      grid.appendChild(createSystemCard('acs', 'Servidor ACS',
        'Hospeda o JR Conect MCPv2; monitoramento /mcp-acs separado',
        'bi-hdd-network', ['Infraestrutura central', 'MCP local 127.0.0.1:3100',
        'Status do host/monitoramento nao aferido aqui'], coreOnline ? 'Online' : 'Hospedagem'));
    }
    if (main && !grid.querySelector('[data-mcp-system="shield"]')) {
      grid.appendChild(createSystemCard('shield', 'JRC SHIELD',
        'Integrado ao MCP central com permissoes OAuth',
        'bi-shield-check', ['4 consultas', '3 acoes administrativas',
        'Execucao administrativa ainda nao validada'], 'Integrado'));
    }

    // Express the relationship and capabilities INSIDE each existing card.
    grid.querySelectorAll('.jrc-mcp-integration-card').forEach(card => {
      const nameEl = card.querySelector('.jrc-mcp-integration-card__name');
      if (!nameEl) return;
      const name = (nameEl.textContent || '').toLowerCase();
      let detail = '';
      if (name.includes('wiki')) detail = 'Centralizado no MCP · 5 ferramentas';
      else if (name.includes('financeiro')) detail =
        'Centralizado no MCP · 4 consultas + 8 acoes administrativas · /mcp-finance mantido como legado';
      else if (name.includes('chatgpt')) detail =
        'Cliente OAuth do MCP · nao e servidor';
      else if (name.includes('opa suite')) detail =
        'Cliente do MCP por token · sem ferramentas SHIELD/Financeiro';
      else if (name.includes('pabx')) detail =
        'Integracao parcial ao MCP · ferramentas administrativas exigem validacao';
      else if (name.includes('shield')) detail = 'Centralizado no MCP · 7 ferramentas';
      else if (name.includes('acs')) detail = 'Hospedagem do MCP · monitoramento separado';
      if (!detail) return;
      let badge = card.querySelector('[data-mcp-card-detail]');
      if (!badge) {
        badge = document.createElement('div');
        badge.dataset.mcpCardDetail = 'true';
        badge.style.cssText = 'color:#9fb0c5;font-size:11px;line-height:1.45;padding:5px 0 0';
        const chips = card.querySelector('.jrc-mcp-integration-card__chips');
        if (chips) chips.insertAdjacentElement('afterend', badge);
        else card.appendChild(badge);
      }
      if (badge.textContent !== detail) badge.textContent = detail;
      if (name.includes('financeiro')) {
        const sub = card.querySelector('.jrc-mcp-integration-card__subtitle');
        if (sub && sub.textContent.includes('MCP isolado')) {
          sub.textContent = 'Integrado ao MCP central; endpoint independente preservado';
        }
      }
    });
    const small = main?.querySelector('.jrc-mcp-primary-card__integrations-head small');
    if (small) {
      const total = grid.querySelectorAll('.jrc-mcp-integration-card, .jrc-mcp-instance-card').length;
      const label = total + ' cards (inclui infraestrutura e clientes)';
      if (small.textContent !== label) small.textContent = label;
    }
'''
# Important: code runs inside showMcpTopology(panel), after grid and parent are validated.
s = s.replace(needle, code + "\n" + needle, 1)
stage.mkdir(mode=0o700, parents=True, exist_ok=True)
target = stage / "mcp-connections-panel.js"
target.write_text(s)
subprocess.run(["node", "--check", str(target)], check=True)
print("STAGED_OK: cards individuais do ACS, SHIELD e detalhes nos demais cards.")
print("Arquivo preparado:", target)
print("Producao intacta.")
