/* JR CONECT: presentation-only equipment workspace. No network or CPE tasks. */
(() => {
    'use strict';
    if (window.__JR_EQUIPMENT_WORKSPACE__) return;
    if (new URLSearchParams(location.search).get('layout') === 'classic') return;
    const shell = document.querySelector('.acs-device-shell');
    const overview = document.getElementById('overview-content');
    const toolbar = shell?.querySelector('.acs-device-toolbar');
    const tabs = document.getElementById('deviceTabs');
    if (!shell || !overview || !toolbar || !tabs) return;
    window.__JR_EQUIPMENT_WORKSPACE__ = true;

    const body = document.body;
    let mountedGrid = null;
    let assistant = null;
    let frame = 0;
    let savedQuestion = '';
    let savedAnswer = '';
    const q = (selector, root = document) => root.querySelector(selector);
    const text = (value, fallback = 'N\u00e3o informado') => {
        if (value === null || value === undefined || value === '') return fallback;
        const s = String(value);
        return /^(N\/A|N\/D|undefined|null)$/i.test(s.trim()) ? fallback : s;
    };
    const set = (id, value) => {
        const node = document.getElementById(id);
        if (node && node.textContent !== value) node.textContent = value;
    };
    function element(tag, className, html) {
        const node = document.createElement(tag);
        node.className = className;
        if (html) node.innerHTML = html; // Static markup only; device values use textContent.
        return node;
    }
    function notice(message) {
        if (typeof window.showToast === 'function') window.showToast(message, 'info');
    }
    function showTab(id, after) {
        const tab = document.getElementById(id);
        if (!tab || tab.disabled) { notice('A tela ainda est\u00e1 carregando.'); return; }
        if (tab.classList.contains('active')) { if (after) after(); return; }
        if (after) tab.addEventListener('shown.bs.tab', after, { once: true });
        if (window.bootstrap?.Tab) window.bootstrap.Tab.getOrCreateInstance(tab).show();
        else tab.click();
    }
    function jump(selector) {
        showTab('overview-tab', () => {
            const target = q(selector, overview);
            if (!target) return;
            target.scrollIntoView({ block: 'center', behavior: 'auto' });
            target.setAttribute('tabindex', '-1');
            target.focus({ preventScroll: true });
        });
    }

    /* Wrap, do not recreate, the existing toolbar, tabs and form containers. */
    const workspace = element('div', 'jr-eq-workspace');
    const heading = element('div', 'jr-eq-pagehead', `
        <div><nav aria-label="Localiza&ccedil;&atilde;o"><a href="/devices.php">Equipamentos</a><span aria-hidden="true">/</span><span>Detalhes</span></nav>
        <h1><i class="bi bi-router" aria-hidden="true"></i> Detalhes do Equipamento</h1></div>
        <div class="jr-eq-head-actions"><button type="button" class="jr-eq-btn jr-eq-assistant-toggle" aria-expanded="true">Assistente <i class="bi bi-chat-dots" aria-hidden="true"></i></button>
        <details class="jr-eq-actions"><summary>A&ccedil;&otilde;es <i class="bi bi-chevron-down" aria-hidden="true"></i></summary></details></div>`);
    const originalActions = q('.acs-device-toolbar-actions', toolbar);
    if (originalActions) q('.jr-eq-actions', heading).append(originalActions);
    const classic = new URL(location.href);
    classic.searchParams.set('layout', 'classic');
    const classicLink = element('a', 'jr-eq-classic');
    classicLink.href = classic.pathname + classic.search;
    classicLink.textContent = 'Layout anterior';
    q('.jr-eq-actions', heading).append(classicLink);
    shell.before(heading, workspace);
    workspace.append(shell);
    shell.classList.add('jr-eq-main');
    const rail = element('aside', 'jr-eq-assistant', '<div class="jr-eq-assistant-wait">Carregando assistente...</div>');
    rail.setAttribute('aria-label', 'Assistente do equipamento');
    rail.id = 'jr-eq-assistant';
    workspace.append(rail);
    const assistantToggle = q('.jr-eq-assistant-toggle', heading);
    assistantToggle.setAttribute('aria-controls', rail.id);
    assistantToggle.addEventListener('click', () => {
        const hidden = workspace.classList.toggle('jr-eq-rail-hidden');
        assistantToggle.setAttribute('aria-expanded', String(!hidden));
    });
    const meta = element('dl', 'jr-eq-hero-meta', `
        <div><dt>Cliente</dt><dd id="jr-eq-client">N&atilde;o informado</dd></div>
        <div><dt>Contrato</dt><dd id="jr-eq-contract">N&atilde;o informado</dd></div>
        <div><dt>PON</dt><dd id="jr-eq-pon">N&atilde;o informado</dd></div>
        <div><dt>ONU</dt><dd id="jr-eq-onu">N&atilde;o informado</dd></div>`);
    toolbar.append(meta);
    const avatar = q('.acs-device-avatar', toolbar);
    if (avatar) avatar.setAttribute('title', 'Representa\u00e7\u00e3o gen\u00e9rica do equipamento');

    /* Keep the real navigation links. No new customers/tickets/report routes. */
    const sidebar = document.getElementById('sidebar');
    const topbar = q('.topbar');
    if (topbar) {
        const brand = q('h4', topbar);
        if (brand) brand.textContent = 'JRCONECT TELECOM';
        const menu = element('button', 'jr-eq-menu', '<i class="bi bi-list" aria-hidden="true"></i>');
        menu.type = 'button';
        menu.setAttribute('aria-label', 'Expandir ou recolher menu');
        menu.setAttribute('aria-controls', 'sidebar');
        let menuOverride = null;
        const sizing = matchMedia('(min-width: 1500px)');
        function applySidebar() {
            const open = menuOverride === null ? sizing.matches : menuOverride;
            body.classList.toggle('jr-eq-menu-open', open);
            menu.setAttribute('aria-expanded', String(open));
        }
        menu.addEventListener('click', () => { menuOverride = !body.classList.contains('jr-eq-menu-open'); applySidebar(); });
        sizing.addEventListener('change', () => { if (menuOverride === null) applySidebar(); });
        topbar.prepend(menu);
        applySidebar();
        const search = element('form', 'jr-eq-search', '<i class="bi bi-search" aria-hidden="true"></i><input name="search" type="search" placeholder="Localizar Wi-Fi, portas, credenciais..." aria-label="Localizar uma se&ccedil;&atilde;o nesta tela">');
        search.addEventListener('submit', event => {
            event.preventDefault();
            const term = q('input', search).value.trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
            if (!term) return;
            const destinations = [
                ['wifi wi-fi redes unificada', '.acs-approved-wifi'],
                ['optico gpon fibra sinal', '.acs-approved-optical'],
                ['lan portas ethernet', '.acs-approved-lan'],
                ['credenciais senha administrador acesso', '.acs-approved-admin'],
                ['wan internet conexao pppoe', '.acs-approved-wan'],
                ['informacoes dispositivo equipamento modelo', '.acs-approved-device']
            ];
            const match = destinations.find(([words]) => words.includes(term));
            if (match) jump(match[1]);
            else if ('monitoramento trafego'.includes(term)) showTab('monitoring-tab');
            else notice('Se\u00e7\u00e3o n\u00e3o localizada. Use o menu Equipamentos para buscar outro modem.');
        });
        const user = q('.user-info', topbar);
        if (user) user.before(search); else topbar.append(search);
    }
    if (sidebar) {
        sidebar.querySelectorAll('a[data-tooltip]').forEach(a => { if (!a.title) a.title = a.dataset.tooltip; });
        const header = q('.sidebar-header', sidebar);
        if (header) {
            const brand = element('div', 'jr-eq-brand', '<strong>JRCONECT</strong><span>t e l e c o m</span>');
            header.append(brand);
        }
    }

    /* Wi-Fi is a shortcut to the original overview card, not a duplicate pane. */
    const shortcutItem = element('li', 'nav-item jr-eq-wifi-shortcut');
    const shortcut = element('button', 'nav-link jr-eq-btn', '<i class="bi bi-wifi" aria-hidden="true"></i> Wi-Fi');
    shortcut.type = 'button'; shortcut.title = 'Ir para as redes Wi-Fi na Vis\u00e3o Geral';
    shortcut.addEventListener('click', () => jump('.acs-approved-wifi'));
    shortcutItem.append(shortcut);
    const order = ['overview-tab', 'wan-tab', null, 'monitoring-tab', 'ai-tab', 'topology-tab', 'dhcp-tab', 'firmware-tab', 'devices-tab'];
    order.forEach(id => {
        if (!id) tabs.append(shortcutItem);
        else {
            const item = document.getElementById(id)?.closest('li');
            if (item && item.parentNode === tabs) tabs.append(item);
        }
    });

    function bindShortcuts(root) {
        root.addEventListener('click', event => {
            const b = event.target.closest('[data-eq-go]');
            if (!b || !root.contains(b)) return;
            const dest = b.dataset.eqGo;
            if (dest === 'summary') jump('.acs-approved-device');
            else if (dest === 'optical') jump('.acs-approved-optical');
            else if (dest === 'wifi') jump('.acs-approved-wifi');
            else if (dest === 'credentials') jump('.acs-approved-admin');
            else if (dest === 'monitor') showTab('monitoring-tab');
            else if (dest === 'firmware') showTab('firmware-tab');
            else if (dest === 'wan') showTab('wan-tab');
            else if (dest === 'topology') showTab('topology-tab');
            else if (dest === 'speed') document.getElementById('onu-speedtest-btn')?.click();
            else if (dest === 'web' && typeof window.openWebManagement === 'function') window.openWebManagement();
        });
    }
    function mountAssistant(card) {
        if (assistant?.isConnected) { card.remove(); return; }
        assistant = card;
        rail.replaceChildren(card);
        const head = q('.acs-overview-card-header', card);
        if (head) head.innerHTML = '<span class="jr-eq-ai-title"><i class="bi bi-robot" aria-hidden="true"></i> Assistente IA <small>Equipamentos</small></span>';
        const intro = q('.acs-ai-question-only > strong', card);
        if (intro) intro.textContent = 'Como posso ajudar?';
        const sub = q('.acs-ai-question-only > span', card);
        if (sub) sub.textContent = 'Respostas dependem da integra\u00e7\u00e3o de IA configurada.';
        const answer = q('#acs-ai-answer-overview', card);
        if (answer) { answer.setAttribute('aria-live', 'polite'); answer.textContent = savedAnswer; }
        const input = q('#acs-ai-question-overview', card);
        if (input) {
            input.value = savedQuestion;
            input.setAttribute('aria-label', 'Pergunta ao assistente do equipamento');
            input.placeholder = 'Digite sua pergunta...';
            input.addEventListener('input', () => { savedQuestion = input.value; });
        }
        const send = q('.acs-ai-input-row button', card);
        if (send) send.setAttribute('aria-label', 'Enviar pergunta');
        const quick = element('div', 'jr-eq-ai-quick', `
            <p>O que voc&ecirc; gostaria de ver?</p><div>
            <button type="button" data-eq-go="summary"><i class="bi bi-file-text" aria-hidden="true"></i>Resumo</button>
            <button type="button" data-eq-go="optical"><i class="bi bi-reception-4" aria-hidden="true"></i>Sinal &oacute;ptico</button>
            <button type="button" data-eq-go="wifi"><i class="bi bi-wifi" aria-hidden="true"></i>Wi-Fi</button>
            <button type="button" data-eq-go="monitor"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i>Monitoramento</button></div>`);
        if (answer) answer.before(quick); else card.append(quick);
        bindShortcuts(quick);
        const foot = element('div', 'jr-eq-ai-foot', '<strong>JRCONECT <span>telecom</span></strong><p>Gest&atilde;o e monitoramento de equipamentos</p>');
        rail.append(foot);
    }
    function enhanceGrid() {
        const grid = q('.acs-approved-layout', overview);
        if (!grid || grid === mountedGrid) return;
        mountedGrid = grid;
        grid.classList.add('jr-eq-overview');
        const ai = q('.acs-approved-ai', grid);
        if (ai) mountAssistant(ai);
        const tools = element('section', 'jr-eq-tools', `
            <h2><i class="bi bi-activity" aria-hidden="true"></i> Ferramentas do equipamento</h2>
            <button type="button" data-eq-go="speed"><i class="bi bi-speedometer2" aria-hidden="true"></i><span>Teste de velocidade<small>Abrir ferramenta existente</small></span><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            <button type="button" data-eq-go="monitor"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i><span>Monitoramento<small>Tr&aacute;fego e sess&atilde;o</small></span><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            <button type="button" data-eq-go="topology"><i class="bi bi-diagram-3" aria-hidden="true"></i><span>Topologia<small>Localiza&ccedil;&atilde;o na rede</small></span><i class="bi bi-chevron-right" aria-hidden="true"></i></button>`);
        const lan = q('.acs-approved-lan', grid);
        if (lan) lan.append(tools);
        const actions = element('section', 'jr-eq-operation-bar', `
            <h2><i class="bi bi-sliders" aria-hidden="true"></i> Gerenciamento do equipamento</h2><div>
            <button type="button" data-eq-go="wifi"><i class="bi bi-wifi" aria-hidden="true"></i> Alterar Wi-Fi</button>
            <button type="button" data-eq-go="firmware"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Firmware</button>
            <button type="button" data-eq-go="web"><i class="bi bi-display" aria-hidden="true"></i> Acesso remoto</button>
            <button type="button" data-eq-go="credentials"><i class="bi bi-shield-lock" aria-hidden="true"></i> Credenciais</button></div>
            <p>Os controles mant&ecirc;m as permiss&otilde;es e limita&ccedil;&otilde;es atuais do equipamento.</p>`);
        grid.append(actions);
        bindShortcuts(tools); bindShortcuts(actions);
        // These old badges were static strings, not live health measurements.
        const opticalBadge = q('.acs-approved-optical > .acs-overview-card-header .acs-mini-badge', grid);
        if (opticalBadge) { opticalBadge.textContent = '\u00daltima leitura'; opticalBadge.classList.remove('success'); }
    }
    function synchronizeHeader() {
        const optical = typeof cachedOpticalData !== 'undefined' ? cachedOpticalData : null;
        set('jr-eq-client', text(optical?.nome));
        set('jr-eq-contract', text(optical?.id_contrato));
        set('jr-eq-pon', text(optical?.pon_id));
        set('jr-eq-onu', text(optical?.onu_number));
        const device = typeof currentDeviceData !== 'undefined' ? currentDeviceData : null;
        const wans = Array.isArray(device?.wan_details) ? device.wan_details : [];
        const wan = wans.find(w => String(w.status || '').toLowerCase() === 'connected') || wans[0];
        const badge = q('.acs-approved-wan > .acs-overview-card-header .acs-mini-badge', overview);
        if (badge) {
            const s = String(wan?.status || '').toLowerCase();
            const label = s === 'connected' ? 'CONECTADO' : s === 'disconnected' ? 'DESCONECTADO' : 'ESTADO N/D';
            if (badge.textContent !== label) badge.textContent = label;
            badge.classList.toggle('success', s === 'connected');
        }
        savedQuestion = q('#acs-ai-question-overview', rail)?.value || savedQuestion;
        savedAnswer = q('#acs-ai-answer-overview', rail)?.textContent || savedAnswer;
    }
    function apply() {
        frame = 0;
        enhanceGrid();
        synchronizeHeader();
    }
    const observer = new MutationObserver(() => { if (!frame) frame = requestAnimationFrame(apply); });
    function observe() { observer.observe(overview, { childList: true, subtree: true }); }
    body.classList.add('jr-equipment-workspace');
    apply(); observe();
    window.addEventListener('pagehide', () => { observer.disconnect(); if (frame) cancelAnimationFrame(frame); frame = 0; });
    window.addEventListener('pageshow', () => { apply(); observe(); });
})();
