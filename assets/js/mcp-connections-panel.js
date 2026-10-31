(() => {
  'use strict';

  if (window.__JRC_MCP_CONNECTIONS_PANEL_V4__) return;
  window.__JRC_MCP_CONNECTIONS_PANEL_V4__ = true;

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

  function openMcpTabFromUrl() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') !== 'mcp') return;

    const trigger = document.querySelector('[data-bs-target="#mcp-config"], #mcp-tab');
    if (!trigger) return;

    try {
      if (window.bootstrap?.Tab) {
        window.bootstrap.Tab.getOrCreateInstance(trigger).show();
      } else {
        trigger.click();
      }
    } catch (_) {
      trigger.click();
    }
  }

  function findTarget() {
    return document.querySelector('#mcp-server-hub') ||
      document.querySelector('#mcp-config') ||
      document.querySelector('.tab-content') ||
      document.querySelector('main') ||
      document.body;
  }

  function serviceIcon(service) {
    const id = String(service?.id || '').toLowerCase();
    if (id.includes('wiki')) return 'bi-book';
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
              Serviços disponíveis e clientes autenticados no servidor MCP.
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
          Serviços e ferramentas MCP
        </div>
        <div class="jrc-mcp-services" data-jrc-mcp-services>
          <div class="jrc-mcp-hub__loading">Carregando serviços...</div>
        </div>
      </div>

      <div class="jrc-mcp-hub__section">
        <div class="jrc-mcp-hub__section-title">
          <span></span>
          Conexões autenticadas
        </div>
        <div data-jrc-mcp-connections>
          <div class="jrc-mcp-hub__loading">Carregando conexões...</div>
        </div>
      </div>
    `;
    return panel;
  }

  function renderServices(panel, data) {
    const root = panel.querySelector('[data-jrc-mcp-services]');
    const rows = Array.isArray(data?.services) ? data.services : [];

    if (!rows.length) {
      root.innerHTML = '<div class="jrc-mcp-hub__empty">Nenhum serviço MCP informado.</div>';
      return;
    }

    root.innerHTML = rows.map(service => {
      const status = service.status === 'online' ? 'online' : 'offline';
      const tools = Array.isArray(service.tools) ? service.tools : [];
      return `
        <article class="jrc-mcp-service-card">
          <div class="jrc-mcp-service-card__icon">
            <i class="bi ${serviceIcon(service)}"></i>
          </div>

          <div class="jrc-mcp-service-card__content">
            <div class="jrc-mcp-service-card__top">
              <div>
                <div class="jrc-mcp-service-card__name">${esc(service.name || service.id)}</div>
                <div class="jrc-mcp-service-card__type">${esc(service.type || 'Serviço MCP')}</div>
              </div>
              <span class="jrc-mcp-status jrc-mcp-status--${status}">
                ${status === 'online' ? 'Online' : 'Offline'}
              </span>
            </div>

            <div class="jrc-mcp-service-card__tools">
              ${tools.length
                ? tools.map(tool => '<span class="jrc-mcp-tool">' + esc(tool) + '</span>').join('')
                : '<span class="jrc-mcp-tool jrc-mcp-tool--muted">Sem ferramentas listadas</span>'}
            </div>
          </div>
        </article>
      `;
    }).join('');
  }

  function renderConnections(panel, data) {
    const root = panel.querySelector('[data-jrc-mcp-connections]');
    const rows = Array.isArray(data?.connections) ? data.connections : [];

    if (!rows.length) {
      root.innerHTML =
        '<div class="jrc-mcp-hub__empty">Nenhuma conexão registrada ainda. ' +
        'Os clientes aparecerão aqui na próxima comunicação autenticada.</div>';
      return;
    }

    root.innerHTML = `
      <div class="jrc-mcp-connections__table-wrap">
        <table class="jrc-mcp-connections__table">
          <thead>
            <tr>
              <th><i class="bi bi-link-45deg"></i> Conexão</th>
              <th><i class="bi bi-shield-lock"></i> Autenticação</th>
              <th><i class="bi bi-activity"></i> Atividade</th>
              <th><i class="bi bi-clock"></i> Última comunicação</th>
              <th><i class="bi bi-stack"></i> Requisições</th>
            </tr>
          </thead>
          <tbody>
            ${rows.map(row => {
              const lastSeen = Date.parse(row.last_seen || '');
              const isActive = Number.isFinite(lastSeen) &&
                (Date.now() - lastSeen) <= (15 * 60 * 1000);
              const status = isActive ? 'online' : 'offline';
              const statusLabel = isActive ? 'Ativo' : 'Inativo';
              const auth = row.auth_type === 'oauth' ? 'OAuth / Keycloak' :
                row.auth_type === 'token' ? 'Token MCP' : (row.auth_type || '—');
              return `
                <tr>
                  <td class="jrc-mcp-connection-name">
                    <span class="jrc-mcp-row-accent"></span>
                    <div>
                      <strong>${esc(row.name || row.client_id || row.id)}</strong>
                      <div class="jrc-mcp-client-id">${esc(row.client_id || row.id || '')}</div>
                    </div>
                  </td>
                  <td>${esc(auth)}</td>
                  <td>
                    <span class="jrc-mcp-status jrc-mcp-status--${status}">
                      ${statusLabel}
                    </span>
                  </td>
                  <td title="${esc(row.last_seen || '')}">${esc(timeAgo(row.last_seen))}</td>
                  <td class="jrc-mcp-requests">${Number(row.requests || 0).toLocaleString('pt-BR')}</td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;
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

      renderServices(panel, data);
      renderConnections(panel, data);
    } catch (error) {
      const message = esc(error.message || 'Falha ao consultar o MCP.');
      panel.querySelector('[data-jrc-mcp-services]').innerHTML =
        '<div class="jrc-mcp-hub__error">' + message + '</div>';
      panel.querySelector('[data-jrc-mcp-connections]').innerHTML =
        '<div class="jrc-mcp-hub__error">' + message + '</div>';
    }
  }

  function boot() {
    openMcpTabFromUrl();

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
