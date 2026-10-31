(() => {
  'use strict';

  if (window.__JRC_MCP_CONNECTIONS_PANEL__) return;
  window.__JRC_MCP_CONNECTIONS_PANEL__ = true;

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
    const d = Math.floor(h / 24);
    return d + ' d';
  }

  function findTarget() {
    const selectors = [
      '.card-header',
      '.section-title',
      '.config-title',
      'h1', 'h2', 'h3', 'h4', 'h5'
    ];

    const nodes = [...document.querySelectorAll(selectors.join(','))];
    const anchor = nodes.find(el => /\bMCP\b/i.test((el.textContent || '').trim()));

    if (anchor) {
      const tab = anchor.closest('.tab-pane');
      if (tab) return { mode: 'append', node: tab };

      const card = anchor.closest('.card');
      if (card) return { mode: 'after', node: card };

      if (anchor.parentElement) return { mode: 'after', node: anchor.parentElement };
    }

    const fallback =
      document.querySelector('.tab-content') ||
      document.querySelector('.content-wrapper') ||
      document.querySelector('main') ||
      document.body;

    return { mode: 'append', node: fallback };
  }

  function buildPanel() {
    const panel = document.createElement('section');
    panel.id = 'jrc-mcp-connections-panel';
    panel.className = 'jrc-mcp-connections';
    panel.innerHTML = `
      <div class="jrc-mcp-connections__head">
        <div>
          <div class="jrc-mcp-connections__title">Servidores conectados ao MCP</div>
          <div class="jrc-mcp-connections__subtitle">
            Clientes autenticados que já se comunicaram com o JR Conect MCPv2.
          </div>
        </div>
        <button type="button" class="jrc-mcp-connections__refresh" data-jrc-mcp-refresh>
          Atualizar
        </button>
      </div>
      <div class="jrc-mcp-connections__body">
        <div class="jrc-mcp-connections__loading">Carregando conexões...</div>
      </div>
    `;
    return panel;
  }

  function render(panel, data) {
    const body = panel.querySelector('.jrc-mcp-connections__body');
    const rows = Array.isArray(data?.connections) ? data.connections : [];

    if (!rows.length) {
      body.innerHTML =
        '<div class="jrc-mcp-connections__empty">Nenhuma conexão registrada ainda. ' +
        'Os clientes aparecerão aqui na próxima comunicação com o MCP.</div>';
      return;
    }

    body.innerHTML = `
      <div class="jrc-mcp-connections__table-wrap">
        <table>
          <thead>
            <tr>
              <th>Conexão</th>
              <th>Autenticação</th>
              <th>Status</th>
              <th>Última comunicação</th>
              <th>Requisições</th>
            </tr>
          </thead>
          <tbody>
            ${rows.map(row => {
              const status = row.status === 'online' ? 'online' : 'offline';
              const auth = row.auth_type === 'oauth' ? 'OAuth / Keycloak' :
                row.auth_type === 'token' ? 'Token MCP' : (row.auth_type || '—');

              return `
                <tr>
                  <td>
                    <strong>${esc(row.name || row.client_id || row.id)}</strong>
                    <div style="color:#7f8998;font-size:11px;margin-top:2px;">
                      ${esc(row.client_id || row.id || '')}
                    </div>
                  </td>
                  <td>${esc(auth)}</td>
                  <td>
                    <span class="jrc-mcp-connections__status jrc-mcp-connections__status--${status}">
                      ${status === 'online' ? 'Online' : 'Offline'}
                    </span>
                  </td>
                  <td title="${esc(row.last_seen || '')}">${esc(timeAgo(row.last_seen))}</td>
                  <td class="jrc-mcp-connections__count">${Number(row.requests || 0).toLocaleString('pt-BR')}</td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;
  }

  async function load(panel) {
    const body = panel.querySelector('.jrc-mcp-connections__body');
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

      render(panel, data);
    } catch (error) {
      body.innerHTML =
        '<div class="jrc-mcp-connections__error">' +
        esc(error.message || 'Falha ao consultar conexões MCP.') +
        '</div>';
    }
  }

  function boot() {
    if (document.getElementById('jrc-mcp-connections-panel')) return;

    const target = findTarget();
    const panel = buildPanel();

    if (target.mode === 'after') {
      target.node.insertAdjacentElement('afterend', panel);
    } else {
      target.node.appendChild(panel);
    }

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
