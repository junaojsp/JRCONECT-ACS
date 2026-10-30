/* JRC_ACOES_V1 - aba "Ações" do dispositivo (paleta + painel + resultados). */
(function () {
    'use strict';
    if (window.__jrcAcoes) return;
    window.__jrcAcoes = true;

    var API = '/api/device-tools.php';
    var GROUPS = [
        ['diagnostico', 'Diagnóstico'],
        ['wifi', 'Wi-Fi'],
        ['conexao', 'Conexão'],
        ['manutencao', 'Manutenção'],
        ['reinicio', 'Reinício']
    ];
    var DEFAULT_FAVS = ['ping', 'speed', 'wan-reconnect', 'reboot', 'backup'];
    var STATUS = {
        running: ['Em andamento', 'run'],
        queued: ['Enfileirado', 'wait'],
        ok: ['Concluído', 'ok'],
        fail: ['Falhou', 'fail']
    };

    var root = null;
    var S = {
        role: '',
        user: '',
        current: 'ping',
        query: '',
        history: [],
        historyEnabled: true,
        runs: [],
        favs: loadFavs(),
        cache: {},
        local: {},
        busy: {}
    };
    var seq = 0;

    function deviceId() {
        return String(window.DEVICE_ID || '');
    }
    function loadFavs() {
        try {
            var v = JSON.parse(localStorage.getItem('jrc_acoes_fav') || 'null');
            if (Array.isArray(v)) {
                var seen = {};
                return v.map(function (id) { return id === 'speed-down' || id === 'speed-up' ? 'speed' : id; }).filter(function (id) {
                    if (seen[id]) return false;
                    seen[id] = true;
                    return true;
                });
            }
        } catch (e) {}
        return DEFAULT_FAVS.slice();
    }
    function saveFavs() {
        try { localStorage.setItem('jrc_acoes_fav', JSON.stringify(S.favs)); } catch (e) {}
    }
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(msg, type) {
        if (typeof window.showToast === 'function') window.showToast(msg, type || 'info');
    }
    function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.text().then(function (t) {
                var j;
                try { j = JSON.parse(t); } catch (e) {
                    throw new Error(r.redirected ? 'Sessão expirada. Entre novamente no painel.' : 'Resposta inválida do servidor (HTTP ' + r.status + ').');
                }
                return j;
            });
        });
    }
    function api(action, extra) {
        return post(API, Object.assign({ action: action, device_id: deviceId() }, extra || {})).then(function (j) {
            if (!j.success) { var e = new Error(j.message || 'Falha na ação.'); e.data = j; throw e; }
            return j;
        });
    }

    function ago(ts) {
        var s = Math.max(0, Math.round(Date.now() / 1000 - ts));
        if (s < 60) return 'agora';
        if (s < 3600) return Math.floor(s / 60) + ' min';
        if (s < 86400) return Math.floor(s / 3600) + ' h';
        return Math.floor(s / 86400) + ' d';
    }

    /* ------------------------------------------------------------ catalogo */

    var A = {};
    function def(id, o) { o.id = id; A[id] = o; return o; }

    function hostField(id, value, label) {
        return field(label || 'Host de destino', '<input class="jra-input" data-f="host" id="jra-' + id + '-host" value="' + esc(value) + '" autocomplete="off" spellcheck="false" maxlength="253">');
    }
    function field(label, html, hint) {
        return '<label class="jra-field"><span>' + esc(label) + '</span>' + html + (hint ? '<small>' + hint + '</small>' : '') + '</label>';
    }
    function val(name) {
        var el = root.querySelector('[data-f="' + name + '"]');
        return el ? el.value.trim() : '';
    }
    function rawVal(name) {
        var el = root.querySelector('[data-f="' + name + '"]');
        return el ? el.value : '';
    }
    function confirmField(word, text) {
        return '<div class="jra-warn"><i class="bi bi-exclamation-triangle"></i><span>' + text + '</span></div>' +
            field('Digite ' + word + ' para confirmar', '<input class="jra-input" data-f="confirm" data-need="' + word + '" autocomplete="off" spellcheck="false" placeholder="' + word + '">');
    }
    function confirmOk() {
        var el = root.querySelector('[data-f="confirm"]');
        return !!el && el.value.trim().toUpperCase() === el.getAttribute('data-need');
    }
    function loading(text) { return '<div class="jra-note"><span class="jra-spin"></span>' + esc(text || 'Carregando...') + '</div>'; }
    function errorBox(msg) { return '<div class="jra-note is-err"><i class="bi bi-exclamation-circle"></i>' + esc(msg) + '</div>'; }

    function cached(key, loader) {
        var c = S.cache[key];
        if (c && c.data) return Promise.resolve(c.data);
        if (c && c.promise) return c.promise;
        var p = loader().then(function (d) { S.cache[key] = { data: d }; return d; }, function (e) { delete S.cache[key]; throw e; });
        S.cache[key] = { promise: p };
        return p;
    }
    function drop(key) { delete S.cache[key]; }

    function netOptions(nets) {
        return nets.map(function (n) {
            return '<option value="' + esc(n.key) + '">' + esc(n.ssid || n.label) + (n.band ? ' · ' + esc(n.band) : '') + (n.enabled ? '' : ' · desligada') + '</option>';
        }).join('');
    }
    function wifiForm(id, extraHtml, desc) {
        return function (st) {
            if (st.error) return errorBox(st.error);
            if (!st.data) return loading('Lendo as redes Wi-Fi do equipamento...');
            var nets = st.data.networks;
            return field('Rede', '<select class="jra-input" data-f="net">' + netOptions(nets) + '</select>') + extraHtml(nets);
        };
    }
    function loadWifi() { return cached('wifi', function () { return api('wifi_list'); }); }
    function loadWan() { return cached('wan', function () { return api('wan_list'); }); }

    function done(summary, extra) { return Object.assign({ status: 'ok', summary: summary }, extra || {}); }

    function serverResult(j) {
        return { status: j.status === 'queued' ? 'queued' : 'ok', summary: j.summary || j.message, serverLogged: true, target: j.target };
    }

    function pollDiag(tool, started) {
        var t0 = Date.now();
        return (function loop() {
            return sleep(started.poll_after_ms || 4000).then(function () {
                return api(tool + '_status', { root: started.root, task_id: started.task_id, started_at: started.started_at });
            }).then(function (s) {
                if (s.completed) return s;
                if (Date.now() - t0 > 170000) throw new Error('Tempo esgotado aguardando o equipamento.');
                return loop();
            });
        })();
    }

    function diagAction(id, tool, title, icon, desc, defHost, opts) {
        opts = opts || {};
        def(id, {
            group: 'diagnostico', title: title, icon: icon, risk: 'safe', desc: desc, hist: [id],
            form: function () {
                var h = hostField(id, defHost);
                if (opts.server) h += field('Servidor DNS (opcional)', '<input class="jra-input" data-f="server" placeholder="ex.: 1.1.1.1" autocomplete="off" maxlength="45">');
                return h;
            },
            canRun: function () { return val('host') !== ''; },
            run: function (run) {
                var host = val('host');
                run.target = host;
                return api(tool + '_start', { host: host, server: opts.server ? val('server') : undefined }).then(function (st) {
                    run.note = st.message;
                    renderResults();
                    return pollDiag(tool, st);
                }).then(function (s) {
                    if (!s.success) return { status: 'fail', summary: s.message };
                    return { status: s.result_status === 'ok' ? 'ok' : 'fail', summary: s.summary, detail: diagDetail(tool, s.data), route: tool === 'trace' ? routeLines(s.data) : '' };
                });
            }
        });
    }
    function diagDetail(tool, d) {
        if (tool === 'trace' && d && d.hops && d.hops.length) {
            return '<ol class="jra-hops">' + d.hops.map(function (h, idx) {
                var timing = '';
                if (h.rtt) timing = /\bms\b/i.test(String(h.rtt)) ? String(h.rtt) : String(h.rtt) + ' ms';
                else if (h.error && String(h.error) !== '0') timing = 'erro ' + h.error;
                var name = h.host && h.address && h.host !== h.address ? h.host : '';
                return '<li data-n="' + esc(h.n || (idx + 1)) + '"><span>' + esc(h.address || h.host || '*') + '</span><em>' + esc(name) + '</em><b>' + esc(timing) + '</b></li>';
            }).join('') + '</ol>';
        }
        if (tool === 'dns' && d && d.results && d.results.length) {
            return '<ul class="jra-kv">' + d.results.map(function (r) {
                return '<li><span>' + esc(r.name || '') + '</span><b>' + esc(r.ips || r.status) + '</b></li>';
            }).join('') + '</ul>';
        }
        if (tool === 'ping' && d) {
            return '<ul class="jra-kv"><li><span>Mín / Média / Máx</span><b>' + esc([d.min_ms, d.avg_ms, d.max_ms].map(function (v) { return v == null ? '-' : v; }).join(' / ')) + ' ms</b></li></ul>';
        }
        return '';
    }

    /* JRC_TRACE_HOPS_V1 — cada salto vira uma linha no historico e o cartao nao perde a lista. */
    function routeLines(d) {
        if (!d || !d.hops || !d.hops.length) return '';
        return d.hops.map(function (h, idx) {
            var addr = h.address || h.host || '*';
            var host = (h.host && h.address && h.host !== h.address) ? h.host : '';
            var rtt = h.rtt ? String(h.rtt) : '';
            if (rtt && !/\bms\b/i.test(rtt)) rtt = rtt + ' ms';
            return [h.n || (idx + 1), addr, host, rtt].join(' | ');
        }).join('\n');
    }
    function hopsFromSummary(summary) {
        var hops = [];
        String(summary || '').split('\n').slice(1).forEach(function (line) {
            var c = line.split(' | ');
            if (c.length < 2 || !/^\d+$/.test(String(c[0]).trim())) return;
            hops.push({
                n: Number(c[0]),
                address: c[1] || '',
                host: c[2] || '',
                rtt: String(c[3] || '').replace(/\s*ms\s*$/i, '')
            });
        });
        return hops;
    }

    /* JRC_SPEED_BOTH_V1 — um teste só, com download e upload no mesmo resultado. */
    function speedLine(row) {
        var name = row.phase === 'download' ? 'Download' : 'Upload';
        if (!row.ok) return name + ' indisponível';
        return name + ' ' + row.mbps + ' Mbps em ' + row.seconds + ' s' + (row.warning ? ' (amostra curta)' : '');
    }
    function speedDetail(rows) {
        return '<ul class="jra-kv">' + rows.map(function (row) {
            var name = row.phase === 'download' ? 'Download' : 'Upload';
            var value = row.ok ? (row.mbps + ' Mbps em ' + row.seconds + ' s') : row.message;
            return '<li><span>' + esc(name) + '</span><b>' + esc(value) + '</b></li>';
        }).join('') + '</ul>';
    }
    function measurePhase(run, phase) {
        var url = '/api/speedtest-onu.php';
        var label = phase === 'download' ? 'download' : 'upload';
        run.note = 'Medindo ' + label + '...';
        renderResults();
        return post(url, { action: 'start_' + phase, device_id: deviceId() }).then(function (st) {
            if (!st.success) throw new Error(st.message || ('Não foi possível iniciar o ' + label + '.'));
            var t0 = Date.now();
            return (function loop() {
                return sleep(3000).then(function () {
                    return post(url, { action: 'status_' + phase, device_id: deviceId() });
                }).then(function (s) {
                    if (s.completed && s.success) return s;
                    if (s.completed && !s.success) throw new Error(s.message || ('O equipamento recusou o ' + label + '.'));
                    if (s.success === false && !s.completed) throw new Error(s.message || ('Falha no ' + label + '.'));
                    if (Date.now() - t0 > 170000) throw new Error('Tempo esgotado no ' + label + '.');
                    run.note = s.message || ('Medindo ' + label + '...');
                    renderResults();
                    return loop();
                });
            })();
        }).then(function (r) {
            var mbps = phase === 'download' ? r.download_mbps : r.upload_mbps;
            return { ok: true, phase: phase, mbps: mbps, seconds: r.duration_seconds, warning: r.timing_warning || '' };
        }, function (e) {
            return { ok: false, phase: phase, message: e && e.message ? e.message : ('Falha no ' + label + '.') };
        });
    }
    function speedAction() {
        def('speed', {
            group: 'diagnostico', title: 'Velocidade', icon: 'bi-lightning-charge', risk: 'safe', hist: ['speed', 'speed-down', 'speed-up'],
            desc: 'Mede o download e o upload (TR-143) a partir do próprio equipamento, um depois do outro, com um arquivo de 100 MiB em cada etapa.',
            form: function () { return '<div class="jra-note"><i class="bi bi-info-circle"></i>O teste usa o servidor de testes da JR CONECT. Download e upload saem no mesmo resultado. Cada etapa leva de 20 a 90 segundos.</div>'; },
            canRun: function () { return true; },
            run: function (run) {
                run.target = '↓ ↑';
                return measurePhase(run, 'download').then(function (dl) {
                    return measurePhase(run, 'upload').then(function (ul) {
                        var rows = [dl, ul];
                        var ok = rows.some(function (row) { return row.ok; });
                        return { status: ok ? 'ok' : 'fail', summary: rows.map(speedLine).join(' · '), detail: speedDetail(rows) };
                    });
                });
            }
        });
    }

    diagAction('ping', 'ping', 'Ping', 'bi-reception-4', 'Testa conectividade e latência até um host, a partir do próprio equipamento.', '8.8.8.8');
    diagAction('trace', 'trace', 'TraceRoute', 'bi-box', 'Mostra o caminho percorrido até o host, coletado pelo equipamento do cliente.', '8.8.8.8');
    speedAction();
    diagAction('dns', 'dns', 'DNS lookup', 'bi-search', 'Resolve um nome usando o DNS do equipamento e mostra IP e tempo de resposta.', 'google.com', { server: true });

    def('wifi-ssid', {
        group: 'wifi', title: 'Trocar SSID', icon: 'bi-wifi', risk: 'warn', hist: ['wifi'],
        desc: 'Altera o nome da rede Wi-Fi. Os clientes conectados caem e precisam reconectar.',
        load: loadWifi,
        form: wifiForm('ssid', function () { return field('Novo nome da rede (SSID)', '<input class="jra-input" data-f="ssid" maxlength="32" autocomplete="off" placeholder="1 a 32 caracteres">'); }),
        canRun: function () { return val('ssid') !== ''; },
        run: function (run) {
            run.target = val('ssid');
            return api('wifi_set', { key: val('net'), ssid: rawVal('ssid') }).then(function (j) { drop('wifi'); return serverResult(j); });
        }
    });
    def('wifi-pass', {
        group: 'wifi', title: 'Trocar senha', icon: 'bi-lock', risk: 'warn', hist: ['wifi'],
        desc: 'Define uma nova senha WPA para a rede. Os dispositivos do cliente precisam reconectar com a nova senha.',
        load: loadWifi,
        form: wifiForm('pass', function (nets) {
            return field('Nova senha', '<div class="jra-pw"><input class="jra-input" data-f="password" type="password" maxlength="63" autocomplete="new-password" placeholder="8 a 63 caracteres"><button type="button" class="jra-eye" data-jra-eye title="Mostrar ou ocultar"><i class="bi bi-eye"></i></button></div>',
                nets.some(function (n) { return !n.can_password; }) ? 'Algumas redes deste modelo não permitem gravar a senha.' : '');
        }),
        canRun: function () { return rawVal('password').length >= 8; },
        run: function (run) {
            var net = netByKey(val('net'));
            run.target = net ? net.ssid : '';
            return api('wifi_set', { key: val('net'), password: rawVal('password') }).then(function (j) { drop('wifi'); j.summary = 'Senha da rede alterada'; return serverResult(j); });
        }
    });
    function netByKey(k) {
        var c = S.cache.wifi && S.cache.wifi.data;
        return c ? c.networks.filter(function (n) { return n.key === k; })[0] : null;
    }
    def('wifi-channel', {
        group: 'wifi', title: 'Trocar canal', icon: 'bi-wifi', risk: 'warn', hist: ['wifi'],
        desc: 'Fixa o canal do rádio ou volta para automático. Útil para fugir de interferência de vizinhos.',
        load: loadWifi,
        form: wifiForm('chan', function (nets) {
            return field('Canal', '<select class="jra-input" data-f="channel">' + channelOptions(nets[0]) + '</select>', '<span data-jra-chan-info>' + chanInfo(nets[0]) + '</span>');
        }),
        onChange: function (el) {
            if (el.getAttribute('data-f') !== 'net') return;
            var n = netByKey(el.value);
            var sel = root.querySelector('[data-f="channel"]');
            if (sel && n) sel.innerHTML = channelOptions(n);
            var info = root.querySelector('[data-jra-chan-info]');
            if (info && n) info.textContent = chanInfo(n);
        },
        canRun: function () { return val('channel') !== ''; },
        run: function (run) {
            var n = netByKey(val('net'));
            run.target = (n ? n.ssid + ' · ' : '') + (val('channel') === 'auto' ? 'auto' : 'canal ' + val('channel'));
            return api('wifi_set', { key: val('net'), channel: val('channel') }).then(function (j) { drop('wifi'); return serverResult(j); });
        }
    });
    function channelOptions(n) {
        var list = (n && n.band === '5 GHz') ? [36, 40, 44, 48, 52, 56, 60, 64, 100, 104, 108, 112, 116, 120, 124, 128, 132, 136, 140, 149, 153, 157, 161, 165]
            : [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13];
        return '<option value="auto">Automático</option>' + list.map(function (c) {
            return '<option value="' + c + '"' + (n && n.channel === c && !n.auto_channel ? ' selected' : '') + '>' + c + '</option>';
        }).join('');
    }
    function chanInfo(n) {
        if (!n) return '';
        return 'Atual: ' + (n.auto_channel ? 'automático' : (n.channel != null ? 'canal ' + n.channel : 'desconhecido')) + (n.band ? ' (' + n.band + ')' : '');
    }
    def('wifi-radio', {
        group: 'wifi', title: 'Ligar/desligar rádio', icon: 'bi-wifi', risk: 'warn', hist: ['wifi'],
        desc: 'Liga ou desliga o rádio Wi-Fi da rede escolhida. Desligar derruba todos os clientes sem fio dessa faixa.',
        load: loadWifi,
        form: wifiForm('radio', function () {
            return field('Estado do rádio', '<select class="jra-input" data-f="enabled"><option value="1">Ligar</option><option value="0">Desligar</option></select>');
        }),
        canRun: function () { return true; },
        run: function (run) {
            var n = netByKey(val('net'));
            var on = val('enabled') === '1';
            run.target = (n ? n.ssid : '') + ' · ' + (on ? 'ligar' : 'desligar');
            return api('wifi_set', { key: val('net'), enabled: on }).then(function (j) { drop('wifi'); return serverResult(j); });
        }
    });

    function wanSelect(onlyPppoe) {
        return function (st) {
            if (st.error) return errorBox(st.error);
            if (!st.data) return loading('Lendo as conexões WAN do equipamento...');
            var list = st.data.connections.filter(function (c) { return !onlyPppoe || (c.type === 'PPPoE' && c.can_credentials); });
            if (!list.length) return errorBox('Nenhuma conexão compatível foi coletada neste equipamento.');
            return field('Conexão', '<select class="jra-input" data-f="wan">' + list.map(function (c) {
                return '<option value="' + esc(c.path) + '"' + (c.management ? ' disabled' : '') + '>' + esc(c.name) + ' · ' + esc(c.type) + (c.username ? ' · ' + esc(c.username) : '') + (c.status ? ' · ' + esc(c.status) : '') + (c.management ? ' · gerência' : '') + '</option>';
            }).join('') + '</select>');
        };
    }
    function wanByPath(p) {
        var c = S.cache.wan && S.cache.wan.data;
        return c ? c.connections.filter(function (x) { return x.path === p; })[0] : null;
    }
    def('wan-reconnect', {
        group: 'conexao', title: 'Reconectar WAN', icon: 'bi-arrow-repeat', risk: 'warn', hist: ['wan'],
        desc: 'Desliga e religa a conexão WAN escolhida para forçar uma nova sessão PPPoE. O cliente fica sem internet por alguns segundos.',
        load: loadWan,
        form: function (st) { return wanSelect(false)(st); },
        canRun: function () { return !!val('wan'); },
        run: function (run) {
            var c = wanByPath(val('wan'));
            run.target = c ? c.name : '';
            return api('wan_reconnect', { path: val('wan') }).then(function (j) { drop('wan'); return serverResult(j); });
        }
    });
    def('inform', {
        group: 'conexao', title: 'Intervalo de Inform', icon: 'bi-clock', risk: 'warn', hist: ['inform'],
        desc: 'Define de quanto em quanto tempo o equipamento fala com o ACS. Intervalos muito curtos aumentam a carga do servidor.',
        load: function () { return cached('inform', function () { return api('inform_get'); }); },
        form: function (st) {
            if (st.error) return errorBox(st.error);
            if (!st.data) return loading('Lendo o intervalo atual...');
            return field('Intervalo (segundos)', '<input class="jra-input" data-f="interval" type="number" min="30" max="86400" step="1" value="' + esc(st.data.interval || 300) + '">',
                'Atual: ' + (st.data.interval != null ? st.data.interval + ' s' : 'desconhecido') + '. Valores comuns: 300, 600, 3600.');
        },
        canRun: function () { var n = parseInt(val('interval'), 10); return n >= 30 && n <= 86400; },
        run: function (run) {
            run.target = val('interval') + ' s';
            return api('inform_set', { interval: parseInt(val('interval'), 10) }).then(function (j) { drop('inform'); return serverResult(j); });
        }
    });
    def('pppoe', {
        group: 'conexao', title: 'Credencial PPPoE', icon: 'bi-box', risk: 'danger', hist: ['pppoe'], role: 'admin',
        desc: 'Troca usuário e senha PPPoE gravados no equipamento. Credencial errada deixa o cliente sem internet.',
        load: loadWan,
        form: function (st) {
            var sel = wanSelect(true)(st);
            if (!st.data) return sel;
            return sel + field('Usuário PPPoE', '<input class="jra-input" data-f="username" autocomplete="off" spellcheck="false" maxlength="64">') +
                field('Senha PPPoE', '<div class="jra-pw"><input class="jra-input" data-f="password" type="password" autocomplete="new-password" maxlength="64"><button type="button" class="jra-eye" data-jra-eye title="Mostrar ou ocultar"><i class="bi bi-eye"></i></button></div>') +
                confirmField('CONFIRMAR', 'A senha atual não pode ser lida do equipamento. Confira usuário e senha no IXC antes de enviar.');
        },
        onChange: function (el) {
            if (el.getAttribute('data-f') !== 'wan') return;
            var c = wanByPath(el.value);
            var u = root.querySelector('[data-f="username"]');
            if (u && c && !u.value) u.value = c.username || '';
        },
        afterForm: function () {
            var c = wanByPath(val('wan'));
            var u = root.querySelector('[data-f="username"]');
            if (u && c && !u.value) u.value = c.username || '';
        },
        canRun: function () { return !!val('wan') && val('username') !== '' && rawVal('password') !== '' && confirmOk(); },
        run: function (run) {
            run.target = val('username');
            return api('pppoe_set', { path: val('wan'), username: val('username'), password: rawVal('password'), confirm: 'CONFIRMAR' })
                .then(function (j) { drop('wan'); return serverResult(j); });
        }
    });
    def('stun', {
        group: 'conexao', title: 'Habilitar STUN', icon: 'bi-lightning-charge', risk: 'warn', hist: ['stun'],
        desc: 'Liga ou desliga o STUN do TR-069, usado para alcançar equipamentos atrás de NAT.',
        load: function () { return cached('stun', function () { return api('stun_get'); }); },
        form: function (st) {
            if (st.error) return errorBox(st.error);
            if (!st.data) return loading('Lendo o estado do STUN...');
            if (!st.data.supported) return errorBox('Este equipamento não informou suporte a STUN.');
            return field('Estado', '<select class="jra-input" data-f="enabled"><option value="1"' + (st.data.enabled ? '' : ' selected') + '>Habilitar</option><option value="0"' + (st.data.enabled ? ' selected' : '') + '>Desabilitar</option></select>', 'Atual: ' + (st.data.enabled ? 'habilitado' : 'desabilitado')) +
                field('Servidor STUN (opcional)', '<input class="jra-input" data-f="server" value="' + esc(st.data.server || '') + '" autocomplete="off" maxlength="253">');
        },
        canRun: function () { return !!root.querySelector('[data-f="enabled"]'); },
        run: function (run) {
            var on = val('enabled') === '1';
            run.target = on ? 'habilitar' : 'desabilitar';
            return api('stun_set', { enabled: on, server: on ? val('server') : '' }).then(function (j) { drop('stun'); return serverResult(j); });
        }
    });

    function download(name, obj) {
        var blob = new Blob([JSON.stringify(obj, null, 2)], { type: 'application/json' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }
    def('backup', {
        group: 'manutencao', title: 'Backup de config', icon: 'bi-bar-chart-line', risk: 'safe', hist: ['backup'],
        desc: 'Salva em um arquivo os parâmetros gravaveis do equipamento coletados no ACS. Serve para restaurar depois neste mesmo equipamento.',
        form: function () {
            return '<div class="jra-note"><i class="bi bi-info-circle"></i>O arquivo é baixado no seu computador. Wi-Fi e senhas só entram no backup para o perfil Admin. Dados da ACS (URL, credenciais do ACS) nunca entram.</div>';
        },
        canRun: function () { return true; },
        run: function (run) {
            return api('backup').then(function (j) {
                var m = j.backup.meta;
                var d = new Date();
                var stamp = d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0') + '-' + String(d.getHours()).padStart(2, '0') + String(d.getMinutes()).padStart(2, '0');
                var name = 'backup-' + (m.serial || 'cpe').replace(/[^A-Za-z0-9_-]/g, '') + '-' + stamp + '.json';
                download(name, j.backup);
                run.target = name;
                return { status: 'ok', summary: j.summary, target: name };
            });
        }
    });
    def('restore', {
        group: 'manutencao', title: 'Restaurar config', icon: 'bi-arrow-repeat', risk: 'danger', hist: ['restore'], role: 'admin',
        desc: 'Reaplica um backup neste equipamento. Primeiro mostra o que vai mudar; só envia depois da sua confirmação.',
        form: function (st) {
            var h = field('Arquivo de backup (.json)', '<input class="jra-input jra-file" data-f="file" type="file" accept="application/json,.json">');
            if (st.preview) {
                var p = st.preview;
                h += '<div class="jra-note"><i class="bi bi-clipboard-check"></i>' + esc(p.summary) + (p.missing ? ' · ' + p.missing + ' ausentes no equipamento' : '') + (p.skipped ? ' · ' + p.skipped + ' ignorados por segurança' : '') + '</div>';
                if (p.changes.length) {
                    h += '<div class="jra-diff">' + p.changes.slice(0, 60).map(function (c) {
                        return '<div><code>' + esc(c.p.replace(/^(InternetGatewayDevice|Device)\./, '')) + '</code><span>' + esc(c.from) + ' → <b>' + esc(c.to) + '</b></span></div>';
                    }).join('') + (p.changes.length > 60 ? '<div><code>+' + (p.changes.length - 60) + ' parâmetros</code></div>' : '') + '</div>';
                    h += confirmField('RESTAURAR', 'Restaurar pode derrubar Wi-Fi e a conexão do cliente. Faça isso com o cliente avisado.');
                }
            }
            return h;
        },
        afterForm: function (st) {
            var input = root.querySelector('[data-f="file"]');
            if (input && st.file && window.DataTransfer) {
                try { var dt = new DataTransfer(); dt.items.add(st.file); input.files = dt.files; } catch (e) {}
            }
        },
        onChange: function (el) {
            if (el.getAttribute('data-f') !== 'file') return;
            var st = stateFor('restore');
            var f = el.files && el.files[0] || null;
            if (f === st.file) return;
            st.file = f;
            st.preview = null;
            st.backup = null;
            renderPanel();
        },
        buttonLabel: function (st) { return st.preview && st.preview.changes.length ? 'Aplicar restauração' : 'Pré-visualizar'; },
        canRun: function (st) {
            if (!st.file) return false;
            if (st.preview && st.preview.changes.length) return confirmOk();
            return true;
        },
        run: function (run, st) {
            if (st.preview && st.preview.changes.length && st.backup) {
                return api('restore_apply', { backup: st.backup, confirm: 'RESTAURAR' }).then(function (j) {
                    st.preview = null; st.backup = null; st.file = null;
                    run.target = 'backup';
                    scheduleRender();
                    return serverResult(j);
                });
            }
            return st.file.text().then(function (t) {
                try { st.backup = JSON.parse(t); } catch (e) { throw new Error('O arquivo não é um JSON válido.'); }
                return api('restore_preview', { backup: st.backup });
            }).then(function (j) {
                st.preview = j;
                scheduleRender();
                run.skip = true;
                return { status: 'ok', summary: j.summary };
            });
        },
        reset: function (st) { st.preview = null; st.backup = null; st.file = null; }
    });
    def('firmware', {
        group: 'manutencao', title: 'Atualizar firmware', icon: 'bi-gear', risk: 'danger', hist: ['firmware'], role: 'admin',
        desc: 'Envia um firmware já cadastrado no GenieACS para o equipamento. Ele baixa, instala e reinicia.',
        load: function () { return cached('files', function () { return api('files_list'); }); },
        form: function (st) {
            if (st.error) return errorBox(st.error);
            if (!st.data) return loading('Lendo os arquivos de firmware do GenieACS...');
            var files = st.data.files;
            if (!files.length) return errorBox('Nenhum arquivo de firmware cadastrado no GenieACS (tipo "1 Firmware Upgrade Image").');
            return field('Arquivo de firmware', '<select class="jra-input" data-f="file">' + files.map(function (f) {
                return '<option value="' + esc(f.name) + '"' + (f.compatible ? '' : ' disabled') + '>' + esc(f.name) + (f.version ? ' · ' + esc(f.version) : '') + (f.compatible ? '' : ' · incompatível') + '</option>';
            }).join('') + '</select>', 'Equipamento: OUI ' + esc(st.data.device_oui) + ' · modelo ' + esc(st.data.device_product_class) + '. Arquivos incompatíveis ficam bloqueados.') +
                confirmField('ATUALIZAR', 'Não desligue a ONU durante a atualização. Um firmware errado pode inutilizar o equipamento.');
        },
        canRun: function () { return !!val('file') && confirmOk(); },
        run: function (run) {
            run.target = val('file');
            return api('firmware_start', { file: val('file'), confirm: 'ATUALIZAR' }).then(function (j) { return serverResult(j); });
        }
    });
    def('capabilities', {
        group: 'manutencao', title: 'Sondar capacidades', icon: 'bi-hdd', risk: 'safe', hist: ['capabilities'],
        desc: 'Pede ao equipamento para reler seu modelo de dados e mostra quais ferramentas ele realmente suporta.',
        form: function (st) {
            var h = '<div class="jra-note"><i class="bi bi-info-circle"></i>Use antes de testar diagnósticos em um modelo novo. "Ler resultado" apenas consulta o que o ACS já tem.</div>';
            if (S.role === 'atendimento') {
                h += '<div class="jra-note"><i class="bi bi-lock-fill"></i>Pedir uma nova leitura ao equipamento é restrito a Admin e NOC. "Ler resultado" mostra o que o ACS já coletou.</div>';
            }
            if (st.caps) {
                var c = st.caps;
                h += '<div class="jra-caps-head"><b>' + esc(c.manufacturer) + ' ' + esc(c.product_class) + '</b><span>' + esc(c.data_model) + (c.software_version ? ' · ' + esc(c.software_version) : '') + '</span></div>' +
                    '<ul class="jra-caps">' + c.features.map(function (f) {
                        return '<li class="' + (f.ok ? 'is-ok' : 'is-no') + '"><i class="bi ' + (f.ok ? 'bi-check-circle-fill' : 'bi-dash-circle') + '"></i>' + esc(f.label) + '</li>';
                    }).join('') + '</ul>';
            }
            return h;
        },
        secondary: { label: 'Ler resultado', run: function (st) {
            return api('capabilities').then(function (j) { st.caps = j; scheduleRender(); toast('Capacidades atualizadas.', 'success'); });
        } },
        canRun: function () { return S.role !== 'atendimento'; },
        run: function (run, st) {
            return api('capabilities_probe').then(function (j) {
                st.caps = j;
                scheduleRender();
                var ok = j.features.filter(function (f) { return f.ok; }).length;
                return { status: 'ok', summary: ok + ' de ' + j.features.length + ' recursos disponíveis · ' + j.data_model };
            });
        }
    });
    def('refresh', {
        group: 'manutencao', title: 'Atualizar coleta', icon: 'bi-arrow-clockwise', risk: 'safe', hist: ['refresh'],
        desc: 'Pede ao equipamento uma nova coleta TR-069 dos objetos de WAN e LAN.',
        form: function () { return '<div class="jra-note"><i class="bi bi-info-circle"></i>Os novos valores aparecem nas outras abas depois da próxima comunicação do equipamento.</div>'; },
        canRun: function () { return true; },
        run: function () {
            return post('/api/device-actions.php', { device_id: deviceId(), action: 'refresh' }).then(function (j) {
                if (!j.success) throw new Error(j.message || 'Falha ao solicitar a coleta.');
                setTimeout(function () { if (typeof window.loadDeviceDetail === 'function') window.loadDeviceDetail(true); }, 4000);
                return { status: 'ok', summary: j.message || 'Nova coleta solicitada.' };
            });
        }
    });
    def('reboot', {
        group: 'reinicio', title: 'Reiniciar', icon: 'bi-arrow-repeat', risk: 'warn', hist: ['reboot'], role: 'ops',
        desc: 'Reinicia o equipamento remotamente via TR-069. O cliente fica offline por alguns minutos.',
        form: function () { return confirmField('REINICIAR', 'O cliente ficará temporariamente sem internet enquanto o equipamento reinicia.'); },
        canRun: function () { return confirmOk(); },
        run: function () {
            return post('/api/device-actions.php', { device_id: deviceId(), action: 'reboot', confirm: 'REINICIAR' }).then(function (j) {
                if (!j.success) throw new Error(j.message || 'Falha ao enviar o reinício.');
                return { status: j.status === 'queued' ? 'queued' : 'ok', summary: j.message || 'Comando de reinício enviado.' };
            });
        }
    });
    def('factory', {
        group: 'reinicio', title: 'Restaurar de fábrica', icon: 'bi-exclamation-triangle', risk: 'danger', hist: ['factory'], role: 'admin',
        desc: 'Apaga toda a configuração do equipamento e volta ao padrão de fábrica. Wi-Fi, PPPoE e provisionamento são perdidos.',
        form: function () { return confirmField('RESTAURAR', 'Esta ação é irreversível. O equipamento só volta a funcionar depois de ser provisionado de novo.'); },
        canRun: function () { return confirmOk(); },
        run: function (run) {
            return api('factory_reset', { confirm: 'RESTAURAR' }).then(function (j) { return serverResult(j); });
        }
    });

    var ORDER = ['ping', 'trace', 'speed', 'dns', 'wifi-ssid', 'wifi-pass', 'wifi-channel', 'wifi-radio',
        'wan-reconnect', 'inform', 'pppoe', 'stun', 'backup', 'restore', 'firmware', 'capabilities', 'refresh', 'reboot', 'factory'];

    function allowed(a) {
        if (!S.role) return true;
        if (a.role === 'admin') return S.role === 'admin';
        if (a.role === 'ops') return S.role === 'admin' || S.role === 'noc';
        if (a.id === 'wan-reconnect' || a.id === 'inform' || a.id === 'stun' || a.id === 'backup') return S.role === 'admin' || S.role === 'noc';
        return true;
    }
    function lockText(a) {
        return a.role === 'admin' || a.id === 'pppoe' ? 'Somente Admin' : 'Somente Admin e NOC';
    }

    /* ----------------------------------------------------------- estado/UI */

    function stateFor(id) {
        return S.local[id] || (S.local[id] = {});
    }

    function shell() {
        root.innerHTML =
            '<div class="jra" data-jra>' +
            '<div class="jra-grid">' +
            '<aside class="jra-pal" aria-label="Ações"><div class="jra-search"><i class="bi bi-search"></i><input type="search" placeholder="buscar ação..." data-jra-search autocomplete="off" aria-label="Buscar ação"></div>' +
            '<div data-jra-list></div></aside>' +
            '<section class="jra-main"><div class="jra-panel" data-jra-panel></div>' +
            '<div class="jra-res"><div class="jra-res-label">Resultado</div><div data-jra-results></div></div></section>' +
            '</div>' +
            '<details class="jra-full" data-jra-full><summary><i class="bi bi-chevron-right"></i> Histórico completo · <span data-jra-count>0</span></summary><div data-jra-fulllist></div></details>' +
            '</div>';
        renderPalette();
        renderPanel();
        renderResults();
    }

    function renderPalette() {
        var q = S.query.toLowerCase();
        var match = function (a) { return !q || a.title.toLowerCase().indexOf(q) !== -1; };
        var html = '';
        var favs = S.favs.filter(function (id) { return A[id] && match(A[id]); });
        if (favs.length) {
            html += '<div class="jra-glabel">Favoritas</div><div class="jra-favs">' + favs.map(function (id) {
                return '<button type="button" class="jra-chip' + (S.current === id ? ' is-on' : '') + '" data-jra-action="' + id + '">' + esc(A[id].title) + '</button>';
            }).join('') + '</div>';
        }
        GROUPS.forEach(function (g) {
            /* JRC_MENU_ONCE_V1 — uma ação favorita fica só no topo, sem repetir no grupo. */
            var items = ORDER.filter(function (id) { return A[id].group === g[0] && match(A[id]) && S.favs.indexOf(id) === -1; });
            if (!items.length) return;
            html += '<div class="jra-glabel">' + esc(g[1]) + '</div>' + items.map(function (id) {
                var a = A[id];
                var locked = !allowed(a);
                return '<button type="button" class="jra-item risk-' + a.risk + (S.current === id ? ' is-on' : '') + (locked ? ' is-locked' : '') + '" data-jra-action="' + id + '"><i class="bi ' + a.icon + '"></i><span>' + esc(a.title) + '</span>' + (locked ? '<i class="bi bi-lock-fill jra-lock" title="' + lockText(a) + '"></i>' : '') + '</button>';
            }).join('');
        });
        if (!html) html = '<div class="jra-empty">Nenhuma ação encontrada.</div>';
        root.querySelector('[data-jra-list]').innerHTML = html;
    }

    function riskBadge(a) {
        if (a.risk === 'danger') return '<span class="jra-risk is-danger">Destrutivo</span>';
        if (a.risk === 'warn') return '<span class="jra-risk is-warn">Atenção</span>';
        return '<span class="jra-risk is-safe">Seguro</span>';
    }

    function renderPanel(keepValues) {
        var a = A[S.current];
        var st = stateFor(a.id);
        var panel = root.querySelector('[data-jra-panel]');
        var isFav = S.favs.indexOf(a.id) !== -1;
        var locked = !allowed(a);
        var formHtml = locked
            ? '<div class="jra-note is-err"><i class="bi bi-lock-fill"></i>' + lockText(a) + '. Peça a um administrador para executar esta ação.</div>'
            : (a.form(st) || '');
        var label = (a.buttonLabel ? a.buttonLabel(st) : 'Executar');
        panel.innerHTML =
            '<div class="jra-head"><span class="jra-ico"><i class="bi ' + a.icon + '"></i></span><h3>' + esc(a.title) + '</h3>' +
            '<button type="button" class="jra-star' + (isFav ? ' is-on' : '') + '" data-jra-fav title="' + (isFav ? 'Remover das favoritas' : 'Adicionar às favoritas') + '"><i class="bi ' + (isFav ? 'bi-star-fill' : 'bi-star') + '"></i></button>' +
            riskBadge(a) + '</div>' +
            '<p class="jra-desc">' + esc(a.desc) + '</p>' +
            '<div class="jra-form" data-jra-form>' + formHtml + '</div>' +
            (locked ? '' : '<div class="jra-actions"><button type="button" class="jra-run' + (a.risk === 'danger' ? ' is-danger' : '') + '" data-jra-run><i class="bi ' + (a.icon) + '"></i><span>' + esc(label) + '</span></button>' +
                (a.secondary ? '<button type="button" class="jra-sec" data-jra-secondary>' + esc(a.secondary.label) + '</button>' : '') + '</div>');
        if (a.afterForm && !locked) a.afterForm(st);
        updateRunButton();
    }

    function updateRunButton() {
        var a = A[S.current];
        var btn = root.querySelector('[data-jra-run]');
        if (!btn) return;
        var st = stateFor(a.id);
        var busy = !!S.busy[a.id];
        var ready = true;
        try { ready = a.canRun ? !!a.canRun(st) : true; } catch (e) { ready = false; }
        if (a.load && !st.data) ready = false;
        btn.disabled = busy || !ready;
        btn.classList.toggle('is-busy', busy);
        var icon = btn.querySelector('i');
        if (icon) icon.className = busy ? 'jra-spin' : 'bi ' + a.icon;
    }

    function selectAction(id) {
        if (!A[id]) return;
        S.current = id;
        var a = A[id];
        var st = stateFor(id);
        if (a.reset && !S.busy[id]) a.reset(st);
        renderPalette();
        renderPanel();
        renderResults();
        if (a.load && allowed(a)) {
            st.error = null;
            a.load().then(function (d) { st.data = d; st.error = null; }, function (e) { st.error = e.message; })
                .then(function () { if (S.current === id) { renderPanel(); } });
        }
    }

    function scheduleRender() {
        renderPanel();
    }

    function dropRecorded(r) {
        return !!(r.recorded && S.historyEnabled && !r.detail);
    }
    function historyDup(h) {
        var head = String(h.summary || '').split('\n')[0];
        return S.runs.some(function (r) {
            if (!r.recorded || !r.detail || r.summary !== head) return false;
            if (Math.abs((r.ts || 0) - (h.ts || 0)) >= 180) return false;
            if (r.tool === h.tool) return true;
            var act = A[r.tool];
            return !!(act && act.hist.indexOf(h.tool) !== -1);
        });
    }
    function rowsFor(a) {
        var hist = S.history.filter(function (h) { return a.hist.indexOf(h.tool) !== -1 && !historyDup(h); });
        var mem = S.runs.filter(function (r) { return r.tool === a.id && !dropRecorded(r); });
        return { mem: mem, hist: hist };
    }

    function cardHtml(r) {
        var st = STATUS[r.status] || STATUS.ok;
        var a = A[r.toolId] || null;
        var icon = a ? a.icon : (r.icon || 'bi-activity');
        var meta = r.meta || '';
        return '<article class="jra-card"><span class="jra-card-ico"><i class="bi ' + icon + '"></i></span>' +
            '<div class="jra-card-body"><div class="jra-card-top"><strong>' + esc(r.title) + '</strong>' + (r.target ? '<span class="jra-sep">·</span><span class="jra-target">' + esc(r.target) + '</span>' : '') + '</div>' +
            '<div class="jra-card-meta">' + esc(meta) + '</div>' +
            '<div class="jra-card-sum">' + (r.status === 'running' ? '<span class="jra-spin"></span>' : '') + esc(r.summary || '') + '</div>' +
            (r.detail || '') + '</div><span class="jra-badge is-' + st[1] + '">' + st[0] + '</span></article>';
    }

    function runToCard(r) {
        return { toolId: r.tool, title: r.title, target: r.target, status: r.status, summary: r.status === 'running' ? (r.note || 'Executando...') : r.summary, detail: r.detail,
            meta: r.status === 'running' ? 'agora · ' + (S.user || 'você') : ago(r.ts) + ' · ' + (S.user || 'você') };
    }
    function histToCard(h) {
        var a = null;
        Object.keys(A).forEach(function (k) { if (A[k].hist.indexOf(h.tool) !== -1 && !a) a = A[k]; });
        var hops = hopsFromSummary(h.summary);
        var summary = hops.length ? String(h.summary).split('\n')[0] : h.summary;
        return { toolId: a ? a.id : '', title: h.title, target: h.target, status: h.status, summary: summary, detail: hops.length ? diagDetail('trace', { hops: hops }) : '', meta: ago(h.ts) + ' · ' + h.username };
    }

    function renderResults() {
        var a = A[S.current];
        var box = root.querySelector('[data-jra-results]');
        if (!box) return;
        var rows = rowsFor(a);
        var cards = rows.mem.slice().reverse().map(runToCard).concat(rows.hist.slice(0, 20).map(histToCard));
        var html = cards.length ? cards.map(cardHtml).join('') : '<div class="jra-empty">Nenhuma execução ainda. Os resultados desta ação aparecem aqui.</div>';
        box.innerHTML = html;
        root.querySelector('[data-jra-count]').textContent = String(S.history.filter(function (h) { return !historyDup(h); }).length + S.runs.filter(function (r) { return !dropRecorded(r); }).length);
        var full = root.querySelector('[data-jra-fulllist]');
        var all = S.runs.filter(function (r) { return !dropRecorded(r); }).reverse().map(function (r) { var c = runToCard(r); c.toolId = r.tool; return c; })
            .concat(S.history.filter(function (h) { return !historyDup(h); }).map(histToCard));
        full.innerHTML = all.length ? all.map(cardHtml).join('') : '<div class="jra-empty">Sem histórico para este equipamento.</div>';
    }

    function loadHistory() {
        return post(API, { action: 'history', device_id: deviceId() }).then(function (j) {
            if (!j.success) return;
            S.role = j.role || S.role;
            S.user = j.username || S.user;
            S.historyEnabled = j.history_enabled !== false;
            S.history = j.history || [];
            renderPalette();
            renderResults();
            if (S.current && (!allowed(A[S.current]) || S.current === 'capabilities')) renderPanel();
        }).catch(function () {});
    }

    function record(run) {
        if (run.serverLogged || run.skip || !S.historyEnabled) return Promise.resolve();
        var summary = run.summary || '';
        if (run.route) summary = summary + '\n' + run.route;
        return post(API, { action: 'history_add', device_id: deviceId(), entry: { tool: run.tool, title: run.title, target: run.target || '', status: run.status, summary: summary } })
            .then(function (j) { if (j.success && j.stored) run.recorded = true; })
            .catch(function () {});
    }

    function execute() {
        var a = A[S.current];
        var st = stateFor(a.id);
        if (S.busy[a.id] || !allowed(a)) return;
        var run = { id: ++seq, tool: a.id, title: a.title, target: '', status: 'running', summary: '', ts: Date.now() / 1000 };
        S.runs.push(run);
        S.busy[a.id] = true;
        updateRunButton();
        renderResults();
        var p;
        try { p = Promise.resolve(a.run(run, st)); } catch (e) { p = Promise.reject(e); }
        p.then(function (res) {
            run.status = res.status || 'ok';
            run.summary = res.summary || '';
            run.detail = res.detail;
            run.route = res.route || '';
            if (res.target) run.target = res.target;
            run.serverLogged = !!res.serverLogged;
            if (res.status === 'fail') toast(run.summary || 'A ação falhou.', 'danger');
            else if (!run.skip) toast(a.title + ': ' + (run.status === 'queued' ? 'enfileirado no equipamento.' : 'concluído.'), 'success');
        }, function (e) {
            run.status = 'fail';
            run.summary = e && e.message ? e.message : 'Falha ao executar a ação.';
            toast(run.summary, 'danger');
        }).then(function () {
            run.ts = Date.now() / 1000;
            S.busy[a.id] = false;
            if (run.skip) {
                S.runs = S.runs.filter(function (r) { return r !== run; });
            }
            if (!run.skip && a.id !== 'capabilities' && a.id !== 'backup') {
                if (a.load) {
                    a.load().then(function (d) { st.data = d; if (S.current === a.id) renderPanel(); });
                } else if (S.current === a.id) {
                    renderPanel();
                }
            }
            updateRunButton();
            renderResults();
            return record(run).then(loadHistory);
        });
    }

    function bind() {
        root.addEventListener('click', function (e) {
            var item = e.target.closest('[data-jra-action]');
            if (item && root.contains(item)) { selectAction(item.getAttribute('data-jra-action')); return; }
            if (e.target.closest('[data-jra-fav]')) {
                var id = S.current;
                var i = S.favs.indexOf(id);
                if (i === -1) S.favs.push(id); else S.favs.splice(i, 1);
                saveFavs();
                renderPalette();
                renderPanel();
                return;
            }
            if (e.target.closest('[data-jra-eye]')) {
                var input = e.target.closest('.jra-pw').querySelector('input');
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                e.target.closest('[data-jra-eye]').querySelector('i').className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye');
                return;
            }
            if (e.target.closest('[data-jra-run]')) { execute(); return; }
            if (e.target.closest('[data-jra-secondary]')) {
                var a = A[S.current];
                var st = stateFor(a.id);
                var btn = e.target.closest('[data-jra-secondary]');
                btn.disabled = true;
                a.secondary.run(st).catch(function (err) { toast(err.message, 'danger'); }).then(function () { btn.disabled = false; });
            }
        });
        root.addEventListener('input', function (e) {
            if (e.target.matches('[data-jra-search]')) {
                S.query = e.target.value.trim();
                renderPalette();
                return;
            }
            var a = A[S.current];
            if (a.onChange && e.target.matches('[data-f]')) a.onChange(e.target);
            updateRunButton();
        });
        root.addEventListener('change', function (e) {
            var a = A[S.current];
            if (a.onChange && e.target.matches('[data-f]')) a.onChange(e.target);
            updateRunButton();
        });
        root.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.matches('.jra-input:not(.jra-file)')) {
                e.preventDefault();
                var btn = root.querySelector('[data-jra-run]');
                if (btn && !btn.disabled) btn.click();
            }
        });
    }

    function mount() {
        var el = document.getElementById('acs-diagnostics-content');
        if (!el || el.getAttribute('data-jra-ready') === '1') return !!el;
        el.setAttribute('data-jra-ready', '1');
        root = el;
        shell();
        bind();
        loadHistory();
        selectAction(S.current);
        return true;
    }

    function start() {
        if (mount()) return;
        var mo = new MutationObserver(function () { if (mount()) mo.disconnect(); });
        mo.observe(document.documentElement, { childList: true, subtree: true });
        setTimeout(function () { mo.disconnect(); }, 15000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
