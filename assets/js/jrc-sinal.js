/* JRC_SINAL_CONSULTA_V1 — consulta de ONU com sinal crítico, fraco ou sem leitura. */
/* JRC_SINAL_PREFETCH_V1 — a frota e o sinal começam a carregar em qualquer tela. */
(function () {
    if (window.__jrcSinal) return;
    window.__jrcSinal = true;
    if (/\/(login|logout|speedtest)\.php$/i.test(location.pathname)) return;

    var ID = 'jrc-sinal';
    var PAGE = 10;
    var CACHE_KEY = 'jrc-sinal-cache-v3';
    var FLEET_KEY = 'jrc-fleet-v1';
    var ALERT_KEY = 'jrc-alert-count-v1';
    var GET_KEY = 'jrc-get-v1';
    var IXC_KEY = 'jrc-ixc-v1';
    var CACHE_MS = 5 * 60 * 1000;
    var MISS = 'Parte da consulta ao IXC não respondeu. Esses equipamentos ficaram só com o sinal do TR-069.';
    var bands = { critico: [], fraco: [], sem: [], parado: [] };
    var current = 'critico';
    var query = '';
    var page = 1;
    var failed = false;
    var loading = true;
    var revealed = false;
    var fromCache = false;
    var note = '';
    var progress = 'Lendo a frota…';
    var generation = 0;
    var fleet = [];
    var fleetReady = false;
    var ixcMap = Object.create(null);
    var ready = Object.create(null);
    var openAlerts = null;
    var acking = false;

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatCount(value) {
        return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function rxNumber(raw) {
        if (raw == null) return null;
        var text = String(raw).trim().replace(',', '.');
        if (!text || text === 'N/A' || text === 'N/D' || text === '—') return null;
        var value = parseFloat(text);
        if (!isFinite(value) || value === -999) return null;
        if (value > 100) value = (value / 100) - 40;
        if (value < -1000) value = value / 100;
        if (Math.abs(value) < 0.001) return null;
        return value;
    }

    function normSerial(value) {
        return String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    function bandOf(value) {
        if (value == null) return 'sem';
        if (value <= -27) return 'critico';
        if (value <= -25) return 'fraco';
        return 'ok';
    }

    function onReports() {
        return /view-logs\.php$/i.test(location.pathname);
    }

    function signalStamp(raw) {
        var floor = 946684800000;
        if (raw == null || raw === '' || raw === 'N/A' || raw === 'N/D') return 0;
        if (typeof raw === 'number' && isFinite(raw)) {
            var ms = raw > 1e12 ? raw : raw * 1000;
            return ms >= floor ? ms : 0;
        }
        var text = String(raw).trim();
        if (/^0{4}/.test(text) || /^00\/00\//.test(text)) return 0;
        if (/^\d+$/.test(text)) {
            var numeric = Number(text);
            var numericMs = numeric > 1e12 ? numeric : numeric * 1000;
            return numericMs >= floor ? numericMs : 0;
        }
        var br = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/);
        if (br) {
            if (+br[3] < 2000) return 0;
            var brMs = new Date(+br[3], +br[2] - 1, +br[1], +(br[4] || 0), +(br[5] || 0), +(br[6] || 0)).getTime();
            return brMs >= floor ? brMs : 0;
        }
        var parsed = Date.parse(text.replace(' ', 'T'));
        return isNaN(parsed) || parsed < floor ? 0 : parsed;
    }

    function ageLabel(ms, item) {
        if (item && item.band === 'parado' && !ms) return 'sem data';
        if (!ms) return '—';
        var diff = Math.max(0, Date.now() - ms) / 1000;
        var days = Math.floor(diff / 86400);
        if (days >= 1) return 'há ' + days + ' d';
        var hours = Math.floor(diff / 3600);
        if (hours >= 1) return 'há ' + hours + ' h';
        var minutes = Math.floor(diff / 60);
        return 'há ' + Math.max(1, minutes) + ' min';
    }

    function modelOf(device) {
        var model = String((device && device.product_class) || '').trim();
        if (model && model !== 'N/A' && model !== 'N/D') return model;
        var brand = String((device && device.manufacturer) || '').trim();
        if (!brand || brand === 'N/A' || brand === 'N/D') return 'Equipamento';
        var words = brand.split(',')[0].trim().split(/\s+/);
        if (words.length > 2) return words[0];
        return words.join(' ') || 'Equipamento';
    }

    function clientOf(item) {
        var device = item.device || item;
        var ixc = item.ixc || {};
        var client = String(ixc.pppoe_username || device.pppoe_username || device.ixc_pppoe_username || '').trim();
        if (!client || client === 'N/A' || client === 'N/D') return '—';
        return client;
    }

    function haystack(item) {
        var device = item.device;
        return [modelOf(device), device.manufacturer, device.product_class, device.serial_number, clientOf(item), device.ip_tr069, device.ip].join(' ').toLowerCase();
    }

    function visible() {
        var list = bands[current] || [];
        var text = query.trim().toLowerCase();
        if (!text) return list;
        return list.filter(function (item) { return haystack(item).indexOf(text) !== -1; });
    }

    function offlineKey(item) {
        var id = alertIdentity(item && item.device);
        return id ? 'offline:' + id : '';
    }

    function unackedStopped(list) {
        var acks = ackMap();
        return (list || []).filter(function (item) {
            var key = offlineKey(item);
            return key && !acks[key];
        });
    }

    function signalCell(item) {
        if (item.band === 'parado') {
            var known = !!ackMap()[offlineKey(item)];
            return '<span class="jrc-sinal-pill ' + (known ? 'is-ack' : 'is-muted') + '"><i class="bi bi-circle-fill"></i>' + (known ? 'Reconhecida' : 'Sem contato') + '</span>';
        }
        if (item.band === 'sem' || item.rx == null) {
            return '<span class="jrc-sinal-pill is-muted"><i class="bi bi-circle-fill"></i>Sem leitura</span>';
        }
        var klass = item.band === 'critico' ? 'is-bad' : 'is-warn';
        return '<span class="jrc-sinal-pill ' + klass + '"><i class="bi bi-circle-fill"></i>' + esc(Number(item.rx).toFixed(2)) + ' dBm</span>';
    }

    function paint() {
        var root = document.getElementById(ID);
        if (!root) return;
        root.querySelectorAll('[data-band]').forEach(function (button) {
            var band = button.getAttribute('data-band');
            button.classList.toggle('is-on', band === current);
            var count = button.querySelector('strong');
            if (count) count.textContent = failed ? '—' : (revealed ? formatCount(bands[band].length) : '…');
        });
        var statusNode = root.querySelector('.jrc-sinal-status');
        if (statusNode) {
            var status = loading ? progress : (fromCache ? 'Lista guardada. Vale por alguns minutos.' : '');
            statusNode.hidden = !status;
            statusNode.textContent = status;
        }
        var noteNode = root.querySelector('.jrc-sinal-note');
        if (noteNode) {
            noteNode.hidden = !note;
            noteNode.textContent = note;
        }
        var ackButton = root.querySelector('.jrc-sinal-ack');
        if (ackButton) {
            var pending = (current === 'parado' && revealed && !failed) ? unackedStopped(visible()) : [];
            var showAck = current === 'parado' && revealed && !failed && (pending.length > 0 || acking);
            ackButton.hidden = !showAck;
            ackButton.disabled = acking || !pending.length;
            ackButton.textContent = acking ? 'Reconhecendo…' : ('Reconhecer estas' + (pending.length ? ' (' + formatCount(pending.length) + ')' : ''));
        }
        var body = root.querySelector('.jrc-sinal-body');
        var pager = root.querySelector('.jrc-sinal-pager');
        if (!body || !pager) return;
        if (failed) {
            body.innerHTML = '<p class="jrc-sinal-error">Não foi possível consultar as ONUs agora.</p>';
            pager.innerHTML = '';
            return;
        }
        if (!revealed) {
            body.innerHTML = '<p class="jrc-sinal-empty">' + esc(progress || 'Lendo a frota…') + '</p>';
            pager.innerHTML = '';
            return;
        }
        var list = visible();
        var pages = Math.max(1, Math.ceil(list.length / PAGE));
        if (page > pages) page = pages;
        var start = (page - 1) * PAGE;
        var slice = list.slice(start, start + PAGE);
        if (!list.length) {
            var empty = query.trim()
                ? 'Nenhuma ONU encontrada para essa busca.'
                : (current === 'parado'
                    ? (loading ? 'Procurando logins desconectados há mais de 60 dias…' : 'Nenhum login de ONU desconectado há mais de 60 dias.')
                    : (loading ? 'Nenhuma ONU neste grupo até agora.' : 'Nenhuma ONU neste grupo.'));
            body.innerHTML = '<p class="jrc-sinal-empty">' + empty + '</p>';
            pager.innerHTML = '';
            return;
        }
        var rows = slice.map(function (item) {
            var device = item.device;
            var href = '/device-detail.php?id=' + encodeURIComponent(device.device_id || '');
            return '<tr><td><a class="jrc-sinal-equip" href="' + href + '"><span class="jrc-sinal-icon"><i class="bi bi-router"></i></span><span><strong>' + esc(modelOf(device)) + '</strong><small>' + esc(device.serial_number || '—') + '</small></span></a></td><td class="jrc-sinal-muted">' + esc(clientOf(item)) + '</td><td>' + signalCell(item) + '</td><td class="jrc-sinal-muted">' + esc(ageLabel(item.signalAt, item)) + '</td></tr>';
        }).join('');
        var ageHead = current === 'parado' ? 'Sem contato' : 'Leitura';
        body.innerHTML = '<table><thead><tr><th>Equipamento</th><th>Cliente</th><th>Sinal</th><th>' + ageHead + '</th></tr></thead><tbody>' + rows + '</tbody></table>';
        var from = start + 1;
        var to = start + slice.length;
        pager.innerHTML = '<button type="button" data-page="prev"' + (page <= 1 ? ' disabled' : '') + '>Anterior</button><span>' + from + '–' + to + ' de ' + formatCount(list.length) + '</span><button type="button" data-page="next"' + (page >= pages ? ' disabled' : '') + '>Próxima</button>';
    }

    function shell() {
        if (document.getElementById(ID)) return;
        var host = document.querySelector('.content-wrapper') || document.querySelector('.main-content');
        if (!host) return;
        var section = document.createElement('section');
        section.id = ID;
        section.innerHTML = '<div class="jrc-sinal-head"><h2>Consulta de sinal</h2><div class="jrc-sinal-actions"><button type="button" class="jrc-sinal-ack" hidden>Reconhecer estas</button><button type="button" class="jrc-sinal-download">Baixar</button><button type="button" class="jrc-sinal-refresh">Atualizar</button></div></div><p class="jrc-sinal-sub">Mesma leitura da frota. Crítico é −27 dBm ou pior. Fraco fica entre −27 e −25 dBm. Sem leitura é ONU da fibra sem valor óptico. Offline +60 dias são ONUs da fibra cujo login está desconectado no IXC há mais de 60 dias.</p><p class="jrc-sinal-status" hidden></p><p class="jrc-sinal-note" hidden></p><div class="jrc-sinal-filters"><button type="button" data-band="critico" class="is-on">Crítico <strong>…</strong></button><button type="button" data-band="fraco">Fraco <strong>…</strong></button><button type="button" data-band="sem">Sem leitura <strong>…</strong></button><button type="button" data-band="parado">Offline +60 dias <strong>…</strong></button></div><input class="jrc-sinal-search" type="search" placeholder="Buscar equipamento, serial ou cliente" aria-label="Buscar ONU"><div class="jrc-sinal-body"><p class="jrc-sinal-empty">Lendo a frota…</p></div><div class="jrc-sinal-pager"></div>';
        host.insertBefore(section, host.firstChild);
        section.addEventListener('click', function (event) {
            if (event.target.closest('.jrc-sinal-download')) {
                downloadList();
                return;
            }
            if (event.target.closest('.jrc-sinal-refresh')) {
                clearWarm();
                run(false);
                return;
            }
            if (event.target.closest('.jrc-sinal-ack')) {
                acknowledgeStopped();
                return;
            }
            var band = event.target.closest('[data-band]');
            if (band) {
                current = band.getAttribute('data-band');
                page = 1;
                paint();
                return;
            }
            var move = event.target.closest('[data-page]');
            if (!move || move.disabled) return;
            page += move.getAttribute('data-page') === 'next' ? 1 : -1;
            paint();
        });
        section.querySelector('.jrc-sinal-search').addEventListener('input', function (event) {
            query = event.target.value || '';
            page = 1;
            paint();
        });
    }

    function foldLogs() {
        var box = document.getElementById('logs-container');
        if (!box) return;
        var card = box.closest('.card') || box.parentElement;
        if (!card || card.getAttribute('data-jrc-logs') === '1') return;
        card.setAttribute('data-jrc-logs', '1');
        card.classList.add('jrc-logs-card');
        var heading = card.querySelector('h5') || card.querySelector('h4') || card.querySelector('.card-title');
        if (heading) heading.innerHTML = 'Logs técnicos <small>console do navegador</small>';
        var toggle = card.querySelector('.card-header') || heading;
        if (!toggle) return;
        toggle.classList.add('jrc-logs-toggle');
        toggle.addEventListener('click', function (event) {
            if (event.target.closest('button, a, select, input, textarea, label')) return;
            card.classList.toggle('is-open');
        });
    }

    function readJson(url, options) {
        options = options || {};
        var method = String(options.method || 'GET').toUpperCase();
        var timeout = options.timeout || 20000;
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, timeout) : 0;
        var init = {
            method: method,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }
        };
        if (method !== 'GET' && method !== 'HEAD' && options.body != null) init.body = options.body;
        if (controller) init.signal = controller.signal;
        return fetch(url, init).then(function (response) {
            return response.text();
        }).then(function (text) {
            if (timer) clearTimeout(timer);
            if (!text || !String(text).trim()) return { success: false, message: 'resposta vazia' };
            try { return JSON.parse(text); }
            catch (e) { return { success: false, message: 'resposta inválida' }; }
        }, function (error) {
            if (timer) clearTimeout(timer);
            if (error && error.name === 'AbortError') return { success: false, error: 'timeout', message: 'Request timeout' };
            return { success: false, message: (error && error.message) || 'falha' };
        });
    }

    async function load(url, options) {
        options = options || {};
        var init = {
            method: options.method || 'GET',
            body: options.body,
            timeout: options.timeout || 20000,
            silent: true
        };
        if (typeof fetchAPI === 'function') return fetchAPI(url, init);
        return readJson(url, init);
    }

    function ixcFor(serial) {
        if (!serial) return null;
        if (ixcMap[serial]) return ixcMap[serial];
        var key = normSerial(serial);
        return key && ixcMap[key] ? ixcMap[key] : null;
    }

    function serialReady(device) {
        var serial = normSerial(device && device.serial_number);
        if (!serial) return true;
        return !!ready[serial];
    }

    function classify(device) {
        var ixc = ixcFor(device && device.serial_number);
        var onFiber = !!(ixc && ixc.found);
        var rx = onFiber ? rxNumber(ixc.rx_power) : rxNumber(device && device.rx_power);
        if (!onFiber && rx == null) return null;
        var band = bandOf(rx);
        if (band === 'ok') return null;
        var signalAt = onFiber ? signalStamp(ixc.last_signal_update || ixc.data_sinal) : 0;
        return { device: device, ixc: onFiber ? ixc : null, rx: rx, band: band, signalAt: signalAt };
    }

    function contactMs(device) {
        var at = signalStamp(device && device.last_inform_timestamp);
        if (at) return at;
        return signalStamp(device && device.last_inform);
    }

    function loginState(ixc) {
        return String(ixc && ixc.online != null ? ixc.online : '').trim().toLowerCase();
    }

    function loginUp(ixc) {
        var online = loginState(ixc);
        return online === 's' || online === 'sim' || online === '1' || online === 'true' || online === 'online';
    }

    function loginKnownDown(ixc) {
        var online = loginState(ixc);
        return online === 'n' || online === 'nao' || online === 'não' || online === 'ss' || online === '0' || online === 'false' || online === 'offline';
    }

    function stoppedOnu(device) {
        if (!serialReady(device)) return null;
        var ixc = ixcFor(device && device.serial_number);
        if (!ixc || !ixc.found || !loginKnownDown(ixc)) return null;
        var end = signalStamp(ixc.disconnected_since);
        if (!end) end = signalStamp(ixc.last_connection_end);
        if (end && Date.now() - end < 60 * 86400 * 1000) return null;
        return { device: device, ixc: ixc, rx: rxNumber(ixc.rx_power), band: 'parado', signalAt: end || 0 };
    }

    function csvCell(value) {
        var text = String(value == null ? '' : value);
        if (/[;"\n\r]/.test(text)) return '"' + text.replace(/"/g, '""') + '"';
        return text;
    }

    function downloadList() {
        var lines = ['modelo;serial;cliente;sinal_dbm;leitura'];
        visible().forEach(function (item) {
            var device = item.device || {};
            var rx = item.band === 'sem' || item.rx == null ? '' : Number(item.rx).toFixed(2);
            lines.push([modelOf(device), device.serial_number || '', clientOf(item), rx, ageLabel(item.signalAt, item)].map(csvCell).join(';'));
        });
        var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'sinal-' + current + '.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
    }

    function sortBands() {
        function byRx(a, b) { return a.rx - b.rx; }
        bands.critico.sort(byRx);
        bands.fraco.sort(byRx);
        bands.sem.sort(function (a, b) {
            return String(modelOf(a.device)).localeCompare(String(modelOf(b.device)), 'pt');
        });
        bands.parado.sort(function (a, b) { return (a.signalAt || 0) - (b.signalAt || 0); });
    }

    function rebuild() {
        bands.critico = [];
        bands.fraco = [];
        bands.sem = [];
        bands.parado = [];
        fleet.forEach(function (device) {
            if (!serialReady(device)) return;
            var item = classify(device);
            if (item) bands[item.band].push(item);
            var stopped = stoppedOnu(device);
            if (stopped) bands.parado.push(stopped);
        });
        sortBands();
    }

    function deviceKey(device, index) {
        return String((device && (device.device_id || device.serial_number)) || ('row-' + index));
    }

    function remember(rows, partial) {
        try {
            sessionStorage.setItem(CACHE_KEY, JSON.stringify({ at: Date.now(), v: 6, note: note, partial: !!partial, rows: rows }));
        } catch (e) {}
    }

    function slimDevice(device) {
        device = device || {};
        return {
            device_id: device.device_id || '',
            serial_number: device.serial_number || '',
            status: device.status || '',
            last_inform: device.last_inform || '',
            last_inform_timestamp: device.last_inform_timestamp || 0
        };
    }

    function publishFleet(signalDone) {
        try {
            sessionStorage.setItem(FLEET_KEY, JSON.stringify({
                at: Date.now(),
                fleetDone: !!fleetReady,
                signalDone: !!signalDone,
                failed: !!failed,
                note: note,
                devices: fleet.map(slimDevice)
            }));
            window.dispatchEvent(new Event('jrc-fleet'));
        } catch (e) {}
    }

    function listedRows() {
        return bands.critico.concat(bands.fraco, bands.sem, bands.parado);
    }

    function saveCache() {
        var rows = listedRows().map(function (item) {
            var device = item.device || {};
            var client = clientOf(item);
            return {
                device_id: device.device_id || '',
                serial_number: device.serial_number || '',
                manufacturer: device.manufacturer || '',
                product_class: device.product_class || '',
                pppoe_username: client === '—' ? '' : client,
                ip_tr069: device.ip_tr069 || '',
                ip: device.ip || '',
                rx: item.rx,
                last_inform: device.last_inform || '',
                last_inform_timestamp: device.last_inform_timestamp || 0,
                signal_at: item.signalAt || 0,
                band: item.band
            };
        });
        remember(rows, false);
        fleetReady = true;
        publishFleet(true);
    }

    function savePartial() {
        var rows = listedRows().map(function (item) {
            var device = item.device || {};
            var client = clientOf(item);
            return {
                device_id: device.device_id || '',
                serial_number: device.serial_number || '',
                manufacturer: device.manufacturer || '',
                product_class: device.product_class || '',
                pppoe_username: client === '—' ? '' : client,
                ip_tr069: device.ip_tr069 || '',
                ip: device.ip || '',
                rx: item.rx,
                last_inform: device.last_inform || '',
                last_inform_timestamp: device.last_inform_timestamp || 0,
                signal_at: item.signalAt || 0,
                band: item.band
            };
        });
        remember(rows, true);
        publishFleet(false);
    }

    function restore() {
        try {
            var raw = sessionStorage.getItem(CACHE_KEY);
            if (!raw) return false;
            var data = JSON.parse(raw);
            if (!data || data.v !== 6 || !data.at || Date.now() - data.at >= CACHE_MS) {
                return false;
            }
            bands.critico = [];
            bands.fraco = [];
            bands.sem = [];
            bands.parado = [];
            (data.rows || []).forEach(function (row) {
                if (!row || !bands[row.band]) return;
                var device = {
                    device_id: row.device_id || '',
                    serial_number: row.serial_number || '',
                    manufacturer: row.manufacturer || '',
                    product_class: row.product_class || '',
                    pppoe_username: row.pppoe_username || '',
                    ip_tr069: row.ip_tr069 || '',
                    ip: row.ip || '',
                    last_inform: row.last_inform || '',
                    last_inform_timestamp: row.last_inform_timestamp || 0
                };
                bands[row.band].push({
                    device: device,
                    ixc: row.pppoe_username ? { found: true, pppoe_username: row.pppoe_username } : null,
                    rx: row.rx,
                    band: row.band,
                    signalAt: row.signal_at || 0
                });
            });
            sortBands();
            note = data.note || '';
            failed = false;
            loading = false;
            revealed = true;
            fromCache = true;
            var root = document.getElementById(ID);
            if (root) root.setAttribute('data-source', 'cache');
            return true;
        } catch (e) {
            return false;
        }
    }

    function markReady(serial) {
        var key = normSerial(serial);
        if (key) ready[key] = 1;
    }

    function storeIxc(result) {
        Object.keys(result.devices || {}).forEach(function (key) {
            ixcMap[key] = result.devices[key];
            var normal = normSerial(key);
            if (normal) ixcMap[normal] = result.devices[key];
        });
    }

    async function consult(mine) {
        var seenIds = Object.create(null);
        var seenSerial = Object.create(null);
        var skip = 0;
        fleet = [];
        var buffer = [];
        var queue = [];
        var running = 0;
        var missed = false;
        var done = 0;
        var serialTotal = 0;
        var fleetDone = false;
        var waiting = [];
        var batchSize = 100;
        var parallel = 3;

        function finishWait() {
            if (!fleetDone || queue.length || running) return;
            waiting.splice(0).forEach(function (fn) { fn(); });
        }

        function noteProgress() {
            progress = 'Consultando o sinal… ' + formatCount(done) + ' de ' + formatCount(serialTotal);
            note = missed ? MISS : '';
            rebuild();
            paint();
            saveAlertCount();
            applyDashboard();
        }

        function launch(batch) {
            running++;
            load('/api/get-devices-ixc-batch.php', {
                method: 'POST',
                body: JSON.stringify({ serial_numbers: batch }),
                timeout: 60000
            }).then(function (result) {
                return result;
            }, function () {
                return null;
            }).then(function (result) {
                running--;
                if (mine !== generation) {
                    queue = [];
                    waiting.splice(0).forEach(function (fn) { fn(); });
                    return;
                }
                if (!result || result.success === false || !result.devices) missed = true;
                else storeIxc(result);
                batch.forEach(markReady);
                done += batch.length;
            revealed = true;
            noteProgress();
            savePartial();
            pump();
                finishWait();
            });
        }

        function pump() {
            while (running < parallel && queue.length && mine === generation) launch(queue.shift());
        }

        function enqueue(force) {
            while (buffer.length >= batchSize || (force && buffer.length)) queue.push(buffer.splice(0, batchSize));
            pump();
        }

        for (var i = 0; i < 40; i++) {
            if (mine !== generation) return null;
            var data = await load('/api/get-devices.php?limit=100&skip=' + skip);
            if (mine !== generation) return null;
            if (!data || data.success === false) throw new Error((data && data.message) || 'lista indisponível');
            var list = data.devices || [];
            var fresh = 0;
            for (var n = 0; n < list.length; n++) {
                var key = deviceKey(list[n], skip + n);
                if (seenIds[key]) continue;
                seenIds[key] = 1;
                fleet.push(list[n]);
                fresh++;
                var serial = normSerial(list[n].serial_number);
                if (serial && !seenSerial[serial]) {
                    seenSerial[serial] = 1;
                    buffer.push(list[n].serial_number);
                    serialTotal++;
                }
            }
            progress = 'Lendo a frota… ' + formatCount(fleet.length);
            saveAlertCount();
            applyDashboard();
            paint();
            enqueue(false);
            if (fresh === 0 || data.hasMore === false) break;
            if (list.length < 100 && data.hasMore !== true) break;
            skip += 100;
        }
        if (mine !== generation) return null;
        fleetDone = true;
        fleetReady = true;
        publishFleet(false);
        saveAlertCount();
        applyDashboard();
        enqueue(true);
        if (queue.length || running) await new Promise(function (resolve) { waiting.push(resolve); });
        if (mine !== generation) return null;
        return missed;
    }

    var warmGeneration = 0;
    var getFlight = Object.create(null);
    var ixcFlight = Object.create(null);
    var rawFetchAPI = null;

    function urlKey(url) {
        try {
            var parsed = new URL(url, location.origin);
            return parsed.pathname + parsed.search;
        } catch (e) {
            return String(url || '');
        }
    }

    function warmGet(url) {
        var key = urlKey(url);
        return /\/api\/get-devices\.php(?:\?|$)/.test(key)
            || /\/api\/recent-devices\.php(?:\?|$)/.test(key)
            || /\/api\/uplink-stats\.php(?:\?|$)/.test(key)
            || /\/api\/get-client-log\.php(?:\?|$)/.test(key)
            || /\/api\/jrc-alertas\.php\?action=list(?:&|$)/.test(key);
    }

    function readStore(name) {
        try { return JSON.parse(sessionStorage.getItem(name) || '{}'); }
        catch (e) { return {}; }
    }

    function writeStore(name, data) {
        try { sessionStorage.setItem(name, JSON.stringify(data)); }
        catch (e) {
            try { sessionStorage.removeItem(name); } catch (err) {}
        }
    }

    function cachedGet(url) {
        var hit = readStore(GET_KEY)[urlKey(url)];
        if (!hit || !hit.at || Date.now() - hit.at >= CACHE_MS) return null;
        return hit.data;
    }

    function storeGet(url, data) {
        if (!data || data.success === false) return;
        var all = readStore(GET_KEY);
        all[urlKey(url)] = { at: Date.now(), data: data };
        writeStore(GET_KEY, all);
    }

    function ixcItems() {
        var all = readStore(IXC_KEY);
        return all.items || {};
    }

    function cachedIxc(serial) {
        var hit = ixcItems()[normSerial(serial)];
        if (!hit || !hit.at || Date.now() - hit.at >= CACHE_MS) return null;
        if (!hit.value || !Object.prototype.hasOwnProperty.call(hit.value, 'last_connection_end')) return null;
        return hit.value;
    }

    function storeIxcValue(serial, value, generation) {
        if (!value || generation !== warmGeneration) return;
        var all = readStore(IXC_KEY);
        if (!all.items) all.items = {};
        all.items[normSerial(serial)] = { at: Date.now(), value: value };
        writeStore(IXC_KEY, all);
    }

    function pickIxcDevice(map, serial) {
        if (!map) return null;
        if (map[serial]) return map[serial];
        var key = normSerial(serial);
        if (key && map[key]) return map[key];
        var names = Object.keys(map);
        for (var i = 0; i < names.length; i++) {
            if (normSerial(names[i]) === key) return map[names[i]];
        }
        return null;
    }

    function clearWarm() {
        warmGeneration++;
        try {
            sessionStorage.removeItem(CACHE_KEY);
            sessionStorage.removeItem(FLEET_KEY);
            sessionStorage.removeItem(GET_KEY);
            sessionStorage.removeItem(IXC_KEY);
            sessionStorage.removeItem(ALERT_KEY);
        } catch (e) {}
    }

    function installCache() {
        if (typeof fetchAPI !== 'function' || fetchAPI.jrcWarm) return;
        rawFetchAPI = fetchAPI;
        var wrapped = function (url, options) {
            options = options || {};
            var method = String(options.method || 'GET').toUpperCase();
            if (method === 'GET' && warmGet(url)) return serveGet(url, options);
            if (method === 'POST' && /get-devices-ixc-batch\.php/.test(String(url))) return serveIxc(url, options);
            return rawFetchAPI(url, options);
        };
        wrapped.jrcWarm = true;
        window.fetchAPI = wrapped;
    }

    function transport(url, options) {
        if (options && options.silent) return readJson(url, options);
        return rawFetchAPI(url, options);
    }

    function serveGet(url, options) {
        var hit = cachedGet(url);
        if (hit) return Promise.resolve(JSON.parse(JSON.stringify(hit)));
        var key = urlKey(url);
        if (!getFlight[key]) {
            var generation = warmGeneration;
            var started = Date.now();
            getFlight[key] = transport(url, options).then(function (data) {
                var kept = readStore(GET_KEY)[key];
                if (generation === warmGeneration && !(kept && kept.at > started)) storeGet(url, data);
                delete getFlight[key];
                return data;
            }, function (error) {
                delete getFlight[key];
                throw error;
            });
        }
        return getFlight[key].then(function (data) { return JSON.parse(JSON.stringify(data)); });
    }

    function serveIxc(url, options) {
        var requested = [];
        try { requested = (JSON.parse(options.body || '{}').serial_numbers || []).slice(); }
        catch (e) { return rawFetchAPI(url, options); }
        var devices = {};
        var missing = [];
        requested.forEach(function (serial) {
            var hit = cachedIxc(serial);
            if (hit) devices[serial] = hit;
            else missing.push(serial);
        });
        if (!missing.length) return Promise.resolve({ success: true, devices: devices });
        var flightKey = missing.map(normSerial).sort().join(',');
        if (!ixcFlight[flightKey]) {
            var generation = warmGeneration;
            ixcFlight[flightKey] = transport(url, {
                method: 'POST',
                body: JSON.stringify({ serial_numbers: missing }),
                timeout: options.timeout || 60000,
                silent: !!(options && options.silent)
            }).then(function (result) {
                delete ixcFlight[flightKey];
                if (!result || result.success === false || !result.devices) return result;
                missing.forEach(function (serial) {
                    var value = pickIxcDevice(result.devices, serial);
                    if (!value) return;
                    storeIxcValue(serial, value, generation);
                });
                return result;
            }, function (error) {
                delete ixcFlight[flightKey];
                throw error;
            });
        }
        return ixcFlight[flightKey].then(function (result) {
            var merged = JSON.parse(JSON.stringify(devices));
            if (result && result.devices) {
                missing.forEach(function (serial) {
                    var value = pickIxcDevice(result.devices, serial) || cachedIxc(serial);
                    if (value) merged[serial] = value;
                });
            }
            return { success: !(result && result.success === false), devices: merged, message: result && result.message };
        });
    }

    function warmExtra() {
        load('/api/jrc-alertas.php?action=list').then(function () {
            if (fleet.length) saveAlertCount();
        }).catch(function () {});
    }

    function alertIdentity(device) {
        var id = String((device && (device.device_id || device.serial_number)) || '').trim();
        return id && id !== 'N/A' && id !== 'N/D' ? id : '';
    }

    function alertRx(device) {
        var ixc = ixcFor(device && device.serial_number);
        var raw = device && device.rx_power;
        if (ixc && ixc.found && ixc.rx_power != null && ixc.rx_power !== '') raw = ixc.rx_power;
        var value = parseFloat(String(raw == null ? '' : raw).trim().replace(',', '.'));
        if (!isFinite(value) || value === -999) return null;
        return value;
    }

    function ackMap() {
        var hit = cachedGet('/api/jrc-alertas.php?action=list');
        return (hit && hit.acks) || {};
    }

    function mergeAcks(extra) {
        var url = '/api/jrc-alertas.php?action=list';
        var merged = {};
        var current = ackMap();
        Object.keys(current).forEach(function (key) { merged[key] = current[key]; });
        Object.keys(extra || {}).forEach(function (key) { merged[key] = extra[key]; });
        storeGet(url, { success: true, acks: merged });
        if (!fleet.length) {
            recallIxc();
            if (!recallDevices()) recallSlimFleet();
        }
        if (fleet.length) saveAlertCount();
    }

    function acknowledgeStopped() {
        if (acking || current !== 'parado') return;
        var pending = unackedStopped(visible());
        if (!pending.length) return;
        var count = pending.length;
        if (typeof window.confirm === 'function' && !window.confirm('Reconhecer ' + count + ' logins desconectados há mais de 60 dias? Elas saem dos alertas abertos.')) return;
        var items = pending.map(function (item) {
            var device = item.device || {};
            return {
                id: offlineKey(item),
                level: 'critico',
                title: 'ONU offline há mais de 60 dias',
                text: modelOf(device) + (item.signalAt ? ' está com o login desconectado há mais de 60 dias.' : ' está com o login desconectado, sem data de queda no IXC.'),
                device_id: alertIdentity(device),
                serial: device.serial_number || ''
            };
        }).filter(function (item) { return item.id; });
        if (!items.length) return;
        acking = true;
        paint();
        load('/api/jrc-alertas.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'ack-many', items: items }),
            timeout: 60000
        }).then(function (res) {
            acking = false;
            if (!res || res.success === false) {
                note = (res && res.message) || 'Não foi possível reconhecer estas ONUs.';
                paint();
                return;
            }
            mergeAcks(res.acks || {});
            if (note.indexOf('reconhecer') !== -1) note = '';
            paint();
        }, function () {
            acking = false;
            note = 'Não foi possível reconhecer estas ONUs.';
            paint();
        });
    }

    function countOpenAlerts() {
        var seen = Object.create(null);
        var acks = ackMap();
        var total = 0;
        fleet.forEach(function (device) {
            var id = alertIdentity(device);
            if (!id || seen[id]) return;
            seen[id] = 1;
            var online = String(device.status || '').toLowerCase() === 'online';
            var key = '';
            if (!online || stoppedOnu(device)) key = 'offline:' + id;
            else {
                var rx = alertRx(device);
                if (rx == null || rx > -25) return;
                key = 'rx:' + id;
            }
            if (acks[key]) return;
            total++;
        });
        return total;
    }

    function saveAlertCount() {
        if (!fleet.length) return openAlerts;
        var count = countOpenAlerts();
        openAlerts = count;
        try {
            sessionStorage.setItem(ALERT_KEY, JSON.stringify({ at: Date.now(), count: count }));
        } catch (e) {}
        paintAlertShortcut();
        return count;
    }

    function storedAlertCount() {
        try {
            var data = JSON.parse(sessionStorage.getItem(ALERT_KEY) || 'null');
            if (!data || data.count == null || Date.now() - data.at >= CACHE_MS) return null;
            return data.count;
        } catch (e) {
            return null;
        }
    }

    function foldText(value) {
        return String(value || '').toLowerCase()
            .replace(/[áàâã]/g, 'a').replace(/[éê]/g, 'e').replace(/í/g, 'i')
            .replace(/[óôõ]/g, 'o').replace(/[úü]/g, 'u').replace(/ç/g, 'c');
    }

    function ownText(el) {
        var own = '';
        for (var n = 0; n < el.childNodes.length; n++) {
            if (el.childNodes[n].nodeType === 3) own += el.childNodes[n].textContent;
        }
        return foldText(own).trim();
    }

    function normFigure(text) {
        return String(text || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function isFigure(text) {
        var value = normFigure(text);
        if (!value) return false;
        if (value === '-' || value === '—' || value === '–' || value === '…' || value === '...' || value === 'N/D' || value === 'N/A') return true;
        return /^[\d.\s]+$/.test(value) && /\d/.test(value);
    }

    var KPI_PHRASES = ['equipamentos monitorados', 'alertas ativos', 'sinal fraco', 'sinal critico', 'sem leitura', 'online nas ultimas', 'tr-069'];

    function phrasesIn(el) {
        var text = foldText(el.textContent);
        var found = [];
        for (var i = 0; i < KPI_PHRASES.length; i++) {
            if (text.indexOf(KPI_PHRASES[i]) !== -1) found.push(KPI_PHRASES[i]);
        }
        return found;
    }

    function figureNode(card) {
        if (!card || !card.querySelectorAll) return null;
        var best = null;
        var bestSize = -1;
        var bestLen = -1;
        var all = card.querySelectorAll('*');
        for (var i = 0; i < all.length; i++) {
            var el = all[i];
            if (el.closest && el.closest('.fb-stat-icon, svg, i')) continue;
            var text = normFigure(el.textContent);
            if (!isFigure(text)) continue;
            var nested = false;
            for (var c = 0; c < el.children.length; c++) {
                if (normFigure(el.children[c].textContent) === text) nested = true;
            }
            if (nested) continue;
            var size = parseFloat(window.getComputedStyle(el).fontSize) || 0;
            if (size > bestSize + 0.5 || (Math.abs(size - bestSize) <= 0.5 && text.length > bestLen)) {
                best = el;
                bestSize = size;
                bestLen = text.length;
            }
        }
        return best;
    }

    function cardFor(labelEl, phrase) {
        var el = labelEl;
        var fallback = null;
        for (var guard = 0; guard < 8 && el && el !== document.body && el !== document.documentElement; guard++) {
            var found = phrasesIn(el);
            if (found.length === 1 && found[0] === phrase) {
                if (figureNode(el)) return el;
                if (!fallback) fallback = el;
            } else if (found.length > 1) break;
            el = el.parentElement;
        }
        return fallback;
    }

    function writeFigure(card, value) {
        var node = figureNode(card);
        if (!node) return;
        var next = formatCount(value);
        if (normFigure(node.textContent) !== next) node.textContent = next;
    }

    function eachLabel(phrase, visit) {
        var nodes = document.querySelectorAll('article, section, li, a, h1, h2, h3, h4, h5, span, strong, b, p, small, div, label, td');
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            if (el.closest && el.closest('#jrc-sinal, #jrc-tr069-apto, table, #sidebar')) continue;
            var own = ownText(el);
            var full = foldText(el.textContent).replace(/\s+/g, ' ').trim();
            if (own !== phrase && full !== phrase) continue;
            visit(el);
        }
    }

    function bindBox(box, href, title) {
        if (!box || box.getAttribute('data-jrc-jump') === '1') return;
        box.setAttribute('data-jrc-jump', '1');
        box.setAttribute('data-jrc-href', href);
        box.style.cursor = 'pointer';
        box.title = title;
        box.addEventListener('click', function (event) {
            if (event.target.closest('a, button, input, select, textarea')) return;
            location.href = href;
        });
    }

    function paintAlertShortcut() {
        if (!/dashboard\.php$/i.test(location.pathname)) return;
        var count = openAlerts != null ? openAlerts : storedAlertCount();
        eachLabel('alertas ativos', function (label) {
            var box = cardFor(label, 'alertas ativos');
            if (!box) return;
            bindBox(box, '/alerts.php', 'Abrir os alertas');
            if (count == null) return;
            writeFigure(box, count);
        });
    }

    function bindSignalJumps() {
        if (!/dashboard\.php$/i.test(location.pathname)) return;
        var hrefs = {
            'sinal fraco': ['/view-logs.php?sinal=fraco', 'Abrir as ONUs com sinal fraco'],
            'sinal critico': ['/view-logs.php?sinal=critico', 'Abrir as ONUs com sinal crítico'],
            'sem leitura': ['/view-logs.php?sinal=sem', 'Abrir as ONUs sem leitura']
        };
        Object.keys(hrefs).forEach(function (phrase) {
            eachLabel(phrase, function (label) {
                var box = cardFor(label, phrase);
                if (box) bindBox(box, hrefs[phrase][0], hrefs[phrase][1]);
            });
        });
    }

    function bandCounts() {
        if (revealed) {
            return { critico: bands.critico.length, fraco: bands.fraco.length, sem: bands.sem.length };
        }
        try {
            var data = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null');
            if (!data || !data.rows || !data.at || Date.now() - data.at >= CACHE_MS) return null;
            var counts = { critico: 0, fraco: 0, sem: 0 };
            data.rows.forEach(function (row) {
                if (counts[row.band] != null) counts[row.band]++;
            });
            return counts;
        } catch (e) {
            return null;
        }
    }

    function paintSignalCounts() {
        if (!/dashboard\.php$/i.test(location.pathname)) return;
        var counts = bandCounts();
        if (!counts) return;
        var map = { 'sinal fraco': counts.fraco, 'sinal critico': counts.critico, 'sem leitura': counts.sem };
        Object.keys(map).forEach(function (phrase) {
            eachLabel(phrase, function (label) {
                var box = cardFor(label, phrase);
                if (!box || (box.closest && box.closest('#jrc-tr069-apto, #jrc-sinal, table'))) return;
                writeFigure(box, map[phrase]);
            });
        });
    }

    function applyDashboard() {
        bindSignalJumps();
        paintAlertShortcut();
        paintSignalCounts();
    }

    function watchDashboard() {
        if (!/dashboard\.php$/i.test(location.pathname) || window.__jrcDashWatch) return;
        window.__jrcDashWatch = true;
        var queued = false;
        var kick = function () {
            if (queued) return;
            queued = true;
            setTimeout(function () {
                queued = false;
                applyDashboard();
            }, 50);
        };
        kick();
        if (typeof MutationObserver === 'function' && document.body) {
            new MutationObserver(kick).observe(document.body, { childList: true, subtree: true, characterData: true });
        }
    }

    function applyBandQuery() {
        var band = '';
        try { band = new URLSearchParams(location.search).get('sinal') || ''; }
        catch (e) {}
        if (band === 'critico' || band === 'fraco' || band === 'sem' || band === 'parado') current = band;
    }

    function cacheReady() {
        try {
            var data = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null');
            return !!(data && data.v === 6 && data.at && Date.now() - data.at < CACHE_MS && data.rows && !data.partial);
        } catch (e) {
            return false;
        }
    }

    async function run(quiet) {
        var mine = ++generation;
        var show = !quiet && onReports();
        var keep = fromCache && revealed;
        if (!keep) {
            fromCache = false;
            revealed = false;
            fleetReady = false;
            page = 1;
            ready = Object.create(null);
            ixcMap = Object.create(null);
            fleet = [];
            bands.critico = [];
            bands.fraco = [];
            bands.sem = [];
            bands.parado = [];
        }
        loading = true;
        failed = false;
        note = keep ? note : '';
        progress = keep ? progress : 'Lendo a frota…';
        if (show && !keep) {
            shell();
            foldLogs();
            var root = document.getElementById(ID);
            if (root) root.removeAttribute('data-source');
            paint();
        }
        try {
            var missed = await consult(mine);
            if (missed == null || mine !== generation) return;
            revealed = true;
            loading = false;
            failed = false;
            progress = '';
            note = missed ? MISS : '';
            rebuild();
            fromCache = false;
            saveCache();
            saveAlertCount();
            applyDashboard();
            if (show || keep) paint();
        } catch (e) {
            if (mine !== generation) return;
            failed = true;
            loading = false;
            progress = '';
            fleetReady = true;
            publishFleet(false);
            if (show || keep) paint();
        }
    }

    function recallIxc() {
        var items = ixcItems();
        Object.keys(items).forEach(function (key) {
            var hit = items[key];
            if (!hit || !hit.value || !hit.at || Date.now() - hit.at >= CACHE_MS) return;
            ixcMap[key] = hit.value;
            var normal = normSerial(key);
            if (normal) ixcMap[normal] = hit.value;
        });
    }

    function recallDevices() {
        var store = readStore(GET_KEY);
        var pages = [];
        Object.keys(store).forEach(function (key) {
            if (!/\/api\/get-devices\.php(?:\?|$)/.test(key)) return;
            var hit = store[key];
            if (!hit || !hit.at || Date.now() - hit.at >= CACHE_MS || !hit.data || !hit.data.devices) return;
            var skip = 0;
            var match = key.match(/[?&]skip=(\d+)/);
            if (match) skip = Number(match[1]) || 0;
            pages.push({ skip: skip, devices: hit.data.devices, hasMore: hit.data.hasMore });
        });
        if (!pages.length) return false;
        pages.sort(function (a, b) { return a.skip - b.skip; });
        var merged = [];
        var seen = Object.create(null);
        var expect = 0;
        var complete = false;
        for (var i = 0; i < pages.length; i++) {
            if (pages[i].skip !== expect) return false;
            for (var n = 0; n < pages[i].devices.length; n++) {
                var device = pages[i].devices[n];
                var id = deviceKey(device, pages[i].skip + n);
                if (seen[id]) continue;
                seen[id] = 1;
                merged.push(device);
            }
            expect = pages[i].skip + pages[i].devices.length;
            if (pages[i].hasMore === false || pages[i].devices.length < 100) complete = true;
        }
        if (!complete || !merged.length) return false;
        fleet = merged;
        fleetReady = true;
        return true;
    }

    function recallSlimFleet() {
        var data = null;
        try { data = JSON.parse(sessionStorage.getItem(FLEET_KEY) || 'null'); }
        catch (e) { return false; }
        if (!data || !data.fleetDone || !data.devices || !data.devices.length || Date.now() - data.at >= CACHE_MS) return false;
        var rxBySerial = Object.create(null);
        try {
            var cache = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null');
            if (cache && cache.rows && cache.at && !cache.partial && Date.now() - cache.at < CACHE_MS) {
                cache.rows.forEach(function (row) {
                    var serial = normSerial(row && row.serial_number);
                    if (serial && row.rx != null && row.band !== 'sem') rxBySerial[serial] = row.rx;
                });
            }
        } catch (e) {}
        fleet = data.devices.map(function (device) {
            var copy = {
                device_id: device.device_id || '',
                serial_number: device.serial_number || '',
                status: device.status || '',
                last_inform: device.last_inform || '',
                last_inform_timestamp: device.last_inform_timestamp || 0
            };
            var serial = normSerial(copy.serial_number);
            if (serial && rxBySerial[serial] != null && !ixcFor(serial)) copy.rx_power = rxBySerial[serial];
            return copy;
        });
        fleetReady = true;
        return true;
    }

    function primeDashboard() {
        if (!/dashboard\.php$/i.test(location.pathname)) return;
        recallIxc();
        if (!recallDevices()) recallSlimFleet();
        if (fleet.length) saveAlertCount();
    }

    function boot() {
        installCache();
        primeDashboard();
        watchDashboard();
        warmExtra();
        if (onReports()) {
            shell();
            foldLogs();
            applyBandQuery();
            if (restore()) {
                paint();
                if (!cacheReady()) run(true);
                return;
            }
            run(false);
            return;
        }
        if (!cacheReady()) run(true);
    }

    function start() {
        installCache();
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
        else boot();
    }

    if (typeof fetchAPI === 'function') installCache();

    var waitApi = 0;
    var waitTimer = setInterval(function () {
        waitApi++;
        if (typeof fetchAPI === 'function' || waitApi > 50) {
            clearInterval(waitTimer);
            start();
        }
    }, 100);
})();
