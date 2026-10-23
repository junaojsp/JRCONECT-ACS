(() => {
    'use strict';

    const ACTION_TAB_ID = 'actions-tab';
    const ACTION_PANEL_ID = 'actions';
    const ACTION_CONTENT_ID = 'actions-content';
    let historyTimer = null;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }

    function toast(message, type='info') {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type, 4500);
            return;
        }
        console.log('[DEVICE ACTIONS]', message);
    }

    async function apiAction(action, extra={}) {
        const response = await fetch('/api/device-actions.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Content-Type':'application/json','Accept':'application/json'},
            body: JSON.stringify(Object.assign({
                device_id: window.DEVICE_ID || '',
                action
            }, extra))
        });
        const raw = await response.text();
        let data = null;
        try { data = JSON.parse(raw); } catch (_) {}
        if (!response.ok || !data?.success) {
            throw new Error(data?.message || 'Falha ao executar a ação.');
        }
        return data;
    }

    function setBusy(name, busy) {
        const btn = document.querySelector('[data-device-action="' + name + '"]');
        if (!btn) return;
        btn.disabled = !!busy;
        btn.classList.toggle('is-busy', !!busy);
    }

    async function refreshReadings() {
        setBusy('refresh', true);
        try {
            const data = await apiAction('refresh');
            toast(data.message || 'Atualização solicitada.', 'success');
            setTimeout(() => {
                if (typeof window.loadDeviceDetail === 'function') window.loadDeviceDetail(true);
                loadHistory();
            }, 3500);
        } catch (error) {
            toast(error.message || 'Falha ao atualizar leitura.', 'danger');
        } finally {
            setBusy('refresh', false);
        }
    }

    function confirmReboot() {
        const modalEl = document.getElementById('jrcDeviceRebootModal');
        if (!modalEl || typeof bootstrap === 'undefined') return;
        const label = document.getElementById('jrc-reboot-device-label');
        if (label) label.textContent = window.DEVICE_ID || 'equipamento';
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    async function rebootDevice() {
        const confirmInput = document.getElementById('jrc-reboot-confirm');
        const confirmValue = (confirmInput?.value || '').trim().toUpperCase();
        if (confirmValue !== 'REINICIAR') {
            toast('Digite REINICIAR para confirmar.', 'warning');
            return;
        }

        setBusy('reboot', true);
        const submit = document.getElementById('jrc-reboot-submit');
        if (submit) submit.disabled = true;

        try {
            const data = await apiAction('reboot', {confirm:'REINICIAR'});
            const modalEl = document.getElementById('jrcDeviceRebootModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getInstance(modalEl)?.hide();
            }
            if (confirmInput) confirmInput.value = '';
            toast(data.message || 'Reinício enviado.', 'success');
            loadHistory();
        } catch (error) {
            toast(error.message || 'Falha ao reiniciar equipamento.', 'danger');
        } finally {
            setBusy('reboot', false);
            if (submit) submit.disabled = false;
        }
    }

    function historyView(data) {
        const entries = Array.isArray(data?.entries) ? data.entries : [];
        if (!entries.length) {
            return '<div class="jrc-actions-empty">Nenhuma ação registrada para este equipamento.</div>';
        }

        const statusMap = {
            submitted:['Enviado','neutral'],
            queued:['Aguardando ONU','warning'],
            completed:['Concluído','success'],
            failed:['Falhou','danger'],
            unconfirmed:['Sem confirmação','neutral']
        };
        const actionMap = {
            refresh:'Atualizar leitura',
            summon:'Solicitar comunicação',
            reboot:'Reiniciar equipamento',
            change:'Alterar configuração',
            command:'Comando'
        };

        return '<div class="table-responsive"><table class="table table-sm jrc-actions-history-table">' +
            '<thead><tr><th>Horário</th><th>Responsável</th><th>Ação</th><th>Resultado</th></tr></thead><tbody>' +
            entries.map(entry => {
                const st = statusMap[entry.status] || statusMap.unconfirmed;
                const dt = new Date(entry.created_at);
                const when = Number.isNaN(dt.getTime()) ? 'Indisponível' : dt.toLocaleString('pt-BR');
                return '<tr>' +
                    '<td>' + escapeHtml(when) + '</td>' +
                    '<td>' + escapeHtml(entry.actor || '—') + '</td>' +
                    '<td><strong>' + escapeHtml(actionMap[entry.action] || 'Comando') + '</strong>' +
                    (entry.fields ? '<small>' + escapeHtml(entry.fields) + '</small>' : '') + '</td>' +
                    '<td><span class="jrc-action-status ' + st[1] + '">' + st[0] + '</span></td>' +
                    '</tr>';
            }).join('') +
            '</tbody></table></div>';
    }

    async function loadHistory() {
        const holder = document.getElementById('jrc-actions-history');
        const deviceId = window.DEVICE_ID || '';
        if (!holder || !deviceId) return;

        try {
            const response = await fetch('/api/get-device-action-history.php?device_id=' + encodeURIComponent(deviceId), {
                credentials:'same-origin',
                cache:'no-store',
                headers:{'Accept':'application/json'}
            });
            const data = await response.json();
            if (!response.ok || !data?.success) throw new Error();
            holder.innerHTML = historyView(data);
        } catch (_) {
            holder.innerHTML = '<div class="jrc-actions-empty">Não foi possível carregar o histórico de ações.</div>';
        }
    }

    function ensureTab() {
        const tabs = document.getElementById('deviceTabs');
        const content = document.getElementById('deviceTabContent');
        if (!tabs || !content) return;

        if (!document.getElementById(ACTION_TAB_ID)) {
            const li = document.createElement('li');
            li.className = 'nav-item';
            li.setAttribute('role','presentation');
            li.innerHTML =
                '<button class="nav-link" id="' + ACTION_TAB_ID + '" data-bs-toggle="tab" data-bs-target="#' + ACTION_PANEL_ID + '"' +
                ' type="button" role="tab" aria-controls="' + ACTION_PANEL_ID + '" aria-selected="false">' +
                '<i class="bi bi-lightning-charge"></i> Ações</button>';

            const diagnostics = document.getElementById('diagnostics-tab')?.closest('li');
            if (diagnostics) tabs.insertBefore(li, diagnostics);
            else tabs.appendChild(li);
        }

        if (!document.getElementById(ACTION_PANEL_ID)) {
            const panel = document.createElement('div');
            panel.className = 'tab-pane fade';
            panel.id = ACTION_PANEL_ID;
            panel.setAttribute('role','tabpanel');
            panel.setAttribute('aria-labelledby', ACTION_TAB_ID);
            panel.innerHTML =
                '<div id="' + ACTION_CONTENT_ID + '" class="jrc-actions-page">' +
                    '<div class="jrc-actions-header">' +
                        '<div><span class="acs-kicker"><i class="bi bi-lightning-charge"></i> Operação</span>' +
                        '<h4>Ações do equipamento</h4>' +
                        '<p>Comandos enviados pelo GenieACS com auditoria e acompanhamento de status.</p></div>' +
                    '</div>' +
                    '<div class="jrc-actions-grid">' +
                        '<section class="jrc-action-card safe">' +
                            '<div class="jrc-action-icon"><i class="bi bi-arrow-repeat"></i></div>' +
                            '<div class="jrc-action-copy"><span>LEITURA</span><h5>Atualizar parâmetros</h5>' +
                            '<p>Solicita nova leitura de WAN, LAN, PPP/IP e Ethernet sem alterar a configuração.</p></div>' +
                            '<button type="button" data-device-action="refresh"><i class="bi bi-arrow-clockwise"></i> Atualizar leitura</button>' +
                        '</section>' +
                        '<section class="jrc-action-card warning">' +
                            '<div class="jrc-action-icon"><i class="bi bi-power"></i></div>' +
                            '<div class="jrc-action-copy"><span>EQUIPAMENTO</span><h5>Reiniciar equipamento</h5>' +
                            '<p>Envia um comando de reboot via TR-069. O cliente ficará temporariamente offline.</p></div>' +
                            '<button type="button" data-device-action="reboot"><i class="bi bi-power"></i> Reiniciar</button>' +
                        '</section>' +
                    '</div>' +
                    '<section class="jrc-actions-history-card">' +
                        '<div class="jrc-actions-history-head"><div><span>HISTÓRICO</span><h5>Últimas ações</h5></div>' +
                        '<button type="button" id="jrc-actions-refresh-history" title="Atualizar histórico"><i class="bi bi-arrow-clockwise"></i></button></div>' +
                        '<div id="jrc-actions-history"><div class="jrc-actions-empty">Carregando histórico...</div></div>' +
                    '</section>' +
                '</div>';
            content.appendChild(panel);
        }

        const refreshBtn = document.querySelector('[data-device-action="refresh"]');
        const rebootBtn = document.querySelector('[data-device-action="reboot"]');
        const histBtn = document.getElementById('jrc-actions-refresh-history');

        if (refreshBtn && !refreshBtn.dataset.bound) {
            refreshBtn.dataset.bound='1';
            refreshBtn.addEventListener('click', refreshReadings);
        }
        if (rebootBtn && !rebootBtn.dataset.bound) {
            rebootBtn.dataset.bound='1';
            rebootBtn.addEventListener('click', confirmReboot);
        }
        if (histBtn && !histBtn.dataset.bound) {
            histBtn.dataset.bound='1';
            histBtn.addEventListener('click', loadHistory);
        }

        const tab = document.getElementById(ACTION_TAB_ID);
        if (tab && !tab.dataset.bound) {
            tab.dataset.bound='1';
            tab.addEventListener('shown.bs.tab', loadHistory);
        }
    }

    function ensureRebootModal() {
        if (document.getElementById('jrcDeviceRebootModal')) return;
        const modal = document.createElement('div');
        modal.className='modal fade';
        modal.id='jrcDeviceRebootModal';
        modal.tabIndex=-1;
        modal.setAttribute('aria-hidden','true');
        modal.innerHTML =
            '<div class="modal-dialog modal-dialog-centered"><div class="modal-content jrc-reboot-modal">' +
            '<div class="modal-header"><div><small>CONFIRMAÇÃO</small><h5 class="modal-title">Reiniciar equipamento</h5></div>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>' +
            '<div class="modal-body"><p>O equipamento <strong id="jrc-reboot-device-label"></strong> ficará temporariamente offline.</p>' +
            '<label for="jrc-reboot-confirm">Digite <strong>REINICIAR</strong> para confirmar:</label>' +
            '<input id="jrc-reboot-confirm" type="text" autocomplete="off" class="form-control mt-2" placeholder="REINICIAR"></div>' +
            '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>' +
            '<button type="button" class="btn btn-danger" id="jrc-reboot-submit"><i class="bi bi-power"></i> Reiniciar equipamento</button></div>' +
            '</div></div>';
        document.body.appendChild(modal);

        document.getElementById('jrc-reboot-submit')?.addEventListener('click', rebootDevice);
    }

    const observer = new MutationObserver(() => {
        ensureTab();
        ensureRebootModal();
    });

    document.addEventListener('DOMContentLoaded', () => {
        ensureTab();
        ensureRebootModal();
        observer.observe(document.documentElement, {childList:true,subtree:true});
        historyTimer = setInterval(() => {
            if (document.getElementById(ACTION_TAB_ID)?.classList.contains('active')) loadHistory();
        }, 15000);
    });

    window.addEventListener('beforeunload', () => {
        if (historyTimer) clearInterval(historyTimer);
    });
})();