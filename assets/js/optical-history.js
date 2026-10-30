/* JRC_DEVICE_CHARTS_FIX_V1 */
let opticalHistoryHours = 24;
let opticalHistoryRequest = 0;
const opticalHistoryCache = new Map();
const opticalHistoryPending = new Map();

function renderOpticalHistoryCard(slot = 'overview') {
    const contentId = slot === 'overview' ? 'optical-history-content' : 'optical-history-' + slot;
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
        <div class="fb-optical-now">
            <div><span>RX</span><strong data-optical="rx_power">—</strong></div>
            <div><span>TX</span><strong data-optical="tx_power">—</strong></div>
            <div><span>Temperatura</span><strong data-optical="temperature">—</strong></div>
            <div><span>Voltagem</span><strong data-optical="voltage">—</strong></div>
        </div>
        <div class="fb-gauge" data-optical-gauge aria-hidden="true">
            <div class="fb-gauge-track"><span data-gauge-mark></span></div>
            <div class="fb-gauge-scale"><span>-30</span><span data-gauge-label>RX</span><span>-8 dBm</span></div>
        </div>
        <div id="${contentId}" data-optical-history aria-live="polite"><p class="text-muted">Consultando histórico…</p></div>
    </section>`;
}

function opticalHistoryEscape(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
}

function opticalHistoryView(data) {
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const numeric = v => v !== null && v !== undefined && v !== '' && typeof v !== 'boolean' && Number.isFinite(Number(v)) ? Number(v) : null;
    const points = (Array.isArray(data?.points) ? data.points : []).map(p => ({
        time:numeric(p.time), rx:numeric(p.rx), min:numeric(p.min ?? p.rx), max:numeric(p.max ?? p.rx)
    })).filter(p => Object.values(p).every(Number.isFinite)).sort((a,b) => a.time-b.time);
    if (!points.length) return '<p class="text-muted mb-0">Sem leituras opticas neste periodo. Nenhum valor foi estimado.</p>';
    const bucket = numeric(data.bucket_seconds) > 0 ? Number(data.bucket_seconds) : 900;
    const min = Math.floor(Math.min(-27,...points.map(p=>p.min))-1);
    const max = Math.ceil(Math.max(-25,...points.map(p=>p.max))+1);
    const left=52, right=716, top=22, bottom=180;
    const first=points[0].time, last=points[points.length-1].time;
    const x=t=>last===first ? (left+right)/2 : left+(t-first)/(last-first)*(right-left);
    const y=v=>bottom-(v-min)/(max-min)*(bottom-top);
    const format=v=>Number(v).toFixed(2).replace('.',',');
    const date=t=>new Date(t).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
    let path='', gaps=0, previous=null;
    for (const p of points) {
        const gap=previous!==null && p.time-previous>bucket*1500;
        if (gap) gaps++;
        path+=(previous===null || gap ? 'M':'L')+x(p.time).toFixed(2)+','+y(p.rx).toFixed(2)+' ';
        previous=p.time;
    }
    const guides=[...new Set([min,-27,-25,max])].sort((a,b)=>a-b).map(v=>
        `<line x1="${left}" x2="${right}" y1="${y(v)}" y2="${y(v)}" stroke="${v===-27?'#f87171':v===-25?'#fbbf24':'#3d4554'}" stroke-dasharray="4 5"/><text x="44" y="${y(v)+4}" text-anchor="end" fill="#aeb6c4" font-size="11">${v}</text>`).join('');
    const dots=points.map(p=>`<circle cx="${x(p.time)}" cy="${y(p.rx)}" r="3.5" fill="#ff555d"><title>${esc(date(p.time))} | RX ${format(p.rx)} dBm | min ${format(p.min)} / max ${format(p.max)}</title></circle>`).join('');
    const stats=data.stats||{}, worst=Math.min(...points.map(p=>p.min)), best=Math.max(...points.map(p=>p.max));
    const count=numeric(stats.samples);
    const seen=stats.last_seen_at ? new Date(stats.last_seen_at) : null;
    const seenText=seen && Number.isFinite(seen.getTime()) ? ' | Ultima observacao: '+seen.toLocaleString('pt-BR') : '';
    const gapText=gaps ? ` | ${gaps} lacuna(s) sem coletas. Os intervalos sem dados nao foram preenchidos.` : '';
    const singleText=points.length===1 ? 'Uma faixa de tempo com leitura: ainda nao ha uma serie continua.' : `${points.length} intervalos com dados reais${gapText}`;
    return `<div class="acs-history-stats"><span>Menor RX <strong>${format(worst)} dBm</strong></span><span>Maior RX <strong>${format(best)} dBm</strong></span><span>Variacao <strong>${format(best-worst)} dB</strong></span><span>Leituras <strong>${count===null?'--':count}</strong></span></div>
    <svg class="acs-history-chart" data-jrc-chart-fix="optical-v1" viewBox="0 0 760 224" style="display:block;width:100%;height:220px" role="img" aria-label="Historico optico real; lacunas sem interpolacao"><text x="${left}" y="12" fill="#aeb6c4" font-size="11">Potencia RX (dBm)</text>${guides}<path d="${path}" fill="none" stroke="#ff555d" stroke-width="2"/>${dots}<text x="${left}" y="210" fill="#aeb6c4" font-size="11">${esc(date(first))}</text>${last!==first?`<text x="${right}" y="210" text-anchor="end" fill="#aeb6c4" font-size="11">${esc(date(last))}</text>`:''}</svg>
    <p class="small text-muted mb-0">${esc(singleText)}</p><p class="small text-muted mb-0">IXC | Media por intervalo de ${bucket/60} min | Referencias da interface: -25 e -27 dBm${esc(seenText)}</p>${Number(stats.unconfirmed_time)>0?'<p class="small text-warning">Ha leituras sem horario de medicao informado pelo IXC; nesses casos vale o horario de observacao.</p>':''}`;
}

async function loadOpticalHistory(serial, hours = opticalHistoryHours, force = false) {
    if (![24, 168, 720].includes(hours)) return;
    opticalHistoryHours = hours;
    const request = ++opticalHistoryRequest;
    const holders = () => [...document.querySelectorAll('[data-optical-history]')];
    if (!holders().length) return;
    const paint = (html) => holders().forEach(node => { node.innerHTML = html; });
    document.querySelectorAll('[data-history-hours]').forEach(button => {
        const selected = Number(button.dataset.historyHours) === hours;
        button.classList.toggle('active', selected);
        button.setAttribute('aria-pressed', String(selected));
    });
    const key = serial + ':' + hours;
    const cached = opticalHistoryCache.get(key);
    if (cached && !force && Date.now() - cached.time < 60000) {
        paint(opticalHistoryView(cached.data));
        return;
    }
    paint('<p class="text-muted">Consultando histórico…</p>');
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
        paint(opticalHistoryView(data));
    } catch (error) {
        if (request !== opticalHistoryRequest) return;
        paint(`<p class="text-muted mb-0">${opticalHistoryEscape(error.message)}</p>`);
    }
}
