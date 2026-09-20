/* JR CONECT: scoped dashboard chart correction. No device/configuration writes.
 * Loaded after the legacy dashboard functions, before DOMContentLoaded.
 * signal_updated_at is a collection timestamp, NEVER proof of online status.
 */
(function (root) {
    'use strict';
    const HOUR = 3600000;
    const ZONE = 'America/Sao_Paulo';
    const fmt = value => Number(value).toLocaleString('pt-BR');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[ch]));

    function signal(value) {
        if (typeof value !== 'string' && typeof value !== 'number') return null;
        const text = String(value).trim().replace(',', '.');
        if (!text) return null;
        const n = Number(text);
        return Number.isFinite(n) && Math.abs(n) >= 0.001 ? n : null;
    }

    function timestamp(value) {
        // Legacy SQL dates have no offset. Interpret them in the operator's
        // configured region (-03:00); reject invalid and future dates below.
        if (typeof value !== 'string') return null;
        const s = value.trim();
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2})(\.\d{1,3})?)?(Z|[+-]\d{2}:\d{2})?$/);
        if (!m) return null;
        const [y, mo, day, h, mi, sec] = m.slice(1, 7).map(x => Number(x || 0));
        const maxDay = new Date(Date.UTC(y, mo, 0)).getUTCDate();
        if (mo < 1 || mo > 12 || day < 1 || day > maxDay || h > 23 || mi > 59 || sec > 59) return null;
        const iso = `${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6] || '00'}${m[7] || ''}${m[8] || '-03:00'}`;
        const result = Date.parse(iso);
        return Number.isFinite(result) ? result : null;
    }

    function analyse(items, summary = {}, now = Date.now()) {
        const devices = Array.isArray(items) ? items.filter(x => x && typeof x === 'object') : [];
        const rawTotal = summary?.total_ixc_fiber;
        const n = (typeof rawTotal === 'number' || (typeof rawTotal === 'string' && rawTotal.trim())) ? Number(rawTotal) : NaN;
        const reportedTotal = Number.isSafeInteger(n) && n >= 0 ? n : devices.length;
        const total = Math.max(reportedTotal, devices.length);
        const start = now - 24 * HOUR;
        const state = { total, loaded: devices.length, complete: total === devices.length,
            withSignal: 0, missing: 0, critical: 0, updated24: 0,
            validDates: 0, invalidDates: 0, futureDates: 0, start,
            hours: Array(24).fill(0), models: Object.create(null), manufacturers: Object.create(null) };
        for (const d of devices) {
            const source = d.ixc || {};
            const rx = signal(source.rx_power);
            if (rx === null) state.missing++;
            else { state.withSignal++; if (rx <= -28) state.critical++; }
            const t = timestamp(source.signal_updated_at);
            if (t === null) state.invalidDates++;
            else if (t > now) state.futureDates++;
            else {
                state.validDates++;
                if (t > start) {
                    state.updated24++;
                    state.hours[Math.min(23, Math.floor((t - start) / HOUR))]++;
                }
            }
            const model = String(source.onu_tipo || d.acs?.product_class || 'N\u00e3o informado').trim();
            state.models[model] = (state.models[model] || 0) + 1;
            // Do not infer manufacturers from ambiguous model prefixes.
            const maker = String(d.acs?.manufacturer || 'N\u00e3o informado').trim();
            state.manufacturers[maker] = (state.manufacturers[maker] || 0) + 1;
        }
        state.coverage = state.loaded ? 100 * state.withSignal / state.loaded : null;
        return state;
    }

    if (typeof module !== 'undefined' && module.exports) module.exports = { analyse, timestamp, signal };
    if (!root.document) return;
    const doc = root.document;
    const shell = doc.querySelector('.jr-ref-shell');
    if (!shell || shell.dataset.chartFix === '1') return;
    shell.dataset.chartFix = '1';
    shell.classList.add('jr-charts-fixed');
    let latest = null;
    const select = selector => shell.querySelector(selector);
    const text = (id, value) => { const el = doc.getElementById(id); if (el) el.textContent = value; };
    const label = (selector, value) => { const el = select(selector); if (el) el.textContent = value; };
    const percent = value => value === null ? '\u2014' : value.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%';
    const clock = time => new Date(time).toLocaleTimeString('pt-BR', { timeZone: ZONE, hour: '2-digit', minute: '2-digit' });

    // Change presentation text only; PHP/internal identifiers remain intact.
    const heading = doc.querySelector('.topbar h4');
    if (heading) heading.textContent = 'JRCONECT TELECOM';
    doc.title = 'JRCONECT TELECOM | Vis\u00e3o Geral';
    label('.jr-ref-status .jr-ref-card-head strong', 'Leituras dos dispositivos');
    const statusLabel = select('.jr-ref-status-numbers > div:first-child > span');
    if (statusLabel) statusLabel.innerHTML = '<i class="dot green"></i>Com leitura \u00f3ptica';
    label('.jr-ref-caption', 'Situa\u00e7\u00e3o das leituras na consulta atual');
    label('.jr-ref-activity .jr-ref-card-head strong', '\u00daltima atualiza\u00e7\u00e3o das leituras');
    label('.jr-ref-activity-score > span', 'atualizados nas \u00faltimas 24 horas');
    label('.jr-ref-ai .jr-ref-card-head strong', 'Resumo operacional');
    label('.jr-ref-ai .beta', 'Autom\u00e1tico');
    label('.jr-ref-auto span', 'Leitura \u00f3ptica n\u00e3o confirma conex\u00e3o online');
    select('.jr-ref-pills')?.remove();

    const ring = select('.jr-ref-ring');
    const canvas = doc.getElementById('deviceChart');
    if (canvas && root.Chart?.getChart) root.Chart.getChart(canvas)?.destroy();
    if (ring) {
        ring.innerHTML = '<svg class="jr-fixed-ring-svg" viewBox="0 0 180 180" aria-hidden="true">' +
            '<circle cx="90" cy="90" r="70" class="jr-fixed-ring-track"/>' +
            '<circle id="jr-ring-value" cx="90" cy="90" r="70" class="jr-fixed-ring-value" pathLength="100" stroke-dasharray="0 100"/>' +
            '</svg><div class="jr-fixed-ring-center"><strong id="ref-availability">\u2014</strong><span>Cobertura de leitura</span></div>';
        ring.setAttribute('role', 'img');
        ring.setAttribute('aria-label', 'Cobertura de leitura: aguardando dados');
    }

    const activity = doc.getElementById('ref-activity-bars');
    if (activity) {
        activity.innerHTML = '<span class="jr-fixed-empty">Aguardando datas das leituras.</span>';
        const note = doc.createElement('small');
        note.className = 'jr-fixed-note';
        note.id = 'jr-reading-note';
        note.textContent = 'Cada coluna conta a \u00faltima coleta conhecida. N\u00e3o \u00e9 hist\u00f3rico de disponibilidade.';
        select('.jr-ref-activity-body')?.append(note);
    }
    const coverage = doc.getElementById('ref-hour-bars');
    if (coverage) coverage.innerHTML = '<span class="jr-fixed-empty">Aguardando dados de leitura.</span>';

    // No reset event feed exists in this page. Missing != zero.
    text('ref-reset-today', '\u2014');
    text('ref-reset-average', '\u2014');
    const resetNote = select('.jr-ref-reset-ok');
    if (resetNote) resetNote.innerHTML = '<i class="bi bi-info-circle"></i><span>Hist\u00f3rico de resets ainda n\u00e3o dispon\u00edvel.</span>';
    const resetBars = select('.jr-ref-reset-bars');
    if (resetBars) {
        resetBars.innerHTML = '<span>Sem registros integrados</span>';
        resetBars.classList.add('jr-fixed-no-events');
    }
    const resetDays = select('.jr-ref-reset-days');
    if (resetDays) {
        resetDays.replaceChildren();
        for (let i = 6; i >= 0; i--) {
            const span = doc.createElement('span');
            const date = new Date(Date.now() - i * 24 * HOUR);
            span.textContent = date.toLocaleDateString('pt-BR', { timeZone: ZONE, weekday: 'short' }).replace('.', '');
            span.title = date.toLocaleDateString('pt-BR', { timeZone: ZONE });
            resetDays.append(span);
        }
    }

    function renderCoverage(s) {
        if (!coverage) return;
        if (!s.loaded) {
            coverage.innerHTML = '<span class="jr-fixed-empty">Nenhuma leitura retornada nesta consulta.</span>';
            return;
        }
        const rows = [
            ['Acima do limite cr\u00edtico', s.withSignal - s.critical, 'green'],
            ['Sinal cr\u00edtico', s.critical, 'red'],
            ['Sem leitura', s.missing, 'muted']
        ];
        coverage.innerHTML = rows.map(([name, count, tone]) =>
            '<div class="jr-fixed-coverage-row"><span>' + name + '</span><div class="jr-fixed-track">' +
            '<b class="jr-fixed-fill ' + tone + '" style="width:' + (100 * count / s.loaded) + '%"></b></div>' +
            '<strong>' + fmt(count) + '</strong></div>').join('');
        coverage.title = 'Contagem de leituras retornadas. Sinal cr\u00edtico: RX menor ou igual a -28 dBm.';
    }

    function renderHours(s) {
        if (!activity) return;
        if (!s.validDates) {
            activity.innerHTML = '<span class="jr-fixed-empty">Nenhuma data v\u00e1lida dispon\u00edvel.</span>';
            text('ref-active-24h', '\u2014');
            return;
        }
        const max = Math.max(1, ...s.hours);
        activity.innerHTML = '<div class="jr-fixed-hour-plot">' + s.hours.map((count, i) => {
            const range = clock(s.start + i * HOUR) + ' a ' + clock(s.start + (i + 1) * HOUR);
            const tip = `${range}: ${fmt(count)} \u00faltimas atualiza\u00e7\u00f5es`;
            return '<div class="jr-fixed-hour-col" tabindex="0" title="' + esc(tip) + '" aria-label="' + esc(tip) + '">' +
                '<i style="height:' + (100 * count / max) + '%"></i></div>';
        }).join('') + '</div><div class="jr-fixed-hour-axis">' + [0, 6, 12, 18, 24].map(i =>
            '<span>' + clock(s.start + i * HOUR) + '</span>').join('') + '</div>';
        const note = doc.getElementById('jr-reading-note');
        if (note) {
            note.textContent = 'Cada coluna conta a \u00faltima coleta conhecida; n\u00e3o representa uptime. Hor\u00e1rio de S\u00e3o Paulo.';
            if (s.futureDates || s.invalidDates) note.textContent += ` Datas futuras/ausentes/inv\u00e1lidas exclu\u00eddas: ${fmt(s.futureDates + s.invalidDates)}.`;
        }
    }

    function renderModels(s) {
        const holder = doc.getElementById('ref-model-bars');
        const sorted = Object.entries(s.models).sort((a, b) => b[1] - a[1]);
        const makers = Object.entries(s.manufacturers).sort((a, b) => b[1] - a[1]);
        text('ref-top-model', sorted[0]?.[0] || '\u2014');
        text('ref-top-manufacturer', makers[0]?.[0] || 'N\u00e3o informado');
        if (!holder) return;
        const rows = sorted.slice(0, 5);
        const remaining = sorted.slice(5).reduce((sum, row) => sum + row[1], 0);
        if (remaining) rows.push(['Outros modelos', remaining]);
        holder.innerHTML = rows.map(([name, count]) => {
            const pct = s.loaded ? 100 * count / s.loaded : 0;
            return '<div class="jr-ref-model-row"><span title="' + esc(name) + '">' + esc(name) + '</span>' +
                '<div class="jr-ref-model-track"><div class="jr-ref-model-fill" style="width:' + pct + '%"></div></div>' +
                '<b title="' + fmt(count) + ' equipamentos">' + percent(pct) + '</b></div>';
        }).join('');
    }

    function summaryText() {
        if (!latest) return 'Aguardando dados para montar o resumo operacional. N\u00e3o foi executada an\u00e1lise por IA.';
        const s = latest;
        if (!s.loaded) return 'Nenhum registro de leitura foi retornado. N\u00e3o \u00e9 poss\u00edvel confirmar o estado online dos equipamentos.';
        return `${fmt(s.total)} equipamentos no invent\u00e1rio; ${fmt(s.loaded)} registros retornados. ` +
            `${fmt(s.withSignal)} com valor de sinal e ${fmt(s.missing)} sem leitura. ` +
            `${fmt(s.critical)} leituras est\u00e3o no limite cr\u00edtico (RX \u2264 -28 dBm). ` +
            'Uma coleta recente n\u00e3o confirma conex\u00e3o online. Resumo autom\u00e1tico, sem an\u00e1lise por IA.';
    }

    function render(devices, summary) {
        const s = analyse(devices, summary);
        latest = s;
        text('ref-total', fmt(s.total));
        text('ref-online', s.loaded || s.total === 0 ? fmt(s.withSignal) : '\u2014');
        text('ref-online-pct', percent(s.coverage));
        text('ref-availability', percent(s.coverage));
        text('ref-critical', s.loaded || s.total === 0 ? fmt(s.critical) : '\u2014');
        text('ref-nosignal', s.loaded || s.total === 0 ? fmt(s.missing) : '\u2014');
        text('ref-active-24h', s.validDates ? fmt(s.updated24) : '\u2014');
        const managed = Number(summary?.total_genieacs);
        text('ref-tr069', summary?.total_genieacs != null && Number.isSafeInteger(managed) && managed >= 0 ? fmt(managed) : '\u2014');
        const bar = doc.getElementById('ref-online-bar');
        if (bar) bar.style.width = (s.coverage ?? 0) + '%';
        const arc = doc.getElementById('jr-ring-value');
        const p = s.coverage ?? 0;
        if (arc) arc.setAttribute('stroke-dasharray', p + ' ' + (100 - p));
        if (ring) ring.setAttribute('aria-label', `Cobertura de leitura: ${percent(s.coverage)}. ${fmt(s.withSignal)} com leitura entre ${fmt(s.loaded)} retornados.`);
        label('.jr-ref-caption', s.complete ? 'Situa\u00e7\u00e3o das leituras na consulta atual' : `Consulta parcial: ${fmt(s.loaded)} de ${fmt(s.total)} registros`);
        label('.jr-fixed-ring-center > span', s.complete ? 'Cobertura de leitura' : 'Cobertura da amostra');
        renderCoverage(s);
        renderHours(s);
        renderModels(s);
        text('ref-ai-summary', summaryText());
    }

    // The existing loader supplies the same read-only payload. Stop the old
    // timestamp-to-online inference without altering the APIs or action routes.
    root.renderReferenceDashboard = render;
    root.buildDashboardAISummary = summaryText;
    // The removed optical card must not trigger a needless request every 30 s.
    const originalUplink = root.loadUplinkData;
    root.loadUplinkData = function () {
        if (doc.getElementById('uplinkChart') && typeof originalUplink === 'function') return originalUplink();
        return Promise.resolve();
    };
    text('ref-ai-summary', summaryText());
    root.JRDashboardChartsFix = { version: '1.0.0', analyse, render };
})(typeof window !== 'undefined' ? window : globalThis);
