let deviceActionHistoryRequest = 0;
let deviceActionHistoryBefore = null;
let deviceActionHistoryTimer = null;
const deviceActionHistoryPending = new Map();

function renderDeviceActionHistoryCard() {
    return `<section class="acs-overview-card acs-action-history">
        <div class="acs-overview-card-header"><div><span class="acs-kicker"><i class="bi bi-clock-history"></i> Ações e comandos</span>
            <small class="d-block text-muted">Quem executou · horário · retorno do GenieACS</small></div>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadDeviceActionHistory(currentDeviceData.device_id)" aria-label="Atualizar histórico de ações"><i class="bi bi-arrow-clockwise"></i></button></div>
        <div id="device-action-history-content" aria-live="polite"><p class="text-muted">Consultando ações…</p></div>
    </section>`;
}

function deviceActionHistoryEscape(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
}

function deviceActionHistoryView(data) {
    const entries = Array.isArray(data.entries) ? data.entries : [];
    if (!entries.length) return '<p class="text-muted mb-0">Nenhuma ação registrada. O histórico começa após a ativação e registra comandos enviados pelo painel e pela integração SIMS.</p>';
    const statuses = {submitted: ['Enviado', 'secondary'], queued: ['Aguardando ONU', 'warning'], completed: ['Concluído', 'success'], failed: ['Falhou', 'danger'], unconfirmed: ['Sem confirmação', 'secondary']};
    const actions = {change: 'Alterar configuração', refresh: 'Atualizar leitura', summon: 'Solicitar comunicação', reboot: 'Reiniciar equipamento', command: 'Comando'};
    const date = value => {
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? 'Horário indisponível' : parsed.toLocaleString('pt-BR');
    };
    const rows = entries.map(entry => {
        const state = statuses[entry.status] || statuses.unconfirmed;
        return `<tr><td>${deviceActionHistoryEscape(date(entry.created_at))}</td>
            <td>${deviceActionHistoryEscape(entry.actor)}</td>
            <td>${deviceActionHistoryEscape(actions[entry.action] || 'Comando')}${entry.fields ? `<small class="d-block text-muted">${deviceActionHistoryEscape(entry.fields)}</small>` : ''}</td>
            <td><span class="badge bg-${state[1]}">${state[0]}</span></td></tr>`;
    }).join('');
    return `<div class="table-responsive"><table class="table table-sm mb-2"><thead><tr><th>Horário</th><th>Responsável</th><th>Ação</th><th>Resultado</th></tr></thead><tbody>${rows}</tbody></table></div>
        <div class="d-flex gap-2 mb-2">
            ${deviceActionHistoryBefore ? '<button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadDeviceActionHistory(currentDeviceData.device_id)">Mais recentes</button>' : ''}
            ${data.has_more ? `<button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadDeviceActionHistory(currentDeviceData.device_id, ${Number(data.next_before) || 0})">Ações anteriores</button>` : ''}
        </div><p class="small text-muted mb-0">Concluído indica execução confirmada pelo retorno do GenieACS. Uma tarefa que desapareceu da fila fica sem confirmação. Senhas não são armazenadas neste histórico.</p>
        ${data.sync_available === false ? '<p class="small text-warning mt-2 mb-0">Não foi possível atualizar a fila de comandos agora. Mantidos os últimos estados registrados.</p>' : ''}`;
}

async function loadDeviceActionHistory(deviceId, before = null) {
    const holder = document.getElementById('device-action-history-content');
    if (!holder || !deviceId) return;
    deviceActionHistoryBefore = before;
    const request = ++deviceActionHistoryRequest;
    const key = deviceId + ':' + (before || 'latest');
    try {
        if (!deviceActionHistoryPending.has(key)) {
            const pending = fetchAPI('/api/get-device-action-history.php?device_id=' + encodeURIComponent(deviceId) + (before ? '&before=' + Number(before) : ''), {timeout: 18000});
            deviceActionHistoryPending.set(key, pending);
            pending.finally(() => deviceActionHistoryPending.delete(key)).catch(() => {});
        }
        const data = await deviceActionHistoryPending.get(key);
        if (request !== deviceActionHistoryRequest) return;
        if (!data?.success) throw new Error(data?.message || 'Não foi possível carregar as ações.');
        const current = document.getElementById('device-action-history-content');
        if (current) current.innerHTML = deviceActionHistoryView(data);
    } catch (error) {
        if (request !== deviceActionHistoryRequest) return;
        const current = document.getElementById('device-action-history-content');
        if (current) current.innerHTML = `<p class="text-muted mb-0">${deviceActionHistoryEscape(error.message)}</p>`;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    deviceActionHistoryTimer = setInterval(() => {
        if (!document.hidden && typeof currentDeviceData !== 'undefined' && currentDeviceData?.device_id
            && !deviceActionHistoryBefore && document.getElementById('device-action-history-content')) {
            loadDeviceActionHistory(currentDeviceData.device_id);
        }
    }, 15000);
});
window.addEventListener('beforeunload', () => clearInterval(deviceActionHistoryTimer));
