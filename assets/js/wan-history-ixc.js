(() => {
    'use strict';

    const CARD_ID = 'jrc-wan-history-ixc-card';
    let currentHours = 720;
    let lastLogin = '';

    function fmtBytes(value) {
        let n = Math.max(0, Number(value) || 0);
        const units = ['B','KB','MB','GB','TB'];
        let i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        const d = n >= 100 ? 0 : n >= 10 ? 1 : 2;
        return n.toFixed(d) + ' ' + units[i];
    }

    function findPppoeLogin() {
        const rows = document.querySelectorAll('#wan-content .acs-wan-details > div');
        for (const row of rows) {
            const label = row.querySelector('span');
            const value = row.querySelector('strong');
            if (!label || !value) continue;
            if (/Usu[aá]rio PPPoE/i.test(label.textContent || '')) {
                const login = (value.textContent || '').trim();
                if (login && !/não disponível|n\/a/i.test(login)) return login;
            }
        }
        const overview = document.getElementById('wan-pppoe-user');
        if (overview) {
            const login = (overview.textContent || '').trim();
            if (login && !/não disponível|n\/a/i.test(login)) return login;
        }
        return '';
    }

    function buildCard() {
        const wrapper = document.createElement('section');
        wrapper.id = CARD_ID;
        wrapper.className = 'jrc-wan-history';
        wrapper.innerHTML =
            '<div class="jrc-wan-history-head">' +
                '<div><h5>Histórico de tráfego</h5><small>Consumo real do IXC · RADIUS / radusuarios_consumo</small></div>' +
                '<div class="jrc-wan-history-periods">' +
                    '<button data-hours="1">1 h</button>' +
                    '<button data-hours="24">24 h</button>' +
                    '<button data-hours="168">7 dias</button>' +
                    '<button data-hours="720" class="active">30 dias</button>' +
                '</div>' +
            '</div>' +
            '<div class="jrc-wan-history-stats">' +
                '<div class="jrc-wan-history-stat"><span>Download total</span><strong data-stat="rx-total">--</strong></div>' +
                '<div class="jrc-wan-history-stat"><span>Upload total</span><strong data-stat="tx-total">--</strong></div>' +
                '<div class="jrc-wan-history-stat"><span>Média download</span><strong data-stat="rx-avg">--</strong></div>' +
                '<div class="jrc-wan-history-stat"><span>Média upload</span><strong data-stat="tx-avg">--</strong></div>' +
                '<div class="jrc-wan-history-stat"><span>Pico download</span><strong data-stat="rx-peak">--</strong></div>' +
                '<div class="jrc-wan-history-stat"><span>Pico upload</span><strong data-stat="tx-peak">--</strong></div>' +
            '</div>' +
            '<div class="jrc-wan-history-chart"><div class="jrc-wan-history-empty">Carregando histórico do IXC...</div></div>';

        wrapper.querySelectorAll('[data-hours]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                currentHours = Number(btn.dataset.hours) || 720;
                wrapper.querySelectorAll('[data-hours]').forEach(function(b) {
                    b.classList.toggle('active', b === btn);
                });
                loadHistory();
            });
        });

        return wrapper;
    }

    function ensureCard() {
        const page = document.querySelector('#wan-content .acs-wan-page');
        if (!page) return null;

        document.querySelectorAll('#wan-content .jrc-wan-history').forEach(function(node) {
            if (node.id !== CARD_ID) node.remove();
        });

        let card = document.getElementById(CARD_ID);
        if (!card) {
            card = buildCard();
            const foot = page.querySelector('.acs-wan-footnote');
            if (foot) page.insertBefore(card, foot);
            else page.appendChild(card);
        }
        return card;
    }

    function chart(points) {
        if (!Array.isArray(points) || points.length === 0) {
            return '<div class="jrc-wan-history-empty">O IXC não retornou consumo para este período.</div>';
        }

        const W = 1000, H = 300, L = 78, R = 982, T = 18, B = 244;
        const pw = R - L, ph = B - T;
        const max = Math.max(1, ...points.flatMap(function(p) {
            return [Number(p.rx_bytes) || 0, Number(p.tx_bytes) || 0];
        }));
        const x = function(i) {
            return points.length === 1 ? L + pw / 2 : L + (i / (points.length - 1)) * pw;
        };
        const y = function(v) {
            return B - (Math.max(0, Number(v) || 0) / max) * ph;
        };
        const slot = pw / Math.max(1, points.length);
        const bw = Math.max(3, Math.min(22, slot * 0.32));

        let bars = '';
        points.forEach(function(p, i) {
            const cx = x(i);
            const rx = Number(p.rx_bytes) || 0;
            const tx = Number(p.tx_bytes) || 0;
            bars += '<rect x="' + (cx - bw - 1).toFixed(1) + '" y="' + y(rx).toFixed(1) +
                '" width="' + bw.toFixed(1) + '" height="' + Math.max(0, B - y(rx)).toFixed(1) +
                '" fill="rgba(56,189,248,.55)" stroke="#38bdf8"/>';
            bars += '<rect x="' + (cx + 1).toFixed(1) + '" y="' + y(tx).toFixed(1) +
                '" width="' + bw.toFixed(1) + '" height="' + Math.max(0, B - y(tx)).toFixed(1) +
                '" fill="rgba(52,211,153,.55)" stroke="#34d399"/>';
        });

        const first = new Date(points[0].t).toLocaleDateString('pt-BR', {day:'2-digit',month:'2-digit'});
        const last = new Date(points[points.length - 1].t).toLocaleDateString('pt-BR', {day:'2-digit',month:'2-digit'});

        return '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" style="width:100%;height:300px;display:block">' +
            '<line x1="' + L + '" y1="' + T + '" x2="' + R + '" y2="' + T + '" stroke="#334155" stroke-dasharray="4 5"/>' +
            '<line x1="' + L + '" y1="' + ((T+B)/2) + '" x2="' + R + '" y2="' + ((T+B)/2) + '" stroke="#334155" stroke-dasharray="4 5"/>' +
            '<line x1="' + L + '" y1="' + B + '" x2="' + R + '" y2="' + B + '" stroke="#475569"/>' +
            '<text x="' + (L-9) + '" y="' + (T+4) + '" text-anchor="end" fill="#8190a5" font-size="12">' + fmtBytes(max) + '</text>' +
            '<text x="' + (L-9) + '" y="' + (((T+B)/2)+4) + '" text-anchor="end" fill="#8190a5" font-size="12">' + fmtBytes(max/2) + '</text>' +
            '<text x="' + (L-9) + '" y="' + (B+4) + '" text-anchor="end" fill="#8190a5" font-size="12">0</text>' +
            bars +
            '<text x="' + L + '" y="274" fill="#8190a5" font-size="11">' + first + '</text>' +
            '<text x="' + R + '" y="274" text-anchor="end" fill="#8190a5" font-size="11">' + last + '</text>' +
            '</svg>' +
            '<div class="jrc-wan-history-legend"><span><i class="rx"></i>Download</span><span><i class="tx"></i>Upload</span></div>';
    }

    async function loadHistory() {
        const card = ensureCard();
        if (!card) return;

        const login = findPppoeLogin();
        if (!login) {
            card.querySelector('.jrc-wan-history-chart').innerHTML =
                '<div class="jrc-wan-history-empty">Usuário PPPoE não identificado.</div>';
            return;
        }

        lastLogin = login;

        try {
            const url = '/api/get-wan-consumption-ixc.php?login=' +
                encodeURIComponent(login) + '&hours=' + encodeURIComponent(currentHours);

            const response = await fetch(url, {cache:'no-store'});
            const data = await response.json();
            if (!response.ok || !data || !data.success) {
                throw new Error(data && data.message ? data.message : 'IXC');
            }

            const s = data.summary || {};
            const set = function(name, value) {
                const el = card.querySelector('[data-stat="' + name + '"]');
                if (el) el.textContent = value;
            };

            set('rx-total', fmtBytes(s.rx_bytes));
            set('tx-total', fmtBytes(s.tx_bytes));
            set('rx-avg', fmtBytes(s.rx_avg_bytes));
            set('tx-avg', fmtBytes(s.tx_avg_bytes));
            set('rx-peak', fmtBytes(s.rx_peak_bytes));
            set('tx-peak', fmtBytes(s.tx_peak_bytes));

            card.querySelector('.jrc-wan-history-chart').innerHTML = chart(data.points || []);
        } catch (error) {
            card.querySelector('.jrc-wan-history-chart').innerHTML =
                '<div class="jrc-wan-history-empty">Não foi possível consultar o histórico do IXC.</div>';
        }
    }

    const observer = new MutationObserver(function() {
        const card = ensureCard();
        if (!card) return;
        const login = findPppoeLogin();
        if (login && login !== lastLogin) loadHistory();
    });

    observer.observe(document.documentElement, {childList:true, subtree:true});

    document.addEventListener('DOMContentLoaded', function() {
        ensureCard();
        setTimeout(loadHistory, 800);
    });

    setInterval(function() {
        const card = ensureCard();
        if (card) loadHistory();
    }, 60000);
})();