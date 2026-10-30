/* JRC_TR069_APTO_V1 — quantidade de ONU com TR-069 habilitado e apta a receber o ACS. */
(function () {
    if (!/dashboard\.php$/i.test(location.pathname)) return;

    var LABEL = 'equipamentos monitorados';
    var NEXT = 'TR-069 apto';
    var ID = 'jrc-tr069-apto';
    var HINT = 'ONU com TR-069 habilitado e online agora. Apta a receber as funções do ACS (último contato há menos de 5 minutos).';
    var cached = null;
    var failed = false;
    var painting = false;
    var started = false;

    function norm(value) {
        return String(value || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function fold(value) {
        return norm(value).toLowerCase()
            .replace(/[áàâã]/g, 'a')
            .replace(/[éê]/g, 'e')
            .replace(/í/g, 'i')
            .replace(/[óôõ]/g, 'o')
            .replace(/ú/g, 'u')
            .replace(/ç/g, 'c');
    }

    function collectLabels() {
        var wanted = {
            'equipamentos monitorados': 'equipamentos',
            'alertas ativos': 'alertas',
            'sinal fraco': 'fraco',
            'sinal critico': 'critico'
        };
        var groups = { equipamentos: [], alertas: [], fraco: [], critico: [] };
        if (!document.body) return groups;
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        var node;
        while ((node = walker.nextNode())) {
            var parent = node.parentElement;
            if (!parent || (parent.closest && (parent.closest('#sidebar') || parent.closest('#' + ID)))) continue;
            var key = wanted[fold(node.nodeValue)];
            if (key && groups[key].indexOf(parent) === -1) groups[key].push(parent);
        }
        return groups;
    }

    function pickPlacement() {
        var groups = collectLabels();
        var best = null;
        function consider(nodes) {
            var row = commonParent(nodes);
            if (!row || row === document.body || row === document.documentElement) return;
            var cols = nodes.map(function (leaf) { return columnOf(leaf, row); });
            if (cols.some(function (col) { return !col; })) return;
            var unique = [];
            cols.forEach(function (col) {
                if (unique.indexOf(col) === -1) unique.push(col);
            });
            if (unique.length !== 4) return;
            var depth = row.querySelectorAll('*').length;
            if (!best || depth < best.depth) best = { row: row, unique: unique, depth: depth };
        }
        groups.equipamentos.forEach(function (equip) {
            groups.alertas.forEach(function (alert) {
                groups.fraco.forEach(function (weak) {
                    groups.critico.forEach(function (critical) {
                        consider([equip, alert, weak, critical]);
                    });
                });
            });
        });
        return best;
    }

    function commonParent(nodes) {
        function chain(el) {
            var list = [];
            while (el) {
                list.push(el);
                el = el.parentElement;
            }
            return list;
        }
        var lists = nodes.map(chain);
        var first = lists[0];
        for (var i = 0; i < first.length; i++) {
            var el = first[i];
            if (lists.every(function (list) { return list.indexOf(el) !== -1; })) return el;
        }
        return null;
    }

    function columnOf(leaf, row) {
        var el = leaf;
        while (el && el.parentElement && el.parentElement !== row) el = el.parentElement;
        return el && el.parentElement === row ? el : null;
    }

    function isValueText(text) {
        var value = norm(text);
        if (!value) return false;
        if (value === '-' || value === '—' || value === '–' || value === '…' || value === '...' || value === 'N/D' || value === 'N/A') return true;
        return /^[\d.\s]+$/.test(value) && /\d/.test(value);
    }

    function valueNode(card) {
        var best = null;
        var bestSize = -1;
        var bestLen = -1;
        var all = card.querySelectorAll('*');
        for (var i = 0; i < all.length; i++) {
            var el = all[i];
            if (el.closest && el.closest('.fb-stat-icon')) continue;
            var text = norm(el.textContent);
            var lower = text.toLowerCase();
            if (!isValueText(text) || lower.indexOf('equipamento') !== -1 || lower.indexOf('tr-069') !== -1) continue;
            var nested = false;
            for (var c = 0; c < el.children.length; c++) {
                if (norm(el.children[c].textContent) === text) nested = true;
            }
            if (nested) continue;
            var size = parseFloat(getComputedStyle(el).fontSize) || 0;
            if (size > bestSize || (size === bestSize && text.length > bestLen)) {
                best = el;
                bestSize = size;
                bestLen = text.length;
            }
        }
        return best;
    }

    function formatCount(value) {
        return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function applyText(card, text) {
        var icon = card.querySelector('.fb-stat-icon, i, svg');
        var stray = card.querySelectorAll('.jrc-tr069-num');
        for (var i = 0; i < stray.length; i++) {
            var extra = stray[i];
            if (icon && extra.parentElement === card && (extra.compareDocumentPosition(icon) & Node.DOCUMENT_POSITION_FOLLOWING)) extra.remove();
        }
        var node = valueNode(card);
        if (node) {
            if (norm(node.textContent) !== text) node.textContent = text;
            node.classList.add('jrc-tr069-num');
        }
        var labels = document.createTreeWalker(card, NodeFilter.SHOW_TEXT);
        var labelNode;
        while ((labelNode = labels.nextNode())) {
            if (norm(labelNode.nodeValue).toLowerCase() === LABEL) labelNode.nodeValue = NEXT;
        }
    }

    function forceRow(row) {
        var count = row.children.length;
        row.classList.add('jrc-tr069-row');
        row.style.setProperty('display', 'grid', 'important');
        row.style.setProperty('grid-template-columns', 'repeat(' + count + ', minmax(0, 1fr))', 'important');
        row.style.setProperty('grid-auto-flow', 'row', 'important');
        row.style.setProperty('align-items', 'stretch', 'important');
        row.style.setProperty('width', '100%', 'important');
        for (var i = 0; i < row.children.length; i++) {
            var child = row.children[i];
            child.style.setProperty('width', 'auto', 'important');
            child.style.setProperty('max-width', 'none', 'important');
            child.style.setProperty('min-width', '0', 'important');
            child.style.setProperty('grid-column', 'auto', 'important');
            child.style.setProperty('grid-row', 'auto', 'important');
        }
    }

    function ensureStyle() {
        if (document.getElementById('jrc-tr069-apto-style')) return;
        var style = document.createElement('style');
        style.id = 'jrc-tr069-apto-style';
        style.textContent = '.jrc-tr069-row{display:grid !important;grid-template-columns:repeat(5,minmax(0,1fr)) !important;gap:14px !important;width:100% !important;align-items:stretch !important}#jrc-tr069-apto{width:auto !important;max-width:none !important;min-width:0 !important;min-height:124px !important;flex:1 1 0% !important;box-sizing:border-box !important}.jrc-tr069-fallback{display:flex;flex-direction:column;gap:6px;width:min(240px,100%);margin:0 0 16px;padding:16px 18px;background:#1a1c21;border:1px solid #2a2d34;border-radius:12px;color:#f3f5f8}.jrc-tr069-fallback .jrc-tr069-num{font-size:28px;line-height:1}.jrc-tr069-fallback span{color:#8d939c;font-size:13px}@media (max-width:1100px){.jrc-tr069-row{grid-template-columns:repeat(2,minmax(0,1fr)) !important}}@media (max-width:860px){.jrc-tr069-row{grid-template-columns:1fr !important}}';
        document.head.appendChild(style);
    }

    function mount() {
        ensureStyle();
        var place = pickPlacement();
        if (!place) return null;
        var row = place.row;
        var unique = place.unique;
        var sample = unique[0];
        var clone = sample.cloneNode(true);
        clone.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
        clone.querySelectorAll('[onclick]').forEach(function (el) { el.removeAttribute('onclick'); });
        clone.removeAttribute('onclick');
        clone.id = ID;
        clone.classList.add('jrc-tr069-card');
        clone.setAttribute('title', HINT);
        var icon = clone.querySelector('i, svg');
        if (icon) icon.style.setProperty('color', '#3ddc97', 'important');
        applyText(clone, cached == null ? '…' : formatCount(cached));
        var extra = false;
        for (var child = row.firstElementChild; child; child = child.nextElementSibling) {
            if (unique.indexOf(child) !== -1) continue;
            var rect = child.getBoundingClientRect();
            if (rect.width > 80 && rect.height > 24) extra = true;
        }
        var host = row;
        if (extra) {
            host = document.createElement('div');
            host.className = 'jrc-tr069-row';
            row.insertBefore(host, sample);
            unique.forEach(function (col) { host.appendChild(col); });
            host.style.setProperty('grid-column', '1 / -1', 'important');
        }
        host.appendChild(clone);
        if (extra) {
            host.style.setProperty('width', '100%', 'important');
            host.style.setProperty('min-width', '0', 'important');
            host.style.setProperty('flex', '1 1 auto', 'important');
        }
        forceRow(host);
        return clone;
    }

    function fallback() {
        ensureStyle();
        var host = document.querySelector('.content-wrapper') || document.querySelector('.main-content') || document.body;
        if (!host) return null;
        var card = document.createElement('section');
        card.id = ID;
        card.className = 'jrc-tr069-card jrc-tr069-fallback';
        card.setAttribute('title', HINT);
        card.innerHTML = '<strong class="jrc-tr069-num">…</strong><span>TR-069 apto</span>';
        host.insertBefore(card, host.firstChild);
        return card;
    }

    function seenAge(device) {
        var ts = Number(device && device.last_inform_timestamp);
        if (!ts && device && device.last_inform && device.last_inform !== 'N/A') {
            var parsed = Date.parse(String(device.last_inform).replace(' ', 'T'));
            if (!isNaN(parsed)) ts = parsed / 1000;
        }
        if (!ts) return null;
        if (ts > 1e12) ts = ts / 1000;
        return (Date.now() / 1000) - ts;
    }

    function isApt(device) {
        var status = String((device && device.status) || '').toLowerCase();
        if (status === 'online') return true;
        if (status === 'offline') return false;
        var age = seenAge(device);
        return age !== null && age < 300 && age > -120;
    }

    function deviceKey(device, index) {
        if (device && (device.device_id || device.serial_number || device._id)) {
            return String(device.device_id || device.serial_number || device._id);
        }
        return 'row-' + index;
    }

    function readFleetCount() {
        try {
            var data = JSON.parse(sessionStorage.getItem('jrc-fleet-v1') || 'null');
            if (!data || !data.at || Date.now() - data.at >= 300000 || !data.fleetDone) return null;
            var list = data.devices || [];
            if (!list.length) return data.failed ? { failed: true } : { count: 0 };
            var seen = Object.create(null);
            var total = 0;
            for (var i = 0; i < list.length; i++) {
                var key = deviceKey(list[i], i);
                if (seen[key]) continue;
                seen[key] = 1;
                if (isApt(list[i])) total++;
            }
            return { count: total };
        } catch (e) {
            return null;
        }
    }

    function paintCount(card) {
        if (!card) return;
        if (cached != null) applyText(card, formatCount(cached));
        else if (failed) applyText(card, '—');
        else applyText(card, '…');
    }

    function ensure() {
        if (painting) return;
        painting = true;
        try {
            var existing = document.getElementById(ID);
            var place = pickPlacement();
            var parked = existing && place && place.row.contains(existing) && !existing.classList.contains('jrc-tr069-fallback');
            if (existing && parked) {
                if (existing.parentElement) forceRow(existing.parentElement);
                paintCount(existing);
                return;
            }
            if (existing) existing.remove();
            var card = mount();
            if (!card && (cached != null || failed)) card = fallback();
            if (!card) return;
            paintCount(card);
        } finally {
            painting = false;
        }
    }

    function watch() {
        if (!document.documentElement || document.documentElement.dataset.jrcTr069Watch) return;
        document.documentElement.dataset.jrcTr069Watch = '1';
        new MutationObserver(function () {
            if (!painting) ensure();
        }).observe(document.documentElement, { childList: true, subtree: true, characterData: true });
    }

    function pull() {
        var hit = readFleetCount();
        if (!hit) return;
        if (hit.failed) {
            cached = null;
            failed = true;
            var card = document.getElementById(ID);
            if (card) card.setAttribute('title', 'Não foi possível contar as ONU aptas agora.');
        } else {
            cached = hit.count;
            failed = false;
        }
        ensure();
    }

    function run() {
        if (started) return;
        started = true;
        watch();
        ensure();
        pull();
        window.addEventListener('jrc-fleet', pull);
        setInterval(pull, 500);
    }

    function boot() {
        run();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
