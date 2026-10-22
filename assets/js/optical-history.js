let opticalHistoryHours = 24;
let opticalHistoryRequest = 0;
const opticalHistoryCache = new Map();
const opticalHistoryPending = new Map();

function renderOpticalHistoryCard() {
    return `<section class="acs-overview-card acs-optical-history">
        <div class="acs-overview-card-header">
            <div><span class="acs-kicker">Sinal óptico (GPON)</span>
                <small class="d-block text-muted">RX ao longo do período · IXC</small></div>
            <div class="acs-history-periods" role="group" aria-label="Período do histórico">
                <button type="button" onclick="loadOpticalHistory(currentDeviceData.serial_number, 24)" data-history-hours="24">24h</button>
                <button type="button" onclick="loadOpticalHistory(currentDeviceData.serial_number, 168)" data-history-hours="168">7 dias</button>
                <button type="button" onclick="loadOpticalHistory(currentDeviceData.serial_number, 720)" data-history-hours="720">30 dias</button>
                <button type="button" onclick="loadOpticalHistory(currentDeviceData.serial_number, opticalHistoryHours, true)" title="Atualizar histórico" aria-label="Atualizar histórico"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
        </div>
        <div class="fb-gauge" id="optical-rx-gauge" aria-hidden="true">
            <div class="fb-gauge-track"><span id="optical-rx-gauge-mark"></span></div>
            <div class="fb-gauge-scale"><span>-30</span><span>-20</span><span>-8 dBm</span></div>
        </div>
        <div id="optical-history-content" aria-live="polite"><p class="text-muted">Consultando histórico…</p></div>
    </section>`;
}

function opticalHistoryEscape(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
}

function opticalHistoryView(data) {
    const points = (Array.isArray(data.points) ? data.points : []).filter(point =>
        typeof point.rx === 'number' && Number.isFinite(point.rx) && Number.isFinite(point.time) &&
        Number.isFinite(point.min) && Number.isFinite(point.max));
    if (!points.length) return '<p class="text-muted mb-0">Sem leituras neste período. O histórico começa após a ativação da coleta; não recupera medições antigas.</p>';
    const min = Math.floor(Math.min(-28, ...points.map(point => point.min)) - 1);
    const max = Math.ceil(Math.max(-24, ...points.map(point => point.max)) + 1);
    const left = 50, right = 740, top = 14, bottom = 172;
    const first = points[0].time, last = points[points.length - 1].time;
    const span = Math.max((data.bucket_seconds || 900) * 1000, last - first);
    const x = time => left + (time - first) / span * (right - left);
    const y = rx => bottom - (rx - min) / (max - min) * (bottom - top);
    const format = value => Number(value).toFixed(2).replace('.', ',');
    const date = time => new Date(time).toLocaleString('pt-BR', {day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'});
    const gap = (data.bucket_seconds || 900) * 1500;
    const segments = [];
    let segment = [];
    let previous = null;
    points.forEach(point => {
        if (previous !== null && point.time - previous > gap && segment.length) {
            segments.push(segment);
            segment = [];
        }
        segment.push(point);
        previous = point.time;
    });
    if (segment.length) segments.push(segment);
    const line = segments.map(group => group.map((point, index) =>
        `${index === 0 ? 'M' : 'L'}${x(point.time).toFixed(2)},${y(point.rx).toFixed(2)}`).join(' ')).join(' ');
    const area = segments.map(group => {
        const start = group[0];
        const end = group[group.length - 1];
        const stroke = group.map((point, index) => `${index === 0 ? 'M' : 'L'}${x(point.time).toFixed(2)},${y(point.rx).toFixed(2)}`).join(' ');
        return `${stroke} L${x(end.time).toFixed(2)},${bottom} L${x(start.time).toFixed(2)},${bottom} Z`;
    }).join(' ');
    const guides = [min, -27, -25, max].filter((value, index, values) => values.indexOf(value) === index).map(value =>
        `<line x1="${left}" x2="${right}" y1="${y(value)}" y2="${y(value)}" stroke="${value === -27 ? '#ef4444' : value === -25 ? '#eab308' : '#40506a'}" stroke-dasharray="4 5"/>
         <text x="42" y="${y(value) + 4}" text-anchor="end">${value}</text>`).join('');
    const dots = points.map(point => `<circle cx="${x(point.time)}" cy="${y(point.rx)}" r="2.4" fill="#ff5d5d">
        <title>${opticalHistoryEscape(date(point.time))} · RX médio ${format(point.rx)} dBm · mín ${format(point.min)} / máx ${format(point.max)}</title></circle>`).join('');
    const stats = data.stats || {};
    const worst = Math.min(...points.map(point => point.min));
    const best = Math.max(...points.map(point => point.max));
    const seen = stats.last_seen_at ? new Date(stats.last_seen_at) : null;
    const seenLabel = seen && !Number.isNaN(seen.getTime()) ? ` · Última observação ${seen.toLocaleString('pt-BR')}` : '';
    const bucketLabel = data.bucket_seconds === 21600 ? '6 horas' : data.bucket_seconds === 3600 ? '1 hora' : '15 minutos';
    return `<div class="acs-history-stats">
        <span>Menor RX <strong>${format(worst)} dBm</strong></span>
        <span>Maior RX <strong>${format(best)} dBm</strong></span>
        <span>Variação <strong>${format(best - worst)} dB</strong></span>
        <span>Leituras <strong>${Number(stats.samples) || 0}</strong></span>
        </div><svg class="acs-history-chart" viewBox="0 0 760 208" role="img" aria-label="Histórico da potência recebida em dBm">
        <defs><linearGradient id="fbRxFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#ff5d5d" stop-opacity="0.42"/><stop offset="100%" stop-color="#ff5d5d" stop-opacity="0.02"/></linearGradient></defs>
        ${guides}<path d="${area}" fill="url(#fbRxFill)" stroke="none"/><path d="${line}" fill="none" stroke="#ff5d5d" stroke-width="2"/>${dots}
        <text x="${left}" y="198">${opticalHistoryEscape(date(first))}</text>
        <text x="${right}" y="198" text-anchor="end">${opticalHistoryEscape(date(last))}</text>
        </svg><p class="small text-muted mb-0">IXC · Média por intervalo de ${bucketLabel}; passe sobre os pontos para ver mínimo e máximo. Linhas de referência: −25 e −27 dBm.${opticalHistoryEscape(seenLabel)}</p>
        ${Number(stats.unconfirmed_time) > 0 ? '<p class="small text-warning mt-2 mb-0">Há leituras sem horário informado pelo IXC. Nesses casos, o gráfico usa o horário em que o ACS observou o valor.</p>' : ''}`;
}

async function loadOpticalHistory(serial, hours = opticalHistoryHours, force = false) {
    if (![24, 168, 720].includes(hours)) return;
    opticalHistoryHours = hours;
    const request = ++opticalHistoryRequest;
    const holder = document.getElementById('optical-history-content');
    if (!holder) return;
    document.querySelectorAll('[data-history-hours]').forEach(button => {
        const selected = Number(button.dataset.historyHours) === hours;
        button.classList.toggle('active', selected);
        button.setAttribute('aria-pressed', String(selected));
    });
    const key = serial + ':' + hours;
    const cached = opticalHistoryCache.get(key);
    if (cached && !force && Date.now() - cached.time < 60000) {
        holder.innerHTML = opticalHistoryView(cached.data);
        return;
    }
    holder.innerHTML = '<p class="text-muted">Consultando histórico…</p>';
    try {
        if (!opticalHistoryPending.has(key)) {
            const pending = fetchAPI('/api/get-optical-history.php?serial=' + encodeURIComponent(serial) + '&hours=' + hours);
            opticalHistoryPending.set(key, pending);
            pending.finally(() => opticalHistoryPending.delete(key)).catch(() => {});
        }
        const data = await opticalHistoryPending.get(key);
        if (!data?.success) throw new Error(data?.message || 'Não foi possível consultar o histórico.');
        opticalHistoryCache.set(key, {time: Date.now(), data});
        if (request !== opticalHistoryRequest) return;
        const currentHolder = document.getElementById('optical-history-content');
        if (currentHolder) currentHolder.innerHTML = opticalHistoryView(data);
    } catch (error) {
        if (request !== opticalHistoryRequest) return;
        const currentHolder = document.getElementById('optical-history-content');
        if (currentHolder) currentHolder.innerHTML = `<p class="text-muted mb-0">${opticalHistoryEscape(error.message)}</p>`;
    }
}
