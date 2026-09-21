/**
 * JR CONECT Dashboard - Source Policy
 *
 * IXC: rede, PPPoE e óptico.
 * TR-069/GenieACS: identidade/estado do CPE, Wi-Fi e clientes locais.
 *
 * Este arquivo é carregado após o JS inline do dashboard para preservar
 * o layout atual e substituir apenas a coleta do histórico.
 */
(() => {
    'use strict';

    window.JR_DASHBOARD_SOURCE_POLICY = Object.freeze({
        network: 'IXC principal / TR-069 fallback',
        optical: 'IXC principal / TR-069 fallback',
        pppoe: 'IXC principal / TR-069 fallback',
        equipment: 'TR-069 / GenieACS',
        wifi: 'TR-069 / GenieACS',
        clients: 'TR-069 / GenieACS',
        status: 'TR-069 / GenieACS'
    });

    const normalizeSerial = value =>
        String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');

    const escapeHtml = value =>
        String(value ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[ch]));

    const extractIPv4 = value => {
        if (typeof window.extractIP === 'function') {
            return window.extractIP(value);
        }
        const match = String(value || '').match(/(\d{1,3}(?:\.\d{1,3}){3})/);
        return match ? match[1] : 'N/A';
    };

    const fetchJson = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options,
            headers: {
                Accept: 'application/json',
                ...(options.headers || {})
            }
        });
        const data = await response.json();
        if (!response.ok || !data?.success) {
            throw new Error(data?.message || 'Consulta indisponível');
        }
        return data;
    };

    const rxBadge = (value, source) => {
        const n = Number(String(value ?? '').replace(',', '.'));
        if (!Number.isFinite(n) || Math.abs(n) < 0.001) {
            return '<span class="badge bg-secondary" title="Sem leitura válida">N/D</span>';
        }

        let cls = 'bg-danger';
        if (n > -20) cls = 'bg-success';
        else if (n >= -23) cls = 'bg-warning';

        return '<span class="badge ' + cls + '" title="Fonte: ' +
            escapeHtml(source) + '">' + escapeHtml(n.toFixed(2)) + ' dBm</span>';
    };

    const temperatureCell = (value, source) => {
        const n = Number(String(value ?? '').replace(',', '.'));
        if (!Number.isFinite(n) || Math.abs(n) < 0.001) {
            return '<span class="text-muted">N/D</span>';
        }
        return '<span title="Fonte: ' + escapeHtml(source) + '">' +
            escapeHtml(n.toFixed(1)) + '°C</span>';
    };

    const renderStatus = device => {
        if (String(device?.status || '').toLowerCase() === 'online') {
            const ping = device?.ping || '-';
            return '<span class="badge online" title="Fonte: TR-069 / GenieACS">ONLINE [' +
                escapeHtml(ping) + 'ms]</span>';
        }
        return '<span class="badge offline" title="Fonte: TR-069 / GenieACS">OFFLINE [-]</span>';
    };

    const renderClients = device => {
        const count = Number(device?.connected_devices_count || 0);
        return '<span class="badge ' + (count > 0 ? 'bg-primary' : 'bg-secondary') +
            '" title="Fonte: TR-069 / GenieACS">' + count + '</span>';
    };

    const buildMapButton = (device, mapInfo) => {
        const serial = String(device?.serial_number || '');
        if (mapInfo?.inMap) {
            const mapUrl = mapInfo.itemType === 'mikrotik'
                ? '/map.php?focus_type=server&focus_id=' + encodeURIComponent(mapInfo.itemId || '')
                : '/map.php?focus_type=onu&focus_serial=' + encodeURIComponent(serial);

            return '<button class="btn btn-sm btn-success me-1" ' +
                'onclick="window.open(\'' + mapUrl + '\', \'_blank\')" ' +
                'title="Visualizar no mapa"><i class="bi bi-map"></i></button>';
        }

        return '<button class="btn btn-sm btn-secondary me-1" ' +
            'onclick="showNotInMapAlert(\'' + encodeURIComponent(serial) + '\')" ' +
            'title="Não cadastrada no mapa"><i class="bi bi-map"></i></button>';
    };

    async function loadRecentDevicesIxcPrimary() {
        if (window.recentDevicesFetchInProgress) return;

        const container = document.getElementById('recent-devices');
        if (!container) return;

        window.recentDevicesFetchInProgress = true;
        container.innerHTML = '<div class="spinner"></div>';

        try {
            const recent = await fetchJson('/api/recent-devices.php');
            const devices = Array.isArray(recent.devices) ? recent.devices : [];

            if (!devices.length) {
                container.innerHTML =
                    '<p class="text-center text-muted">Nenhuma atividade recente encontrada</p>';
                return;
            }

            const serialNumbers = devices
                .map(device => device.serial_number)
                .filter(Boolean);

            const [mapResult, ixcResult] = await Promise.allSettled([
                fetchJson('/api/get-onu-location-batch.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ serial_numbers: serialNumbers })
                }),
                fetchJson('/api/get-devices-ixc-batch.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ serial_numbers: serialNumbers })
                })
            ]);

            const mapLocations = mapResult.status === 'fulfilled'
                ? (mapResult.value.locations || {})
                : {};
            const ixcDevices = ixcResult.status === 'fulfilled'
                ? (ixcResult.value.devices || {})
                : {};

            let html = '<div class="table-responsive">' +
                '<table class="table table-hover"><thead><tr>' +
                '<th>Serial <small class="text-muted">TR-069</small></th>' +
                '<th>MAC <small class="text-muted">TR-069</small></th>' +
                '<th>Modelo <small class="text-muted">TR-069</small></th>' +
                '<th>IP <small class="text-muted">IXC</small></th>' +
                '<th>SSID <small class="text-muted">TR-069</small></th>' +
                '<th>PPPoE <small class="text-muted">IXC</small></th>' +
                '<th>RX <small class="text-muted">IXC</small></th>' +
                '<th>Temperatura <small class="text-muted">IXC</small></th>' +
                '<th>Clientes <small class="text-muted">TR-069</small></th>' +
                '<th>Status <small class="text-muted">TR-069</small></th>' +
                '<th>Ações</th></tr></thead><tbody>';

            devices.forEach(device => {
                const serial = String(device.serial_number || '');
                const serialKey = normalizeSerial(serial);
                const ixc = ixcDevices[serialKey] || ixcDevices[serial] || {};
                const source = ixc.found
                    ? (ixc.source || 'IXC')
                    : 'TR-069 fallback';

                const ip = extractIPv4(ixc.ip || device.ip_tr069);
                const ipDisplay = ip !== 'N/A' && ip !== ''
                    ? '<a href="http://' + escapeHtml(ip) +
                      '" target="_blank" rel="noopener noreferrer" title="Fonte: ' +
                      escapeHtml(source) + '">' + escapeHtml(ip) + '</a>'
                    : '<span class="text-muted">N/D</span>';

                const pppoe = ixc.pppoe_username || device.pppoe_username || 'N/D';
                const rx = (ixc.rx_power !== null && ixc.rx_power !== undefined && ixc.rx_power !== '')
                    ? ixc.rx_power
                    : device.rx_power;
                const temperature = (
                    ixc.temperature !== null &&
                    ixc.temperature !== undefined &&
                    ixc.temperature !== ''
                ) ? ixc.temperature : device.temperature;

                const loc = mapLocations[serial] || mapLocations[serialKey] || {};
                const mapInfo = {
                    inMap: Boolean(loc.found),
                    itemType: loc.item_type || 'onu',
                    itemId: loc.onu?.id || loc.server?.id || null
                };

                html += '<tr>' +
                    '<td><a href="/device-detail.php?id=' +
                        encodeURIComponent(device.device_id || '') + '">' +
                        escapeHtml(serial || 'N/D') + '</a></td>' +
                    '<td title="Fonte: TR-069 / GenieACS">' +
                        escapeHtml(device.mac_address || 'N/D') + '</td>' +
                    '<td title="Fonte: TR-069 / GenieACS">' +
                        escapeHtml(device.product_class || 'N/D') + '</td>' +
                    '<td>' + ipDisplay + '</td>' +
                    '<td title="Fonte: TR-069 / GenieACS">' +
                        escapeHtml(device.wifi_ssid || 'N/D') + '</td>' +
                    '<td title="Fonte: ' + escapeHtml(source) + '">' +
                        escapeHtml(pppoe) + '</td>' +
                    '<td>' + rxBadge(rx, source) + '</td>' +
                    '<td>' + temperatureCell(temperature, source) + '</td>' +
                    '<td class="text-center">' + renderClients(device) + '</td>' +
                    '<td>' + renderStatus(device) + '</td>' +
                    '<td>' + buildMapButton(device, mapInfo) +
                    '<button class="btn btn-sm btn-primary" ' +
                        'onclick="summonDeviceQuick(\'' +
                        String(device.device_id || '').replace(/'/g, "\\'") +
                        '\')" title="Solicitar comunicação via TR-069">' +
                        '<i class="bi bi-lightning-charge"></i></button></td>' +
                    '</tr>';
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;

        } catch (error) {
            console.error('[DASHBOARD] Falha ao carregar histórico com política IXC/TR-069:', error);
            container.innerHTML =
                '<p class="text-center text-danger">Erro ao carregar os dados do histórico</p>';
        } finally {
            window.recentDevicesFetchInProgress = false;
        }
    }

    // Substitui apenas a coleta/renderização do histórico.
    window.loadRecentDevices = loadRecentDevicesIxcPrimary;

    // Aplica rótulos de origem sem alterar o layout.
    const decorateSources = () => {
        const cards = document.querySelectorAll(
            '.jr-ref-card, .acs-final-card, .card'
        );

        cards.forEach(card => {
            const text = (card.querySelector(
                '.jr-ref-card-head, .acs-final-card-head, .card-header'
            )?.textContent || '').trim().toLowerCase();

            if (!text) return;

            if (text.includes('histórico de dispositivos')) {
                card.title = 'Rede/PPPoE/óptico: IXC · CPE/Wi-Fi/status: TR-069';
            } else if (
                text.includes('leitura') ||
                text.includes('sinal') ||
                text.includes('estatísticas da rede')
            ) {
                card.title = 'Inventário e óptico: IXC · gerenciamento do CPE: TR-069';
            } else if (text.includes('uso de dispositivos')) {
                card.title = 'Modelo/fabricante: TR-069 com complemento cadastral IXC';
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', decorateSources, { once: true });
    } else {
        decorateSources();
    }
})();
