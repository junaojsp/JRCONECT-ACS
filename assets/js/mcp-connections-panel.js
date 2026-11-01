(() => {
  'use strict';

  if (window.__JRC_MCP_UNIFIED_CARDS_V9__) return;
  window.__JRC_MCP_UNIFIED_CARDS_V9__ = true;

  function esc(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function timeAgo(value) {
    const ts = Date.parse(value || '');
    if (!Number.isFinite(ts)) return '—';
    const diff = Math.max(0, Date.now() - ts);
    const sec = Math.floor(diff / 1000);
    if (sec < 10) return 'agora';
    if (sec < 60) return sec + ' s';
    const min = Math.floor(sec / 60);
    if (min < 60) return min + ' min';
    const h = Math.floor(min / 60);
    if (h < 24) return h + ' h';
    return Math.floor(h / 24) + ' d';
  }

  function findTarget() {
    return document.querySelector('#mcp-server-hub') ||
      document.querySelector('#mcp-config') ||
      document.querySelector('.tab-content') ||
      document.querySelector('main') ||
      document.body;
  }

  function itemIcon(item) {
    const id = String(item?.id || item?.client_id || '').toLowerCase();
    if (id.includes('wiki')) return 'bi-book';
    if (id.includes('opa')) return 'bi-chat-dots';
    if (id.includes('chatgpt')) return 'bi-robot';
    return 'bi-hdd-stack';
  }

  function buildPanel() {
    const panel = document.createElement('section');
    panel.id = 'jrc-mcp-connections-panel';
    panel.className = 'jrc-mcp-hub';
    panel.innerHTML = `
      <div class="jrc-mcp-hub__head">
        <div class="jrc-mcp-hub__brand">
          <div class="jrc-mcp-hub__brand-icon"><i class="bi bi-layers"></i></div>
          <div>
            <div class="jrc-mcp-hub__title">JR Conect MCPv2</div>
            <div class="jrc-mcp-hub__subtitle">
              Visão consolidada dos MCPs, integrações e clientes conectados.
            </div>
          </div>
        </div>
        <button type="button" class="jrc-mcp-hub__refresh" data-jrc-mcp-refresh>
          <i class="bi bi-arrow-repeat"></i>
          <span>Atualizar</span>
        </button>
      </div>

      <div class="jrc-mcp-hub__section">
        <div class="jrc-mcp-hub__section-title">
          <span></span>
          MCPs e integrações conectadas
        </div>
        <div class="jrc-mcp-services jrc-mcp-unified-grid" data-jrc-mcp-unified>
          <div class="jrc-mcp-hub__loading">Carregando MCPs e integrações...</div>
        </div>
      </div>
    `;
    return panel;
  }

  function renderUnified(panel, data) {
    const root = panel.querySelector('[data-jrc-mcp-unified]');
    const services = Array.isArray(data?.services) ? data.services : [];
    const connections = Array.isArray(data?.connections) ? data.connections : [];

    const items = [];

    services.forEach(service => {
      items.push({
        kind: 'service',
        id: service.id,
        name: service.name || service.id,
        type: String(service?.id || '').toLowerCase().includes('wiki')
          ? 'Integração interna'
          : (service.type || 'Servidor MCP'),
        status: service.status === 'online' ? 'online' : 'offline',
        statusLabel: service.status === 'online' ? 'Online' : 'Offline',
        tools: Array.isArray(service.tools) ? service.tools : [],
      });
    });

    const orderedConnections = [...connections].sort((a, b) => {
      const aId = String(a?.id || a?.client_id || '').toLowerCase();
      const bId = String(b?.id || b?.client_id || '').toLowerCase();
      const weight = id => id.includes('opa') ? 0 : id.includes('chatgpt') ? 1 : 2;
      return weight(aId) - weight(bId);
    });

    orderedConnections.forEach(row => {
      const auth = row.auth_type === 'oauth'
        ? 'OAuth / Keycloak'
        : row.auth_type === 'token'
          ? 'Token MCP'
          : (row.auth_type || 'Autenticação MCP');

      items.push({
        kind: 'client',
        id: row.id || row.client_id,
        client_id: row.client_id || row.id,
        name: row.name || row.client_id || row.id,
        type: 'Cliente MCP externo',
        status: 'online',
        statusLabel: 'Conectado',
        auth,
        lastSeen: row.last_seen,
        requests: Number(row.requests || 0),
      });
    });

    if (!items.length) {
      root.innerHTML = '<div class="jrc-mcp-hub__empty">Nenhum MCP ou integração registrada.</div>';
      return;
    }

    root.innerHTML = items.map(item => {
      const isClient = item.kind === 'client';

      const detailChips = isClient
        ? [
            '<span class="jrc-mcp-tool"><i class="bi bi-shield-lock"></i> ' + esc(item.auth) + '</span>',
            '<span class="jrc-mcp-tool"><i class="bi bi-clock"></i> ' + esc(timeAgo(item.lastSeen)) + '</span>',
            '<span class="jrc-mcp-tool"><i class="bi bi-stack"></i> ' + esc(item.requests.toLocaleString('pt-BR')) + ' req.</span>'
          ].join('')
        : item.tools.length
          ? item.tools.map(tool => '<span class="jrc-mcp-tool">' + esc(tool) + '</span>').join('')
          : '<span class="jrc-mcp-tool jrc-mcp-tool--muted">Sem ferramentas listadas</span>';

      const clientId = isClient && item.client_id
        ? '<div class="jrc-mcp-service-card__client-id">' + esc(item.client_id) + '</div>'
        : '';

      return `
        <article class="jrc-mcp-service-card ${isClient ? 'jrc-mcp-service-card--client' : ''}">
          <div class="jrc-mcp-service-card__icon">
            <i class="bi ${itemIcon(item)}"></i>
          </div>

          <div class="jrc-mcp-service-card__content">
            <div class="jrc-mcp-service-card__top">
              <div>
                <div class="jrc-mcp-service-card__name">${esc(item.name)}</div>
                <div class="jrc-mcp-service-card__type">${esc(item.type)}</div>
                ${clientId}
              </div>

              <span class="jrc-mcp-status jrc-mcp-status--${item.status}">
                ${esc(item.statusLabel)}
              </span>
            </div>

            <div class="jrc-mcp-service-card__tools">
              ${detailChips}
            </div>
          </div>
        </article>
      `;
    }).join('');
  }

  async function load(panel) {
    try {
      const response = await fetch('/api/mcp-connections.php', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      });
      const data = await response.json();

      if (!response.ok || !data?.success) {
        throw new Error(data?.message || 'Não foi possível consultar o MCP.');
      }

      renderUnified(panel, data);
    } catch (error) {
      const root = panel.querySelector('[data-jrc-mcp-unified]');
      root.innerHTML =
        '<div class="jrc-mcp-hub__error">' +
        esc(error.message || 'Falha ao consultar MCPs e integrações.') +
        '</div>';
    }
  }

  function boot() {
    const old = document.getElementById('jrc-mcp-connections-panel');
    if (old) old.remove();

    const target = findTarget();
    const panel = buildPanel();
    target.appendChild(panel);

    panel.querySelector('[data-jrc-mcp-refresh]')?.addEventListener('click', () => load(panel));
    load(panel);

    window.setInterval(() => {
      if (!document.hidden) load(panel);
    }, 30000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
