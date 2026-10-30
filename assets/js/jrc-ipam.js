/* JRC_IPAM_V1 — cadastro enxuto de redes e IPs no ACS. */
(function () {
    'use strict';
    if (window.__jrcIpam) return;
    window.__jrcIpam = true;

    var PAGE = 8;
    var data = { networks: [], addresses: [], history: [] };
    var tab = 'redes';
    var selected = '';
    var query = '';
    var statusFilter = 'todos';
    var addrQuery = '';
    var addrNet = '';
    var dnsQuery = '';
    var page = 1;
    var addrPage = 1;
    var phase = 'load';
    var note = '';
    var form = null;
    var focusForm = false;
    var root = null;
    var busy = false;

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function ipLong(text) {
        var parts = String(text || '').trim().split('.');
        if (parts.length !== 4) return null;
        var number = 0;
        for (var i = 0; i < 4; i++) {
            if (!/^\d{1,3}$/.test(parts[i])) return null;
            var piece = Number(parts[i]);
            if (piece > 255) return null;
            number = (number * 256 + piece) >>> 0;
        }
        return number;
    }

    function longIp(number) {
        number = number >>> 0;
        return ((number >>> 24) & 255) + '.' + ((number >>> 16) & 255) + '.' + ((number >>> 8) & 255) + '.' + (number & 255);
    }

    function parseCidr(cidr) {
        var match = String(cidr || '').trim().match(/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/);
        if (!match) return null;
        var ip = ipLong(match[1]);
        var prefix = Number(match[2]);
        if (ip == null || prefix < 16 || prefix > 30) return null;
        var hostBits = 32 - prefix;
        var mask = (0xFFFFFFFF ^ ((1 << hostBits) - 1)) >>> 0;
        var network = (ip & mask) >>> 0;
        var broadcast = (network | (~mask >>> 0)) >>> 0;
        var size = broadcast - network + 1;
        return {
            cidr: longIp(network) + '/' + prefix,
            network: network,
            broadcast: broadcast,
            prefix: prefix,
            mask: longIp(mask),
            first: (network + 1) >>> 0,
            last: (broadcast - 1) >>> 0,
            firstIp: longIp((network + 1) >>> 0),
            lastIp: longIp((broadcast - 1) >>> 0),
            broadcastIp: longIp(broadcast),
            usable: Math.max(0, size - 2),
            size: size
        };
    }

    function request(body) {
        return fetch('/api/jrc-ipam.php' + (body ? '' : '?action=list'), {
            method: body ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (response) {
            return response.text().then(function (text) {
                try { return JSON.parse(text); }
                catch (err) { return { success: false, message: 'Resposta inválida do cadastro.' }; }
            });
        });
    }

    function networkById(id) {
        for (var i = 0; i < data.networks.length; i++) {
            if (data.networks[i].id === id) return data.networks[i];
        }
        return null;
    }

    function addressesOf(id) {
        return data.addresses.filter(function (row) { return row.network_id === id; });
    }

    function tally(net) {
        var block = parseCidr(net.cidr);
        var usable = block ? block.usable : 0;
        var uso = 0;
        var hold = 0;
        addressesOf(net.id).forEach(function (row) {
            if (row.status === 'reservado') hold += 1;
            else uso += 1;
        });
        var livres = Math.max(0, usable - uso - hold);
        var base = usable;
        return {
            block: block,
            usable: usable,
            uso: uso,
            hold: hold,
            livres: livres,
            pct: pctOf(uso, base),
            share: function (count) { return pctOf(count, usable); }
        };
    }

    function pctOf(count, total) {
        if (!total || !count) return 0;
        var rounded = Math.round(count * 100 / total);
        return rounded === 0 ? 1 : rounded;
    }

    function heat(pct) {
        if (pct >= 85) return 'is-hot';
        if (pct >= 70) return 'is-warm';
        return '';
    }

    function accent(pct) {
        if (pct >= 85) return '#e10600';
        if (pct >= 70) return '#f0b429';
        return '#3dd68c';
    }

    function filteredNetworks() {
        var needle = query.trim().toLowerCase();
        return data.networks.filter(function (net) {
            if (statusFilter === 'ativa' && net.active === false) return false;
            if (statusFilter === 'inativa' && net.active !== false) return false;
            if (!needle) return true;
            return (net.cidr + ' ' + net.description + ' ' + (net.location || '') + ' ' + (net.kind || '')).toLowerCase().indexOf(needle) !== -1;
        });
    }

    function filteredAddresses() {
        var needle = addrQuery.trim().toLowerCase();
        return data.addresses.filter(function (row) {
            if (addrNet && row.network_id !== addrNet) return false;
            if (!needle) return true;
            var net = networkById(row.network_id);
            var hay = row.ip + ' ' + (row.hostname || '') + ' ' + (row.description || '') + ' ' + (net ? net.cidr : '');
            return hay.toLowerCase().indexOf(needle) !== -1;
        }).sort(function (a, b) {
            return (ipLong(a.ip) || 0) - (ipLong(b.ip) || 0);
        });
    }

    function hostRows() {
        var needle = dnsQuery.trim().toLowerCase();
        return data.addresses.filter(function (row) {
            if (!String(row.hostname || '').trim()) return false;
            if (!needle) return true;
            return (row.hostname + ' ' + row.ip + ' ' + (row.description || '')).toLowerCase().indexOf(needle) !== -1;
        }).sort(function (a, b) {
            return String(a.hostname).localeCompare(String(b.hostname), 'pt');
        });
    }

    function grand() {
        var uso = 0;
        var livres = 0;
        var hold = 0;
        data.networks.forEach(function (net) {
            var row = tally(net);
            uso += row.uso;
            livres += row.livres;
            hold += row.hold;
        });
        var total = uso + livres + hold;
        return {
            redes: data.networks.length,
            uso: uso,
            livres: livres,
            hold: hold,
            usoPct: pctOf(uso, total),
            livresPct: pctOf(livres, total),
            holdPct: pctOf(hold, total)
        };
    }

    function fmt(number) {
        return Number(number || 0).toLocaleString('pt-BR');
    }

    function when(seconds) {
        var date = new Date((Number(seconds) || 0) * 1000);
        if (!Number(seconds)) return '—';
        try {
            return date.toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });
        } catch (err) {
            return date.toLocaleString('pt-BR');
        }
    }

    function pager(total, current) {
        var pages = Math.max(1, Math.ceil(total / PAGE));
        if (current > pages) current = pages;
        if (total <= PAGE) {
            return { html: '<p class="jrc-ip-pager"><span>Mostrando ' + (total ? 1 : 0) + ' a ' + total + ' de ' + total + '</span></p>', page: current };
        }
        var from = (current - 1) * PAGE + 1;
        var to = Math.min(total, current * PAGE);
        var html = '<p class="jrc-ip-pager"><button type="button" class="jrc-ip-ghost" data-act="page" data-page="prev"' + (current === 1 ? ' disabled' : '') + '>Anterior</button><span>' + from + '–' + to + ' de ' + total + '</span><button type="button" class="jrc-ip-ghost" data-act="page" data-page="next"' + (current === pages ? ' disabled' : '') + '>Próxima</button></p>';
        return { html: html, page: current };
    }

    function ring(pct, color) {
        return '<span class="jrc-ip-ring" style="--p:' + pct + ';--c:' + color + '"><b>' + pct + '%</b></span>';
    }

    function statCards() {
        var all = grand();
        return '<div class="jrc-ip-stats">' +
            '<article class="jrc-ip-stat"><span class="jrc-ip-ico"><i class="bi bi-diagram-3"></i></span><div><strong>' + fmt(all.redes) + '</strong><span>Redes cadastradas</span><small>Total de sub-redes</small></div></article>' +
            '<article class="jrc-ip-stat is-green"><span class="jrc-ip-ico"><i class="bi bi-hdd-network"></i></span><div><strong>' + fmt(all.uso) + '</strong><span>IPs em uso</span><small>' + all.usoPct + '% do total</small></div>' + ring(all.usoPct, '#3dd68c') + '</article>' +
            '<article class="jrc-ip-stat is-blue"><span class="jrc-ip-ico"><i class="bi bi-cloud"></i></span><div><strong>' + fmt(all.livres) + '</strong><span>IPs livres</span><small>' + all.livresPct + '% do total</small></div>' + ring(all.livresPct, '#7eb6ff') + '</article>' +
            '<article class="jrc-ip-stat is-amber"><span class="jrc-ip-ico"><i class="bi bi-bookmark"></i></span><div><strong>' + fmt(all.hold) + '</strong><span>IPs reservados</span><small>' + all.holdPct + '% do total</small></div>' + ring(all.holdPct, '#f0b429') + '</article>' +
            '</div>';
    }

    function tabsHtml() {
        var items = [
            ['redes', 'bi-diagram-3', 'Redes'],
            ['enderecos', 'bi-list-ul', 'Endereços IP'],
            ['dns', 'bi-globe2', 'DNS / Hostnames'],
            ['historico', 'bi-clock-history', 'Histórico'],
            ['config', 'bi-gear', 'Configurações']
        ];
        return '<div class="jrc-ip-tabs">' + items.map(function (item) {
            return '<button type="button" data-act="tab" data-tab="' + item[0] + '"' + (tab === item[0] ? ' class="is-on"' : '') + '><i class="bi ' + item[1] + '"></i> ' + item[2] + '</button>';
        }).join('') + '</div>';
    }

    function pill(active) {
        if (active === false) return '<span class="jrc-ip-pill is-off"><i class="bi bi-circle-fill"></i> Inativa</span>';
        return '<span class="jrc-ip-pill"><i class="bi bi-circle-fill"></i> Ativa</span>';
    }

    function statusPill(status) {
        if (status === 'reservado') return '<span class="jrc-ip-pill is-hold">Reservado</span>';
        return '<span class="jrc-ip-pill is-use">Em uso</span>';
    }

    function bar(pct) {
        return '<span class="jrc-ip-bar ' + heat(pct) + '"><span style="width:' + pct + '%"></span></span>' + pct + '%';
    }

    function networkRows() {
        var rows = filteredNetworks();
        var view = pager(rows.length, page);
        page = view.page;
        var slice = rows.slice((page - 1) * PAGE, page * PAGE);
        if (phase === 'load') return '<p class="jrc-ip-empty">Carregando o cadastro…</p>';
        if (phase === 'error') return '<p class="jrc-ip-empty">' + esc(note || 'Não foi possível carregar o cadastro.') + '</p>';
        if (!data.networks.length) return '<p class="jrc-ip-empty">Nenhuma rede cadastrada. Use Nova rede para documentar a primeira faixa.</p>';
        if (!rows.length) return '<p class="jrc-ip-empty">Nenhuma rede neste filtro.</p>';
        var body = slice.map(function (net) {
            var row = tally(net);
            var on = net.id === selected ? ' class="is-on"' : '';
            return '<tr data-pick="' + esc(net.id) + '"' + on + '>' +
                '<td class="jrc-ip-net" style="--accent:' + accent(row.pct) + '">' + esc(net.cidr) + '</td>' +
                '<td>' + esc(net.description) + '</td>' +
                '<td class="jrc-ip-hide">' + esc(net.location || '—') + '</td>' +
                '<td>' + bar(row.pct) + '</td>' +
                '<td>' + fmt(row.livres) + '</td>' +
                '<td>' + fmt(row.uso) + '</td>' +
                '<td class="jrc-ip-hide">' + fmt(row.hold) + '</td>' +
                '<td>' + pill(net.active) + '</td>' +
                '<td class="jrc-ip-actions-cell"><button type="button" class="jrc-ip-icon" data-act="edit-net" data-id="' + esc(net.id) + '" title="Editar"><i class="bi bi-pencil"></i></button><button type="button" class="jrc-ip-icon is-danger" data-act="del-net" data-id="' + esc(net.id) + '" title="Excluir"><i class="bi bi-trash"></i></button></td>' +
                '</tr>';
        }).join('');
        return '<div class="jrc-ip-scroll"><table class="jrc-ip-table"><thead><tr><th>Rede</th><th>Descrição</th><th class="jrc-ip-hide">Localização</th><th>Uso</th><th>Livres</th><th>Em uso</th><th class="jrc-ip-hide">Reservados</th><th>Status</th><th></th></tr></thead><tbody>' + body + '</tbody></table></div>' + view.html.replace('de ' + rows.length, 'de ' + rows.length + ' redes');
    }

    function detailHtml() {
        var net = networkById(selected) || filteredNetworks()[0] || null;
        if (net && !selected) selected = net.id;
        if (!net) {
            return '<aside class="jrc-ip-card jrc-ip-detail"><h2>Detalhes da rede</h2><p class="jrc-ip-empty">Selecione uma rede para ver o gateway, a faixa e os IPs livres.</p></aside>';
        }
        var row = tally(net);
        var block = row.block;
        var facts = block
            ? '<div class="jrc-ip-facts">' +
                fact('Tipo', net.kind || '—') +
                fact('Localização', net.location || '—') +
                fact('Gateway', net.gateway || block.firstIp) +
                fact('Máscara', block.mask + ' (/' + block.prefix + ')') +
                fact('Range', block.firstIp + ' – ' + block.lastIp) +
                fact('Broadcast', block.broadcastIp) +
                fact('Total de IPs', fmt(row.usable)) +
                fact('Em uso', fmt(row.uso) + ' (' + row.share(row.uso) + '%)') +
                fact('Livres', fmt(row.livres) + ' (' + row.share(row.livres) + '%)') +
                fact('Reservados', fmt(row.hold) + ' (' + row.share(row.hold) + '%)') +
                '</div>'
            : '<p class="jrc-ip-empty">Essa faixa está ilegível. Edite a rede e grave o CIDR de novo.</p>';
        return '<aside class="jrc-ip-card jrc-ip-detail">' +
            '<h3 class="jrc-ip-detail-label">Detalhes da rede</h3><div class="jrc-ip-detail-top"><div><h2>' + esc(net.cidr) + ' ' + pill(net.active) + '</h2><p>' + esc(net.description) + '</p></div><button type="button" class="jrc-ip-ghost" data-act="edit-net" data-id="' + esc(net.id) + '"><i class="bi bi-pencil"></i> Editar</button></div>' +
            facts +
            '<div class="jrc-ip-meter"><span style="width:' + row.pct + '%;background:' + accent(row.pct) + '"></span></div>' +
            '<div class="jrc-ip-split"><span>Uso do total de IPs</span><strong>' + row.pct + '%</strong></div>' +
            '<h3 class="jrc-ip-detail-label">Ações rápidas</h3><div class="jrc-ip-actions">' +
            '<button type="button" class="jrc-ip-ghost" data-act="see" data-id="' + esc(net.id) + '"><i class="bi bi-list-ul"></i> Ver endereços desta rede</button>' +
            '<button type="button" class="jrc-ip-ghost" data-act="add-ip" data-id="' + esc(net.id) + '"><i class="bi bi-plus-lg"></i> Adicionar novo IP</button>' +
            '<button type="button" class="jrc-ip-ghost" data-act="import" data-id="' + esc(net.id) + '"><i class="bi bi-upload"></i> Importar IPs (CSV)</button>' +
            '<button type="button" class="jrc-ip-ghost" data-act="export" data-id="' + esc(net.id) + '"><i class="bi bi-download"></i> Exportar rede</button>' +
            '<button type="button" class="jrc-ip-danger" data-act="del-net" data-id="' + esc(net.id) + '"><i class="bi bi-trash"></i> Excluir rede</button>' +
            '</div></aside>';
    }

    function fact(label, value) {
        return '<div><small>' + esc(label) + '</small><strong>' + esc(value) + '</strong></div>';
    }

    function redesTab() {
        return '<div class="jrc-ip-layout"><section class="jrc-ip-card"><div class="jrc-ip-card-head"><h2>Redes cadastradas (' + data.networks.length + ')</h2><div class="jrc-ip-tools"><button type="button" class="jrc-ip-btn" data-act="new-net">+ Nova rede</button></div></div>' +
            '<div class="jrc-ip-tools"><input id="jrc-ip-q" type="search" placeholder="Buscar rede, descrição ou localização..." value="' + esc(query) + '"><select id="jrc-ip-status"><option value="todos"' + (statusFilter === 'todos' ? ' selected' : '') + '>Todos os status</option><option value="ativa"' + (statusFilter === 'ativa' ? ' selected' : '') + '>Ativa</option><option value="inativa"' + (statusFilter === 'inativa' ? ' selected' : '') + '>Inativa</option></select></div>' +
            networkRows() + '</section>' + detailHtml() + '</div>';
    }

    function netOptions(current) {
        var html = '<option value="">Todas as redes</option>';
        data.networks.forEach(function (net) {
            html += '<option value="' + esc(net.id) + '"' + (net.id === current ? ' selected' : '') + '>' + esc(net.cidr + ' · ' + net.description) + '</option>';
        });
        return html;
    }

    function enderecosTab() {
        var rows = filteredAddresses();
        var view = pager(rows.length, addrPage);
        addrPage = view.page;
        var slice = rows.slice((addrPage - 1) * PAGE, addrPage * PAGE);
        var body = !rows.length
            ? '<p class="jrc-ip-empty">Nenhum IP em uso ou reservado' + (addrNet || addrQuery ? ' neste filtro' : '') + '. O que está livre não ocupa linha.</p>'
            : '<div class="jrc-ip-scroll"><table class="jrc-ip-table"><thead><tr><th>IP</th><th>Hostname</th><th>Descrição</th><th class="jrc-ip-hide">Rede</th><th>Status</th><th></th></tr></thead><tbody>' + slice.map(function (row) {
                var net = networkById(row.network_id);
                return '<tr><td class="jrc-ip-net" style="--accent:' + (row.status === 'reservado' ? '#f0b429' : '#3dd68c') + '">' + esc(row.ip) + '</td><td>' + esc(row.hostname || '—') + '</td><td>' + esc(row.description || '—') + '</td><td class="jrc-ip-hide">' + esc(net ? net.cidr : '—') + '</td><td>' + statusPill(row.status) + '</td><td class="jrc-ip-actions-cell"><button type="button" class="jrc-ip-icon" data-act="edit-ip" data-id="' + esc(row.id) + '" title="Editar"><i class="bi bi-pencil"></i></button><button type="button" class="jrc-ip-icon is-danger" data-act="del-ip" data-id="' + esc(row.id) + '" title="Liberar"><i class="bi bi-trash"></i></button></td></tr>';
            }).join('') + '</tbody></table></div>' + view.html;
        return '<section class="jrc-ip-card"><div class="jrc-ip-card-head"><h2>Endereços lançados (' + data.addresses.length + ')</h2><button type="button" class="jrc-ip-btn" data-act="add-ip">+ Novo IP</button></div><div class="jrc-ip-tools"><input id="jrc-ip-addr-q" type="search" placeholder="Buscar IP, hostname ou descrição..." value="' + esc(addrQuery) + '"><select id="jrc-ip-netf">' + netOptions(addrNet) + '</select></div>' + body + '</section>';
    }

    function dnsTab() {
        var rows = hostRows();
        var body = !rows.length
            ? '<p class="jrc-ip-empty">Nenhum hostname lançado. O nome fica no próprio IP, sem servidor de DNS.</p>'
            : '<div class="jrc-ip-scroll"><table class="jrc-ip-table"><thead><tr><th>Hostname</th><th>IP</th><th class="jrc-ip-hide">Rede</th><th>Descrição</th></tr></thead><tbody>' + rows.map(function (row) {
                var net = networkById(row.network_id);
                return '<tr><td>' + esc(row.hostname) + '</td><td class="jrc-ip-net" style="--accent:#7eb6ff">' + esc(row.ip) + '</td><td class="jrc-ip-hide">' + esc(net ? net.cidr : '—') + '</td><td>' + esc(row.description || '—') + '</td></tr>';
            }).join('') + '</tbody></table></div>';
        return '<section class="jrc-ip-card"><div class="jrc-ip-card-head"><h2>DNS / Hostnames</h2></div><div class="jrc-ip-tools"><input id="jrc-ip-dns-q" type="search" placeholder="Buscar hostname ou IP..." value="' + esc(dnsQuery) + '"></div>' + body + '</section>';
    }

    function historyTab() {
        if (!data.history.length) return '<section class="jrc-ip-card"><h2>Histórico</h2><p class="jrc-ip-empty">Ainda não há movimento. Cadastros, importações e exclusões aparecem aqui.</p></section>';
        var rows = data.history.slice(0, 50).map(function (item) {
            return '<tr><td>' + esc(when(item.at)) + '</td><td>' + esc(item.user || 'operador') + '</td><td>' + esc(item.text || '') + '</td></tr>';
        }).join('');
        return '<section class="jrc-ip-card"><div class="jrc-ip-card-head"><h2>Histórico</h2></div><div class="jrc-ip-scroll"><table class="jrc-ip-table"><thead><tr><th>Quando</th><th>Quem</th><th>O que</th></tr></thead><tbody>' + rows + '</tbody></table></div></section>';
    }

    function configTab() {
        return '<section class="jrc-ip-card"><h2>Configurações</h2><p>O cadastro fica no arquivo do ACS, no mesmo servidor do painel. Não usa o phpIPAM.</p><div class="jrc-ip-facts">' +
            fact('Redes', 'até 200') + fact('Endereços', 'até 20 mil, só uso e reserva') +
            fact('Importação', 'CSV de até 2000 linhas') + fact('Faixas', 'IPv4 de /16 a /30') +
            '</div><div class="jrc-ip-actions"><button type="button" class="jrc-ip-ghost" data-act="export-all"><i class="bi bi-download"></i> Exportar todas as redes</button></div></section>';
    }

    function panel() {
        if (tab === 'enderecos') return enderecosTab();
        if (tab === 'dns') return dnsTab();
        if (tab === 'historico') return historyTab();
        if (tab === 'config') return configTab();
        return redesTab();
    }

    function shell() {
        return '<section class="jrc-ip"><div class="jrc-ip-head"><div class="jrc-ip-title"><span class="jrc-ip-mark"><i class="bi bi-diagram-3"></i></span><div><h1>Endereçamento IP</h1><p>Documentação de redes e endereços IP da JR Conect</p></div></div><button type="button" class="jrc-ip-btn" data-act="new-net">+ Nova rede</button></div>' +
            (note && phase !== 'error' ? '<p class="jrc-ip-note" id="jrc-ip-note">' + esc(note) + '</p>' : '') +
            statCards() + tabsHtml() + panel() + '</section>';
    }

    function modalHtml() {
        if (!form) return '';
        var inner = '';
        if (form.mode === 'confirm') {
            inner = '<h2>' + esc(form.title) + '</h2><p class="hint">' + esc(form.text) + '</p><p class="jrc-ip-note">' + esc(form.note || '') + '</p><div class="jrc-ip-form-row"><button type="button" class="jrc-ip-ghost" data-act="close">Cancelar</button><button type="button" class="jrc-ip-btn" data-act="' + esc(form.act) + '" data-id="' + esc(form.id) + '">Confirmar</button></div>';
        } else if (form.mode === 'net') {
            inner = '<h2>' + (form.id ? 'Editar rede' : 'Nova rede') + '</h2><p class="hint">IPv4 de /16 a /30. O gateway em branco vira o primeiro IP útil.</p>' +
                field('CIDR', 'cidr', form.cidr, '10.0.1.0/24') +
                field('Descrição', 'description', form.description, 'OLT-01') +
                field('Localização', 'location', form.location, 'POP Central') +
                field('Tipo', 'kind', form.kind, 'Gerência, OLT, rádios') +
                field('Gateway', 'gateway', form.gateway, 'opcional') +
                '<label class="jrc-ip-check"><input type="checkbox" name="active"' + (form.active ? ' checked' : '') + '> Rede ativa</label>' +
                '<p class="jrc-ip-note" id="jrc-ip-form-note">' + esc(form.note || '') + '</p>' +
                '<div class="jrc-ip-form-row"><button type="button" class="jrc-ip-ghost" data-act="close">Cancelar</button><button type="button" class="jrc-ip-btn" data-act="save-net">Salvar rede</button></div>';
        } else if (form.mode === 'ip') {
            var locked = form.lock ? '<input name="network_id" type="hidden" value="' + esc(form.network_id) + '"><p class="hint">' + esc((networkById(form.network_id) || {}).cidr || '') + '</p>' : '<label>Rede<select name="network_id">' + data.networks.map(function (net) {
                return '<option value="' + esc(net.id) + '"' + (net.id === form.network_id ? ' selected' : '') + '>' + esc(net.cidr + ' · ' + net.description) + '</option>';
            }).join('') + '</select></label>';
            inner = '<h2>' + (form.id ? 'Editar IP' : 'Adicionar IP') + '</h2>' + locked +
                '<label>IP<input name="ip" value="' + esc(form.ip) + '"' + (form.id ? ' readonly' : '') + ' placeholder="10.0.1.10"></label>' +
                field('Hostname', 'hostname', form.hostname, 'olt-01') +
                field('Descrição', 'description', form.description, 'onde está esse equipamento') +
                '<label>Status<select name="status"><option value="uso"' + (form.status !== 'reservado' ? ' selected' : '') + '>Em uso</option><option value="reservado"' + (form.status === 'reservado' ? ' selected' : '') + '>Reservado</option></select></label>' +
                '<p class="jrc-ip-note" id="jrc-ip-form-note">' + esc(form.note || '') + '</p>' +
                '<div class="jrc-ip-form-row"><button type="button" class="jrc-ip-ghost" data-act="close">Cancelar</button><button type="button" class="jrc-ip-btn" data-act="save-ip">Salvar IP</button></div>';
        } else if (form.mode === 'import') {
            inner = '<h2>Importar IPs</h2><p class="hint">Colunas: ip, hostname, descricao, status. Status: uso, reservado ou livre. Até 2000 linhas.</p>' +
                '<label>Arquivo CSV<input id="jrc-ip-csv" type="file" accept=".csv,text/csv"></label>' +
                '<label>Ou cole aqui<textarea name="csv" placeholder="ip,hostname,descricao,status">' + esc(form.csv || '') + '</textarea></label>' +
                '<p class="jrc-ip-note" id="jrc-ip-form-note">' + esc(form.note || '') + '</p>' +
                '<div class="jrc-ip-form-row"><button type="button" class="jrc-ip-ghost" data-act="close">Cancelar</button><button type="button" class="jrc-ip-btn" data-act="save-import">Importar</button></div>';
        }
        return '<div class="jrc-ip-modal"><form class="jrc-ip-form" data-act="noop">' + inner + '</form></div>';
    }

    function field(label, name, value, placeholder) {
        return '<label>' + label + '<input name="' + name + '" value="' + esc(value || '') + '" placeholder="' + esc(placeholder || '') + '"></label>';
    }

    function render() {
        if (!root) return;
        var active = document.activeElement;
        var focusId = active && active.id;
        var caret = active && typeof active.selectionStart === 'number' ? active.selectionStart : null;
        root.innerHTML = shell() + modalHtml();
        if (focusForm) {
            focusForm = false;
            var input = root.querySelector('.jrc-ip-form input:not([type="hidden"]):not([type="checkbox"]):not([type="file"])');
            if (input) input.focus();
            return;
        }
        if (focusId && /^(jrc-ip-q|jrc-ip-addr-q|jrc-ip-dns-q)$/.test(focusId)) {
            var again = document.getElementById(focusId);
            if (again) {
                again.focus();
                if (caret != null && again.setSelectionRange) again.setSelectionRange(caret, caret);
            }
        }
    }

    function readForm() {
        var out = {};
        if (!root) return out;
        var fields = root.querySelectorAll('.jrc-ip-form [name]');
        Array.prototype.forEach.call(fields, function (el) {
            out[el.name] = el.type === 'checkbox' ? el.checked : el.value;
        });
        return out;
    }

    function fail(message) {
        if (form) {
            form.note = message;
            var slot = document.getElementById('jrc-ip-form-note');
            if (slot) {
                slot.textContent = message;
                return;
            }
        }
        note = message;
        render();
    }

    function refresh(keep) {
        return request(null).then(function (res) {
            if (!res || res.success === false) throw new Error((res && res.message) || 'Não foi possível carregar o cadastro.');
            data.networks = res.networks || [];
            data.addresses = res.addresses || [];
            data.history = res.history || [];
            phase = 'ready';
            if (keep) selected = keep;
            if (selected && !networkById(selected)) selected = '';
            render();
        });
    }

    function post(body) {
        if (busy) return Promise.resolve();
        busy = true;
        return request(body).then(function (res) {
            busy = false;
            if (!res || res.success === false) throw new Error((res && res.message) || 'Não foi possível salvar.');
            return res;
        }).catch(function (err) {
            busy = false;
            throw err;
        });
    }

    function saveNet() {
        var values = readForm();
        var block = parseCidr(values.cidr || '');
        if (!block) return fail('Informe uma rede IPv4 de /16 a /30. Exemplo: 10.0.1.0/24.');
        if (!String(values.description || '').trim()) return fail('Dê um nome para a rede.');
        var hit = null;
        data.networks.forEach(function (net) {
            if (hit || net.id === form.id) return;
            var other = parseCidr(net.cidr);
            if (other && block.network <= other.broadcast && other.network <= block.broadcast) hit = other;
        });
        if (hit) return fail('Essa rede encosta em ' + hit.cidr + '.');
        var gateway = String(values.gateway || '').trim();
        if (gateway) {
            var gw = ipLong(gateway);
            if (gw == null || gw < block.first || gw > block.last) return fail('O gateway precisa estar dentro da rede.');
        }
        post({
            action: 'save-network',
            id: form.id || '',
            cidr: values.cidr,
            description: values.description,
            location: values.location || '',
            kind: values.kind || '',
            gateway: gateway,
            active: !!values.active
        }).then(function (res) {
            form = null;
            note = '';
            tab = 'redes';
            return refresh(res.network && res.network.id);
        }).catch(function (err) { fail(err.message); });
    }

    function saveIp() {
        var values = readForm();
        var networkId = values.network_id || (form && form.network_id) || '';
        if (!networkId) return fail('Cadastre uma rede antes de lançar o IP.');
        post({
            action: 'save-address',
            network_id: networkId,
            ip: values.ip || '',
            hostname: values.hostname || '',
            description: values.description || '',
            status: values.status || 'uso'
        }).then(function () {
            form = null;
            note = '';
            return refresh(selected);
        }).catch(function (err) { fail(err.message); });
    }

    function splitCsv(line) {
        var out = [];
        var cell = '';
        var quoted = false;
        for (var i = 0; i < line.length; i++) {
            var ch = line.charAt(i);
            if (quoted) {
                if (ch === '"' && line.charAt(i + 1) === '"') { cell += '"'; i += 1; }
                else if (ch === '"') quoted = false;
                else cell += ch;
            } else if (ch === '"') quoted = true;
            else if (ch === ',' || ch === ';') { out.push(cell); cell = ''; }
            else cell += ch;
        }
        out.push(cell);
        return out;
    }

    function parseCsv(text) {
        var lines = String(text || '').replace(/^\uFEFF/, '').split(/\r?\n/).filter(function (line) { return line.trim(); });
        if (!lines.length) return [];
        var head = splitCsv(lines[0]).map(function (cell) { return cell.trim().toLowerCase(); });
        var start = 0;
        var idx = { ip: 0, hostname: -1, description: -1, status: -1 };
        if (head.indexOf('ip') !== -1) {
            start = 1;
            idx.ip = head.indexOf('ip');
            idx.hostname = head.indexOf('hostname');
            if (idx.hostname < 0) idx.hostname = head.indexOf('host');
            idx.description = head.indexOf('descricao');
            if (idx.description < 0) idx.description = head.indexOf('descrição');
            if (idx.description < 0) idx.description = head.indexOf('description');
            idx.status = head.indexOf('status');
        }
        var rows = [];
        for (var i = start; i < lines.length; i++) {
            var cols = splitCsv(lines[i]);
            rows.push({
                ip: (cols[idx.ip] || '').trim(),
                hostname: idx.hostname >= 0 ? (cols[idx.hostname] || '').trim() : '',
                description: idx.description >= 0 ? (cols[idx.description] || '').trim() : '',
                status: idx.status >= 0 ? (cols[idx.status] || 'uso').trim() : 'uso'
            });
        }
        return rows;
    }

    function saveImport() {
        var values = readForm();
        var rows = parseCsv(values.csv || '');
        if (!rows.length) return fail('O CSV não tem linhas de IP.');
        post({ action: 'import', network_id: form.network_id, rows: rows }).then(function (res) {
            var skipped = (res.skipped || []).join('; ');
            form = null;
            note = skipped ? ('Importou ' + res.saved + '. Ignorou: ' + skipped) : '';
            tab = 'enderecos';
            addrNet = '';
            return refresh(selected);
        }).catch(function (err) { fail(err.message); });
    }

    function csvCell(value) {
        var text = String(value == null ? '' : value);
        if (/[",\n;]/.test(text)) return '"' + text.replace(/"/g, '""') + '"';
        return text;
    }

    function download(name, text) {
        var blob = new Blob([text], { type: 'text/csv;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
    }

    function exportNet(id) {
        var net = networkById(id);
        if (!net) return;
        var block = parseCidr(net.cidr);
        var rows = addressesOf(id);
        var used = {};
        rows.forEach(function (row) { used[row.ip] = row; });
        var lines = ['ip,hostname,descricao,status'];
        var large = !block || block.usable > 1024;
        if (!large) {
            for (var number = block.first; number <= block.last; number++) {
                var ip = longIp(number >>> 0);
                var row = used[ip];
                lines.push([ip, row ? row.hostname : '', row ? row.description : '', row ? row.status : 'livre'].map(csvCell).join(','));
            }
            note = '';
        } else {
            rows.forEach(function (row) {
                lines.push([row.ip, row.hostname || '', row.description || '', row.status || 'uso'].map(csvCell).join(','));
            });
            note = 'Rede grande: o CSV trouxe só os IPs em uso e reservados.';
        }
        download(net.cidr.replace('/', '_') + '.csv', lines.join('\n') + '\n');
        render();
    }

    function exportAll() {
        var lines = ['rede,ip,hostname,descricao,status'];
        data.networks.forEach(function (net) {
            addressesOf(net.id).forEach(function (row) {
                lines.push([net.cidr, row.ip, row.hostname || '', row.description || '', row.status || 'uso'].map(csvCell).join(','));
            });
        });
        download('enderecamento-ip.csv', lines.join('\n') + '\n');
    }

    function openNet(id) {
        var net = id ? networkById(id) : null;
        form = {
            mode: 'net',
            id: net ? net.id : '',
            cidr: net ? net.cidr : '',
            description: net ? net.description : '',
            location: net ? (net.location || '') : '',
            kind: net ? (net.kind || '') : '',
            gateway: net ? (net.gateway || '') : '',
            active: net ? net.active !== false : true,
            note: ''
        };
        focusForm = true;
        render();
    }

    function openIp(id, addressId) {
        if (!data.networks.length) {
            note = 'Cadastre uma rede antes de lançar o IP.';
            render();
            return;
        }
        var address = null;
        if (addressId) {
            for (var i = 0; i < data.addresses.length; i++) {
                if (data.addresses[i].id === addressId) address = data.addresses[i];
            }
        }
        var networkId = address ? address.network_id : (id || addrNet || selected || data.networks[0].id);
        form = {
            mode: 'ip',
            id: address ? address.id : '',
            network_id: networkId,
            lock: !!address || !!id,
            ip: address ? address.ip : '',
            hostname: address ? (address.hostname || '') : '',
            description: address ? (address.description || '') : '',
            status: address ? address.status : 'uso',
            note: ''
        };
        focusForm = true;
        render();
    }

    function removeNet(id) {
        post({ action: 'delete-network', id: id }).then(function () {
            form = null;
            if (selected === id) selected = '';
            note = '';
            return refresh();
        }).catch(function (err) { fail(err.message); });
    }

    function removeIp(id) {
        post({ action: 'delete-address', id: id }).then(function () {
            form = null;
            note = '';
            return refresh(selected);
        }).catch(function (err) { fail(err.message); });
    }

    function onClick(event) {
        if (event.target.classList && event.target.classList.contains('jrc-ip-modal')) {
            form = null;
            render();
            return;
        }
        var button = event.target.closest('[data-act]');
        if (!button) {
            var row = event.target.closest('[data-pick]');
            if (row && root.contains(row)) {
                var networkId = row.getAttribute('data-pick');
                if (networkById(networkId)) {
                    selected = networkId;
                    render();
                }
            }
            return;
        }
        if (button.disabled) return;
        var act = button.getAttribute('data-act');
        var id = button.getAttribute('data-id') || '';
        if (act === 'noop') return;
        if (act === 'tab') { tab = button.getAttribute('data-tab') || 'redes'; render(); return; }
        if (act === 'page') {
            var step = button.getAttribute('data-page');
            if (tab === 'enderecos') addrPage += step === 'prev' ? -1 : 1;
            else page += step === 'prev' ? -1 : 1;
            render();
            return;
        }
        if (act === 'new-net') return openNet('');
        if (act === 'edit-net') { selected = id; return openNet(id); }
        if (act === 'del-net') {
            var net = networkById(id);
            form = { mode: 'confirm', title: 'Excluir rede', text: 'Excluir ' + (net ? net.cidr : 'esta rede') + ' e os IPs lançados nela?', act: 'do-del-net', id: id, note: '' };
            render();
            return;
        }
        if (act === 'do-del-net') return removeNet(id || (form && form.id));
        if (act === 'add-ip') return openIp(id, '');
        if (act === 'edit-ip') return openIp('', id);
        if (act === 'del-ip') {
            form = { mode: 'confirm', title: 'Liberar IP', text: 'Tirar esse IP da lista? Ele volta a contar como livre.', act: 'do-del-ip', id: id, note: '' };
            render();
            return;
        }
        if (act === 'do-del-ip') return removeIp(id || (form && form.id));
        if (act === 'see') { selected = id; addrNet = id; tab = 'enderecos'; addrPage = 1; render(); return; }
        if (act === 'import') {
            form = { mode: 'import', network_id: id || selected, csv: '', note: '' };
            focusForm = true;
            render();
            return;
        }
        if (act === 'export') return exportNet(id || selected);
        if (act === 'export-all') return exportAll();
        if (act === 'close') { form = null; render(); return; }
        if (act === 'save-net') return saveNet();
        if (act === 'save-ip') return saveIp();
        if (act === 'save-import') return saveImport();
    }

    function onInput(event) {
        if (event.target.id === 'jrc-ip-q') { query = event.target.value; page = 1; render(); }
        else if (event.target.id === 'jrc-ip-addr-q') { addrQuery = event.target.value; addrPage = 1; render(); }
        else if (event.target.id === 'jrc-ip-dns-q') { dnsQuery = event.target.value; render(); }
    }

    function onChange(event) {
        if (event.target.id === 'jrc-ip-status') { statusFilter = event.target.value; page = 1; render(); return; }
        if (event.target.id === 'jrc-ip-netf') { addrNet = event.target.value; addrPage = 1; render(); return; }
        if (event.target.id === 'jrc-ip-csv' && event.target.files && event.target.files[0]) {
            var reader = new FileReader();
            reader.onload = function () {
                var area = root.querySelector('textarea[name="csv"]');
                if (area) area.value = String(reader.result || '');
                var slot = document.getElementById('jrc-ip-form-note');
                if (slot) slot.textContent = 'Arquivo lido. Confira e importe.';
            };
            reader.readAsText(event.target.files[0]);
        }
    }

    function bind() {
        if (root.dataset.bound === '1') return;
        root.dataset.bound = '1';
        root.addEventListener('click', onClick);
        root.addEventListener('input', onInput);
        root.addEventListener('change', onChange);
        root.addEventListener('submit', function (event) { event.preventDefault(); });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && form) { form = null; render(); }
        });
    }

    function mount() {
        if (!/\/ipam\.php$/.test(location.pathname)) return;
        document.documentElement.classList.add('jrc-ipam-page');
        var wrap = document.querySelector('.content-wrapper') || document.querySelector('.content') || document.body;
        var sheets = [];
        Array.prototype.forEach.call(wrap.querySelectorAll('link[href*="jrc-ipam.css"]'), function (node) {
            sheets.push(node);
        });
        root = document.getElementById('jrc-ipam');
        if (!root) {
            root = document.createElement('div');
            root.id = 'jrc-ipam';
        } else {
            root.remove();
        }
        wrap.innerHTML = '';
        sheets.forEach(function (node) { wrap.appendChild(node); });
        wrap.appendChild(root);
        bind();
        render();
        refresh().catch(function (err) {
            phase = 'error';
            note = err.message || 'Não foi possível carregar o cadastro.';
            render();
        });
    }

    function labelOf(link) {
        var labels = link.querySelectorAll('p, span');
        for (var i = labels.length - 1; i >= 0; i--) {
            if (!labels[i].closest('i') && !labels[i].classList.contains('badge') && !labels[i].classList.contains('jrc-al-count')) return labels[i];
        }
        return null;
    }

    function installMenu() {
        if (document.getElementById('jrc-ipam-nav')) return;
        var source = document.querySelector('a[href="/view-logs.php"], a[href="view-logs.php"]');
        if (!source) source = document.querySelector('a[href="/alerts.php"], a[href="alerts.php"]');
        if (!source) return;
        var onPage = /\/ipam\.php$/.test(location.pathname);
        var link = source.cloneNode(true);
        link.id = 'jrc-ipam-nav';
        link.setAttribute('href', '/ipam.php');
        if (onPage) link.classList.add('active');
        else link.classList.remove('active');
        var junk = link.querySelectorAll('.jrc-al-count, .badge, .navbar-badge');
        Array.prototype.forEach.call(junk, function (node) { node.remove(); });
        var icon = link.querySelector('i');
        if (icon) {
            icon.className = icon.className.replace(/\bbi-[a-z0-9-]+\b/g, ' ').replace(/\s+/g, ' ').trim();
            if (!/\bbi\b/.test(' ' + icon.className + ' ')) icon.className = (icon.className + ' bi').trim();
            icon.className = (icon.className + ' bi-diagram-3').replace(/\s+/g, ' ').trim();
        }
        var label = labelOf(link);
        if (label) {
            var wrote = false;
            for (var n = 0; n < label.childNodes.length; n++) {
                if (label.childNodes[n].nodeType === 3 && label.childNodes[n].textContent.trim()) {
                    label.childNodes[n].textContent = 'Endereçamento IP';
                    wrote = true;
                    break;
                }
            }
            if (!wrote) label.textContent = 'Endereçamento IP';
        } else {
            var kids = link.childNodes;
            var placed = false;
            for (var k = 0; k < kids.length; k++) {
                if (kids[k].nodeType === 3 && kids[k].textContent.trim()) {
                    kids[k].textContent = ' Endereçamento IP';
                    placed = true;
                }
            }
            if (!placed) link.appendChild(document.createTextNode(' Endereçamento IP'));
        }
        var nav = source.closest('nav, ul, .fb-nav, .nav-sidebar, .sidebar') || source.parentNode;
        if (onPage && nav) {
            Array.prototype.forEach.call(nav.querySelectorAll('a.active'), function (node) {
                if (node !== link) node.classList.remove('active');
            });
            link.classList.add('active');
        }
        var item = source.closest('li');
        if (item && item.parentNode) {
            var copy = item.cloneNode(false);
            copy.removeAttribute('id');
            copy.classList.remove('menu-open');
            copy.appendChild(link);
            item.parentNode.insertBefore(copy, item.nextSibling);
        } else if (source.parentNode) {
            source.parentNode.insertBefore(link, source.nextSibling);
        }
    }

    function boot() {
        installMenu();
        mount();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
}());
