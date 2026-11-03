(() => {
  'use strict';

  if (window.__JRC_MCP_LOCAL_INSTANCES_V11__) return;
  window.__JRC_MCP_LOCAL_INSTANCES_V11__ = true;

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
      document.querySelector('main') ||
      document.body;
  }

  async function parseApiResponse(response, fallbackMessage) {
    const raw = await response.text();
    const contentType = String(response.headers.get('content-type') || '').toLowerCase();
    const trimmed = raw.trim().toLowerCase();

    const looksLikeHtml =
      contentType.includes('text/html') ||
      trimmed.startsWith('<!doctype html') ||
      trimmed.startsWith('<html');

    if (response.status === 401 || response.status === 403 || looksLikeHtml) {
      throw new Error('Sessão expirada. Atualize a página e faça login novamente.');
    }

    let data;
    try {
      data = JSON.parse(raw);
    } catch (_) {
      throw new Error(fallbackMessage || 'Resposta inválida do servidor.');
    }

    if (!response.ok || !data?.success) {
      throw new Error(data?.message || fallbackMessage || 'Falha na comunicação com o servidor.');
    }

    return data;
  }


  function integrationIcon(id) {
    const key = String(id || '').toLowerCase();
    if (key.includes('wiki')) return 'bi-book';
    if (key.includes('opa')) return 'bi-chat-dots';
    if (key.includes('chatgpt')) return 'bi-robot';
    return 'bi-plug';
  }

  function buildPanel() {
    const panel = document.createElement('section');
    panel.id = 'jrc-mcp-connections-panel';
    panel.className = 'jrc-mcp-hub';
    panel.innerHTML = `
      <div class="jrc-mcp-hub__head">
        <div class="jrc-mcp-hub__brand">
          <div class="jrc-mcp-hub__brand-icon"><i class="bi bi-hdd-stack"></i></div>
          <div>
            <div class="jrc-mcp-hub__title">Infraestrutura MCP</div>
            <div class="jrc-mcp-hub__subtitle">
              Servidor MCP local do ACS, integrações conectadas e futuras instâncias.
            </div>
          </div>
        </div>

        <div class="jrc-mcp-hub__actions">
          <button type="button" class="jrc-mcp-hub__add" data-jrc-mcp-add>
            <i class="bi bi-plus-lg"></i>
            <span>Adicionar MCP</span>
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
          Servidor MCP principal
        </div>
        <div data-jrc-mcp-primary>
          <div class="jrc-mcp-hub__loading">Carregando servidor principal...</div>
        </div>
      </div>

      <div class="jrc-mcp-hub__section" data-jrc-mcp-additional-section hidden>
        <div class="jrc-mcp-hub__section-title">
          <span></span>
          Instâncias MCP adicionais
        </div>
        <div class="jrc-mcp-instances-grid" data-jrc-mcp-additional></div>
      </div>

      <div class="jrc-mcp-modal" data-jrc-mcp-credentials-modal hidden>
        <div class="jrc-mcp-modal__backdrop" data-jrc-mcp-credentials-close></div>
        <div class="jrc-mcp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="jrc-mcp-credentials-title">
          <div class="jrc-mcp-modal__head">
            <div id="jrc-mcp-credentials-title" class="jrc-mcp-modal__title">Configurar credenciais OAuth2</div>
            <button type="button" class="jrc-mcp-modal__close" data-jrc-mcp-credentials-close aria-label="Fechar"><i class="bi bi-x-lg"></i></button>
          </div>
          <form class="jrc-mcp-modal__form" data-jrc-mcp-credentials-form autocomplete="off">
            <input name="id" type="hidden">
            <label><span>Client ID do IXC</span><input name="client_id" type="text" autocomplete="off" maxlength="512" required></label>
            <label><span>Client Secret do IXC</span><input name="client_secret" type="password" autocomplete="new-password" maxlength="2048" required></label>
            <p>Os dados são enviados pelo painel autenticado e armazenados fora da pasta pública. Salvar não inicia a autorização OAuth2.</p>
            <div class="jrc-mcp-modal__message" data-jrc-mcp-credentials-message></div>
            <div class="jrc-mcp-modal__footer">
              <button type="button" data-jrc-mcp-credentials-close>Cancelar</button>
              <button type="submit" class="jrc-mcp-modal__save">Salvar credenciais</button>
            </div>
          </form>
        </div>
      </div>
      <div class="jrc-mcp-modal" data-jrc-mcp-modal hidden>
        <div class="jrc-mcp-modal__backdrop" data-jrc-mcp-close></div>
        <div class="jrc-mcp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="jrc-mcp-modal-title">
          <div class="jrc-mcp-modal__head">
            <div>
              <div id="jrc-mcp-modal-title" class="jrc-mcp-modal__title">Adicionar instância MCP</div>
              <div class="jrc-mcp-modal__subtitle">
                Planeje uma nova instância MCP no próprio servidor ACS.
              </div>
            </div>
            <button type="button" class="jrc-mcp-modal__close" data-jrc-mcp-close aria-label="Fechar">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <form class="jrc-mcp-modal__form" data-jrc-mcp-form>
            <label>
              <span>Tipo de conexão</span>
              <select name="type" data-jrc-mcp-type>
                <option value="local">Servidor MCP local</option>
                <option value="external">Conectar MCP externo (OAuth2)</option>
              </select>
            </label>
            <div class="jrc-mcp-form-grid" data-jrc-mcp-local-fields>
              <label>
                <span>Nome da instância</span>
                <input name="name" type="text" maxlength="100" required placeholder="Ex.: MCP Financeiro">
              </label>

              <label>
                <span>Container Docker</span>
                <input name="container" type="text" maxlength="80" required placeholder="jrconect-mcp-financeiro">
              </label>

              <label>
                <span>Porta local</span>
                <input name="port" type="number" min="1024" max="65535" required placeholder="3200">
              </label>

              <label>
                <span>Endpoint público</span>
                <input name="endpoint" type="url" maxlength="500" required placeholder="https://mcp-financeiro.jrconect.com/mcp">
              </label>
            </div>
            <div data-jrc-mcp-external-fields hidden>
              <label>
                <span>Nome da conexão</span>
                <input name="external_name" type="text" maxlength="100" placeholder="IXC Provedor JR CONECT">
              </label>
              <label>
                <span>Endpoint HTTPS do MCP externo</span>
                <input name="external_endpoint" type="url" maxlength="500" placeholder="https://cda.jrconect.com/services/mcp">
              </label>
              <p>Autenticação OAuth2. Este cadastro não solicita nem armazena Client ID, Client Secret ou tokens; a conexão será configurada separadamente.</p>
            </div>

            <label>
              <span>Descrição</span>
              <textarea name="description" maxlength="240" rows="3" placeholder="Finalidade dessa instância MCP"></textarea>
            </label>

            <div class="jrc-mcp-modal__note">
              <i class="bi bi-info-circle"></i>
              O cadastro não inicia conexões nem cria containers automaticamente. A instância ficará como
              <strong>Cadastrada</strong> até ser implantada.
            </div>

            <div class="jrc-mcp-modal__message" data-jrc-mcp-form-message></div>

            <div class="jrc-mcp-modal__footer">
              <button type="button" class="jrc-mcp-modal__cancel" data-jrc-mcp-close>Cancelar</button>
              <button type="submit" class="jrc-mcp-modal__save">
                <i class="bi bi-check-lg"></i>
                Salvar MCP
              </button>
            </div>
          </form>
        </div>
      </div>
    `;
    return panel;
  }

  function buildIntegration(serviceOrConnection, kind) {
    const isService = kind === 'service';
    const id = serviceOrConnection.id || serviceOrConnection.client_id || '';
    const name = serviceOrConnection.name || serviceOrConnection.client_id || id || 'Integração';
    const status = isService
      ? (serviceOrConnection.status === 'online' ? 'online' : 'offline')
      : 'online';
    const statusLabel = isService
      ? (serviceOrConnection.status === 'online' ? 'Online' : 'Offline')
      : 'Conectado';

    let subtitle = '';
    let chips = [];

    if (isService) {
      subtitle = String(id).toLowerCase().includes('wiki')
        ? 'Integração interna do servidor MCP'
        : (serviceOrConnection.type || 'Componente interno');

      const tools = Array.isArray(serviceOrConnection.tools) ? serviceOrConnection.tools : [];
      chips = tools.map(tool => esc(tool));
    } else {
      const auth = serviceOrConnection.auth_type === 'oauth'
        ? 'OAuth / Keycloak'
        : serviceOrConnection.auth_type === 'token'
          ? 'Token MCP'
          : (serviceOrConnection.auth_type || 'Autenticação MCP');

      subtitle = 'Cliente externo conectado ao JR Conect MCPv2';
      chips = [
        auth,
        'Última comunicação: ' + timeAgo(serviceOrConnection.last_seen),
        Number(serviceOrConnection.requests || 0).toLocaleString('pt-BR') + ' req.'
      ];
    }

    return `
      <article class="jrc-mcp-integration-card">
        <div class="jrc-mcp-integration-card__icon">
          <i class="bi ${integrationIcon(id)}"></i>
        </div>
        <div class="jrc-mcp-integration-card__body">
          <div class="jrc-mcp-integration-card__top">
            <div>
              <div class="jrc-mcp-integration-card__name">${esc(name)}</div>
              <div class="jrc-mcp-integration-card__subtitle">${esc(subtitle)}</div>
            </div>
            <span class="jrc-mcp-status jrc-mcp-status--${status}">${esc(statusLabel)}</span>
          </div>
          <div class="jrc-mcp-integration-card__chips">
            ${chips.length
              ? chips.map(chip => '<span class="jrc-mcp-tool">' + esc(chip) + '</span>').join('')
              : '<span class="jrc-mcp-tool jrc-mcp-tool--muted">Sem detalhes adicionais</span>'}
          </div>
        </div>
      </article>
    `;
  }

  function renderPrimary(panel, statusData) {
    const root = panel.querySelector('[data-jrc-mcp-primary]');
    const services = Array.isArray(statusData?.services) ? statusData.services : [];
    const connections = Array.isArray(statusData?.connections) ? statusData.connections : [];

    const core = services.find(service => String(service?.id || '').toLowerCase() === 'mcp-core') || {
      id: 'mcp-core',
      name: 'JR Conect MCPv2',
      status: 'offline',
      tools: [],
    };

    const integrations = [];

    services
      .filter(service => service !== core)
      .forEach(service => integrations.push(buildIntegration(service, 'service')));

    const orderedConnections = [...connections].sort((a, b) => {
      const weight = value => {
        const id = String(value?.id || value?.client_id || '').toLowerCase();
        if (id.includes('opa')) return 0;
        if (id.includes('chatgpt')) return 1;
        return 2;
      };
      return weight(a) - weight(b);
    });

    orderedConnections.forEach(connection => {
      integrations.push(buildIntegration(connection, 'connection'));
    });

    const coreStatus = core.status === 'online' ? 'online' : 'offline';
    const coreLabel = coreStatus === 'online' ? 'Online' : 'Offline';

    root.innerHTML = `
      <article class="jrc-mcp-primary-card">
        <div class="jrc-mcp-primary-card__header">
          <div class="jrc-mcp-primary-card__identity">
            <div class="jrc-mcp-primary-card__icon"><i class="bi bi-server"></i></div>
            <div>
              <div class="jrc-mcp-primary-card__name">JR Conect MCPv2</div>
              <div class="jrc-mcp-primary-card__subtitle">Servidor MCP local hospedado no servidor ACS</div>
            </div>
          </div>
          <span class="jrc-mcp-status jrc-mcp-status--${coreStatus}">${coreLabel}</span>
        </div>

        <div class="jrc-mcp-primary-card__meta">
          <div>
            <span>Container</span>
            <strong>jrconect-mcp</strong>
          </div>
          <div>
            <span>Porta local</span>
            <strong>127.0.0.1:3100</strong>
          </div>
          <div>
            <span>Endpoint</span>
            <strong>mcp.jrconect.com/mcp</strong>
          </div>
        </div>

        <div class="jrc-mcp-primary-card__tools">
          ${Array.isArray(core.tools) && core.tools.length
            ? core.tools.map(tool => '<span class="jrc-mcp-tool">' + esc(tool) + '</span>').join('')
            : '<span class="jrc-mcp-tool jrc-mcp-tool--muted">Nenhuma ferramenta principal informada</span>'}
        </div>

        <div class="jrc-mcp-primary-card__integrations-head">
          <span>Integrações deste servidor</span>
          <small>${integrations.length} conectada(s)</small>
        </div>

        <div class="jrc-mcp-integrations-grid">
          ${integrations.length
            ? integrations.join('')
            : '<div class="jrc-mcp-hub__empty">Nenhuma integração conectada.</div>'}
        </div>
      </article>
    `;
  }

  function renderAdditional(panel, instances) {
    const section = panel.querySelector('[data-jrc-mcp-additional-section]');
    const root = panel.querySelector('[data-jrc-mcp-additional]');
    const rows = Array.isArray(instances) ? instances : [];

    if (!rows.length) {
      section.hidden = true;
      root.innerHTML = '';
      return;
    }

    section.hidden = false;
    root.innerHTML = rows.map(instance => `
      <article class="jrc-mcp-instance-card">
        <div class="jrc-mcp-instance-card__header">
          <div class="jrc-mcp-instance-card__identity">
            <div class="jrc-mcp-instance-card__icon"><i class="bi bi-server"></i></div>
            <div>
              <div class="jrc-mcp-instance-card__name">${esc(instance.name || 'Instância MCP')}</div>
              <div class="jrc-mcp-instance-card__subtitle">${esc(instance.description || 'Instância MCP local planejada')}</div>
            </div>
          </div>
          <span class="jrc-mcp-status jrc-mcp-status--registered">${instance.type === 'external' ? 'Externo cadastrado' : 'Cadastrado'}</span>
        </div>

        <div class="jrc-mcp-instance-card__meta">
          <span><i class="bi bi-box"></i> ${esc(instance.type === 'external' ? 'OAuth2' : (instance.container || '—'))}</span>
          <span><i class="bi bi-ethernet"></i> ${esc(instance.type === 'external' ? 'Sem conexão ativa' : (instance.local_address || ('127.0.0.1:' + (instance.port || '—'))))}</span>
          <span><i class="bi bi-link-45deg"></i> ${esc(instance.endpoint || '—')}</span>
        </div>
        ${instance.type === 'external' ? `<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px;">
          <button type="button" class="jrc-mcp-hub__refresh" style="display:inline-flex;align-items:center;width:auto;min-height:26px;padding:4px 9px;font-size:11px;line-height:1.2;" data-jrc-mcp-credentials="${esc(instance.id)}">Configurar OAuth2</button>
          <button type="button" class="jrc-mcp-hub__refresh" style="display:inline-flex;align-items:center;width:auto;min-height:26px;padding:4px 9px;font-size:11px;line-height:1.2;" data-jrc-mcp-authorize="${esc(instance.id)}" ${instance.credentials_saved ? '' : 'disabled'}>Autorizar IXC</button>
          <button type="button" class="jrc-mcp-hub__refresh" style="display:inline-flex;align-items:center;width:auto;min-height:26px;padding:4px 9px;font-size:11px;line-height:1.2;" data-jrc-mcp-test="${esc(instance.id)}" ${instance.credentials_saved ? '' : 'disabled'}>Testar conexão</button>
          <small>${instance.credentials_saved ? 'Credenciais salvas — autorização a confirmar' : 'Credenciais pendentes'}</small>
        </div>` : ''}
      </article>
    `).join('');
  }

  async function load(panel) {
    const primary = panel.querySelector('[data-jrc-mcp-primary]');

    try {
      const [statusResponse, instancesResponse] = await Promise.all([
        fetch('/api/mcp-connections.php', {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' }
        }),
        fetch('/api/mcp-instances.php', {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' }
        })
      ]);

      const statusData = await parseApiResponse(
        statusResponse,
        'Não foi possível consultar o JR Conect MCPv2.'
      );

      const instancesData = await parseApiResponse(
        instancesResponse,
        'Não foi possível consultar instâncias MCP adicionais.'
      );

      renderPrimary(panel, statusData);
      renderAdditional(panel, instancesData.instances);
    } catch (error) {
      primary.innerHTML =
        '<div class="jrc-mcp-hub__error">' +
        esc(error.message || 'Falha ao carregar infraestrutura MCP.') +
        '</div>';
    }
  }

  function openModal(panel) {
    const modal = panel.querySelector('[data-jrc-mcp-modal]');
    const form = panel.querySelector('[data-jrc-mcp-form]');
    const message = panel.querySelector('[data-jrc-mcp-form-message]');

    if (!modal || !form) return;

    form.reset();
    form.querySelector('[data-jrc-mcp-type]')?.dispatchEvent(new Event('change'));
    if (message) {
      message.textContent = '';
      message.className = 'jrc-mcp-modal__message';
    }

    modal.hidden = false;
    document.body.classList.add('jrc-mcp-modal-open');

    setTimeout(() => form.querySelector('input[name="name"]')?.focus(), 0);
  }

  function closeModal(panel) {
    const modal = panel.querySelector('[data-jrc-mcp-modal]');
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('jrc-mcp-modal-open');
  }

  async function saveInstance(panel, form) {
    const message = panel.querySelector('[data-jrc-mcp-form-message]');
    const submit = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);

    const isExternal = formData.get('type') === 'external';
    const payload = {
      type: isExternal ? 'external' : 'local',
      name: String(formData.get(isExternal ? 'external_name' : 'name') || '').trim(),
      container: String(formData.get('container') || '').trim(),
      port: String(formData.get('port') || '').trim(),
      endpoint: String(formData.get(isExternal ? 'external_endpoint' : 'endpoint') || '').trim(),
      ...(isExternal ? { auth_type: 'oauth2' } : {}),
      description: String(formData.get('description') || '').trim(),
    };

    if (message) {
      message.className = 'jrc-mcp-modal__message';
      message.textContent = 'Salvando...';
    }
    if (submit) submit.disabled = true;

    try {
      const response = await fetch('/api/mcp-instances.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': String(window.JRC_MCP_INSTANCE_CSRF || '')
        },
        body: JSON.stringify(payload)
      });

      const data = await parseApiResponse(
        response,
        'Não foi possível cadastrar a instância MCP.'
      );

      closeModal(panel);
      await load(panel);
    } catch (error) {
      if (message) {
        message.className = 'jrc-mcp-modal__message jrc-mcp-modal__message--error';
        message.textContent = error.message || 'Falha ao cadastrar MCP.';
      }
    } finally {
      if (submit) submit.disabled = false;
    }
  }

  function credentialsModal(panel, id) {
    const modal = panel.querySelector('[data-jrc-mcp-credentials-modal]');
    const form = modal.querySelector('form');
    form.reset();
    form.elements.id.value = id;
    modal.querySelector('[data-jrc-mcp-credentials-message]').textContent = '';
    modal.hidden = false;
    document.body.classList.add('jrc-mcp-modal-open');
  }

  async function saveCredentials(panel, form) {
    const message = panel.querySelector('[data-jrc-mcp-credentials-message]');
    const submit = form.querySelector('button[type="submit"]');
    submit.disabled = true;
    message.textContent = 'Salvando...';
    try {
      const response = await fetch('/api/mcp-instances.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': String(window.JRC_MCP_INSTANCE_CSRF || '')
        },
        body: JSON.stringify({
          action: 'set_external_credentials',
          id: form.elements.id.value,
          client_id: form.elements.client_id.value,
          client_secret: form.elements.client_secret.value
        })
      });
      await parseApiResponse(response, 'Não foi possível salvar as credenciais.');
      form.reset();
      panel.querySelector('[data-jrc-mcp-credentials-modal]').hidden = true;
      document.body.classList.remove('jrc-mcp-modal-open');
      await load(panel);
    } catch (error) {
      message.textContent = error.message || 'Falha ao salvar.';
    } finally {
      submit.disabled = false;
    }
  }

  function boot() {
    const old = document.getElementById('jrc-mcp-connections-panel');
    if (old) old.remove();

    const target = findTarget();
    const panel = buildPanel();
    target.appendChild(panel);

    const typeSelect = panel.querySelector('[data-jrc-mcp-type]');
    function syncType() {
      const isExternal = typeSelect?.value === 'external';
      const local = panel.querySelector('[data-jrc-mcp-local-fields]');
      const external = panel.querySelector('[data-jrc-mcp-external-fields]');
      if (local) {
        local.hidden = isExternal;
        local.querySelectorAll('input').forEach(input => { input.disabled = isExternal; });
      }
      if (external) {
        external.hidden = !isExternal;
        external.querySelectorAll('input').forEach(input => { input.disabled = !isExternal; });
      }
    }
    typeSelect?.addEventListener('change', syncType);
    syncType();
    panel.addEventListener('click', async event => {
      const authorize = event.target.closest('[data-jrc-mcp-authorize]');
      if (authorize) {
        const id = authorize.getAttribute('data-jrc-mcp-authorize');
        try {
          const resp = await fetch('/api/mcp-ixc-oauth.php?action=status&id=' + encodeURIComponent(id), {credentials:'same-origin'});
          const status = await parseApiResponse(resp, 'Falha ao consultar OAuth2.');
          const message = 'Cadastre esta Redirect URI EXATA no OAuth Server do IXC antes de continuar:\\n\\n' + status.redirect_uri + '\\n\\nDeseja abrir a autorização agora?';
          if (window.confirm(message)) {
            window.location.assign('/api/mcp-ixc-oauth.php?action=start&id=' + encodeURIComponent(id) + '&return=' + encodeURIComponent(window.location.pathname));
          }
        } catch (err) {
          window.alert(err.message || 'Falha na autorização.');
        }
      }
      const test = event.target.closest('[data-jrc-mcp-test]');
      if (test) {
        test.disabled = true;
        try {
          const id = test.getAttribute('data-jrc-mcp-test');
          const response = await fetch('/api/mcp-ixc-oauth.php?action=test&id=' + encodeURIComponent(id), {
            method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':String(window.JRC_MCP_INSTANCE_CSRF || ''),'Accept':'application/json'}
          });
          const result = await parseApiResponse(response, 'Não foi possível testar o MCP do IXC.');
          window.alert('IXC conectado. Ferramentas encontradas: ' + result.tool_count + '\\n' + (result.tools || []).join(', '));
        } catch(err) {
          window.alert(err.message || 'Falha no teste.');
        } finally { test.disabled = false; }
      }
      const target = event.target.closest('[data-jrc-mcp-credentials]');
      if (target) credentialsModal(panel, target.getAttribute('data-jrc-mcp-credentials'));
      if (event.target.closest('[data-jrc-mcp-credentials-close]')) {
        const form = panel.querySelector('[data-jrc-mcp-credentials-form]');
        form.reset();
        panel.querySelector('[data-jrc-mcp-credentials-modal]').hidden = true;
        document.body.classList.remove('jrc-mcp-modal-open');
      }
    });
    panel.querySelector('[data-jrc-mcp-credentials-form]')?.addEventListener('submit', event => {
      event.preventDefault();
      saveCredentials(panel, event.currentTarget);
    });
    panel.querySelector('[data-jrc-mcp-refresh]')?.addEventListener('click', () => load(panel));
    panel.querySelector('[data-jrc-mcp-add]')?.addEventListener('click', () => openModal(panel));

    panel.querySelectorAll('[data-jrc-mcp-close]').forEach(element => {
      element.addEventListener('click', () => closeModal(panel));
    });

    panel.querySelector('[data-jrc-mcp-form]')?.addEventListener('submit', event => {
      event.preventDefault();
      saveInstance(panel, event.currentTarget);
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
