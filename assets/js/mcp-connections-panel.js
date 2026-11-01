(() => {
  'use strict';

  if (window.__JRC_MCP_ADD_SERVER_V10__) return;
  window.__JRC_MCP_ADD_SERVER_V10__ = true;

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
    if (item?.kind === 'manual-server') return 'bi-server';
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
              Visão consolidada dos servidores MCP, integrações e clientes conectados.
            </div>
          </div>
        </div>

        <div class="jrc-mcp-hub__actions">
          <button type="button" class="jrc-mcp-hub__add" data-jrc-mcp-add>
            <i class="bi bi-plus-lg"></i>
            <span>Adicionar servidor</span>
          </button>
          <button type="button" class="jrc-mcp-hub__refresh" data-jrc-mcp-refresh>
            <i class="bi bi-arrow-repeat"></i>
            <span>Atualizar</span>
          </button>
        </div>
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

      <div class="jrc-mcp-modal" data-jrc-mcp-modal hidden>
        <div class="jrc-mcp-modal__backdrop" data-jrc-mcp-close></div>
        <div class="jrc-mcp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="jrc-mcp-modal-title">
          <div class="jrc-mcp-modal__head">
            <div>
              <div id="jrc-mcp-modal-title" class="jrc-mcp-modal__title">Adicionar servidor MCP</div>
              <div class="jrc-mcp-modal__subtitle">Cadastre outro servidor para aparecer nesta central.</div>
            </div>
            <button type="button" class="jrc-mcp-modal__close" data-jrc-mcp-close aria-label="Fechar">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <form class="jrc-mcp-modal__form" data-jrc-mcp-form>
            <label>
              <span>Nome do servidor</span>
              <input name="name" type="text" maxlength="100" required placeholder="Ex.: MCP Financeiro">
            </label>

            <label>
              <span>Endpoint MCP</span>
              <input name="endpoint" type="url" maxlength="500" required placeholder="https://mcp.exemplo.com/mcp">
            </label>

            <label>
              <span>Descrição</span>
              <textarea name="description" maxlength="240" rows="3" placeholder="Opcional"></textarea>
            </label>

            <div class="jrc-mcp-modal__message" data-jrc-mcp-form-message></div>

            <div class="jrc-mcp-modal__footer">
              <button type="button" class="jrc-mcp-modal__cancel" data-jrc-mcp-close>Cancelar</button>
              <button type="submit" class="jrc-mcp-modal__save">
                <i class="bi bi-check-lg"></i>
                Salvar servidor
              </button>
            </div>
          </form>
        </div>
      </div>
    `;
    return panel;
  }

  function renderUnified(panel, data, manualServers) {
    const root = panel.querySelector('[data-jrc-mcp-unified]');
    const services = Array.isArray(data?.services) ? data.services : [];
    const connections = Array.isArray(data?.connections) ? data.connections : [];
    const registered = Array.isArray(manualServers) ? manualServers : [];

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

    registered.forEach(server => {
      items.push({
        kind: 'manual-server',
        id: server.id,
        name: server.name || server.endpoint || 'Servidor MCP',
        type: server.description || server.type || 'Servidor MCP cadastrado',
        status: 'registered',
        statusLabel: 'Cadastrado',
        endpoint: server.endpoint || '',
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
        type: 'Cliente externo do JR Conect MCPv2',
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
      const isManual = item.kind === 'manual-server';

      let detailChips = '';

      if (isClient) {
        detailChips = [
          '<span class="jrc-mcp-tool"><i class="bi bi-diagram-3"></i> via JR Conect MCPv2</span>',
          '<span class="jrc-mcp-tool"><i class="bi bi-shield-lock"></i> ' + esc(item.auth) + '</span>',
          '<span class="jrc-mcp-tool"><i class="bi bi-clock"></i> ' + esc(timeAgo(item.lastSeen)) + '</span>',
          '<span class="jrc-mcp-tool"><i class="bi bi-stack"></i> ' + esc(item.requests.toLocaleString('pt-BR')) + ' req.</span>'
        ].join('');
      } else if (isManual) {
        detailChips = item.endpoint
          ? '<span class="jrc-mcp-tool"><i class="bi bi-link-45deg"></i> ' + esc(item.endpoint) + '</span>'
          : '<span class="jrc-mcp-tool jrc-mcp-tool--muted">Endpoint não informado</span>';
      } else {
        detailChips = item.tools.length
          ? item.tools.map(tool => '<span class="jrc-mcp-tool">' + esc(tool) + '</span>').join('')
          : '<span class="jrc-mcp-tool jrc-mcp-tool--muted">Sem ferramentas listadas</span>';
      }

      const clientId = isClient && item.client_id
        ? '<div class="jrc-mcp-service-card__client-id">' + esc(item.client_id) + '</div>'
        : '';

      return `
        <article class="jrc-mcp-service-card ${isClient ? 'jrc-mcp-service-card--client' : ''} ${isManual ? 'jrc-mcp-service-card--manual' : ''}">
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
    const root = panel.querySelector('[data-jrc-mcp-unified]');

    try {
      const [statusResponse, serversResponse] = await Promise.all([
        fetch('/api/mcp-connections.php', {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' }
        }),
        fetch('/api/mcp-servers.php', {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' }
        })
      ]);

      const statusData = await statusResponse.json();
      const serversData = await serversResponse.json();

      if (!statusResponse.ok || !statusData?.success) {
        throw new Error(statusData?.message || 'Não foi possível consultar o MCP.');
      }

      if (!serversResponse.ok || !serversData?.success) {
        throw new Error(serversData?.message || 'Não foi possível consultar servidores cadastrados.');
      }

      renderUnified(panel, statusData, serversData.servers);
    } catch (error) {
      root.innerHTML =
        '<div class="jrc-mcp-hub__error">' +
        esc(error.message || 'Falha ao consultar MCPs e integrações.') +
        '</div>';
    }
  }

  function openModal(panel) {
    const modal = panel.querySelector('[data-jrc-mcp-modal]');
    const form = panel.querySelector('[data-jrc-mcp-form]');
    const message = panel.querySelector('[data-jrc-mcp-form-message]');
    if (!modal || !form) return;

    form.reset();
    if (message) message.textContent = '';
    modal.hidden = false;
    document.body.classList.add('jrc-mcp-modal-open');

    setTimeout(() => {
      form.querySelector('input[name="name"]')?.focus();
    }, 0);
  }

  function closeModal(panel) {
    const modal = panel.querySelector('[data-jrc-mcp-modal]');
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('jrc-mcp-modal-open');
  }

  async function saveServer(panel, form) {
    const message = panel.querySelector('[data-jrc-mcp-form-message]');
    const submit = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);

    const payload = {
      name: String(formData.get('name') || '').trim(),
      endpoint: String(formData.get('endpoint') || '').trim(),
      description: String(formData.get('description') || '').trim(),
    };

    if (message) {
      message.className = 'jrc-mcp-modal__message';
      message.textContent = 'Salvando...';
    }
    if (submit) submit.disabled = true;

    try {
      const response = await fetch('/api/mcp-servers.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': String(window.JRC_MCP_SERVER_CSRF || '')
        },
        body: JSON.stringify(payload)
      });

      const data = await response.json();
      if (!response.ok || !data?.success) {
        throw new Error(data?.message || 'Não foi possível salvar o servidor.');
      }

      closeModal(panel);
      await load(panel);
    } catch (error) {
      if (message) {
        message.className = 'jrc-mcp-modal__message jrc-mcp-modal__message--error';
        message.textContent = error.message || 'Falha ao salvar servidor.';
      }
    } finally {
      if (submit) submit.disabled = false;
    }
  }

  function boot() {
    const old = document.getElementById('jrc-mcp-connections-panel');
    if (old) old.remove();

    const target = findTarget();
    const panel = buildPanel();
    target.appendChild(panel);

    panel.querySelector('[data-jrc-mcp-refresh]')?.addEventListener('click', () => load(panel));
    panel.querySelector('[data-jrc-mcp-add]')?.addEventListener('click', () => openModal(panel));
    panel.querySelectorAll('[data-jrc-mcp-close]').forEach(el => {
      el.addEventListener('click', () => closeModal(panel));
    });

    panel.querySelector('[data-jrc-mcp-form]')?.addEventListener('submit', event => {
      event.preventDefault();
      saveServer(panel, event.currentTarget);
    });

    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeModal(panel);
    });

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
