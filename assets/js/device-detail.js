const deviceId = window.DEVICE_ID || '';
let savedScrollPosition = 0;
let savedHotspotData = {}; // Store last known hotspot data

// Cache local dos dados ópticos para sobreviver ao auto-refresh de 30 segundos
let cachedOpticalData = null;
let opticalLoading = false;
let opticalLoadedForDevice = null;
let opticalRequestCounter = 0;
let currentDeviceData = null;

// Helper function to get active tab name
function getActiveTabName() {
    const activeTab = document.querySelector('.nav-link.active');
    if (!activeTab) return 'Unknown';

    const tabText = activeTab.textContent.trim();
    return tabText.replace(/\(\d+\)/g, '').trim(); // Remove badge counts like (2)
}

// Save current hotspot data before re-render
function saveCurrentHotspotData() {
    savedHotspotData = {};

    const userCells = document.querySelectorAll('.hotspot-user[data-mac]');
    const trafficCells = document.querySelectorAll('.hotspot-traffic[data-mac]');

    userCells.forEach(cell => {
        const mac = cell.getAttribute('data-mac');
        if (!savedHotspotData[mac]) savedHotspotData[mac] = {};
        savedHotspotData[mac].userHtml = cell.innerHTML;
    });

    trafficCells.forEach(cell => {
        const mac = cell.getAttribute('data-mac');
        if (!savedHotspotData[mac]) savedHotspotData[mac] = {};
        savedHotspotData[mac].trafficHtml = cell.innerHTML;
    });

    if (Object.keys(savedHotspotData).length > 0) {
        console.debug('[HOTSPOT] Saved data for', Object.keys(savedHotspotData).length, 'devices before re-render');
    }
}

// Restore saved hotspot data after re-render
function restoreSavedHotspotData() {
    let restoredCount = 0;

    Object.keys(savedHotspotData).forEach(mac => {
        const data = savedHotspotData[mac];
        const userCell = document.querySelector(`.hotspot-user[data-mac="${mac}"]`);
        const trafficCell = document.querySelector(`.hotspot-traffic[data-mac="${mac}"]`);

        if (userCell && data.userHtml) {
            userCell.innerHTML = data.userHtml;
            restoredCount++;
        }

        if (trafficCell && data.trafficHtml) {
            trafficCell.innerHTML = data.trafficHtml;
        }
    });

    if (restoredCount > 0) {
        console.debug('[HOTSPOT] Restored data for', restoredCount, 'devices after re-render');
    }
}

async function loadDeviceDetail(isAutoRefresh = false) {
    // SKIP auto-refresh if hotspot monitoring is active to prevent conflicts
    if (isAutoRefresh && hotspotMonitoringActive) {
        console.debug('[AUTO-REFRESH] Skipping device detail refresh while hotspot monitoring is active');
        return; // Skip refresh completely
    }

    // Save hotspot data BEFORE re-render (if auto-refresh)
    if (isAutoRefresh) {
        saveCurrentHotspotData();
    }

    // Save current scroll position if this is an auto-refresh
    if (isAutoRefresh) {
        savedScrollPosition = window.pageYOffset || document.documentElement.scrollTop;
        const activeTab = getActiveTabName();
        console.debug(`[AUTO-REFRESH] Saving scroll position: ${savedScrollPosition}px (Active tab: ${activeTab})`);
    } else {
        // Manual refresh - reset scroll to top
        savedScrollPosition = 0;
    }

    // For auto-refresh: NO loading spinner at all (silent refresh)
    // For manual refresh: Show full loading spinner
    if (!isAutoRefresh) {
        // Full loading for manual refresh only
        document.getElementById('loading-spinner').innerHTML = '<div class="spinner"></div>';
        document.getElementById('loading-spinner').style.display = 'block';
        document.getElementById('deviceTabs').style.display = 'none';
        document.getElementById('deviceTabContent').style.display = 'none';
    }

    const result = await fetchAPI('/api/get-device-detail.php?device_id=' + encodeURIComponent(deviceId));

    if (result && result.success) {
        const device = result.device;
        currentDeviceData = device;

        // Fetch ONU location from map
        const locationResult = await fetchAPI('/api/get-onu-location.php?serial_number=' + encodeURIComponent(device.serial_number));

        // Update modern device header
        document.getElementById('device-id-badge').textContent = device.serial_number;

        const modelTitle = document.getElementById('device-model-title');
        if (modelTitle) {
            modelTitle.textContent = device.product_class || device.model || device.manufacturer || 'Equipamento';
        }

        const ipHeader = document.getElementById('device-ip-header');
        if (ipHeader) {
            ipHeader.textContent = extractIP(device.ip_tr069) || 'IP não disponível';
        }

        const statusHeader = document.getElementById('device-status-header');
        if (statusHeader) {
            const online = String(device.status || '').toLowerCase() === 'online';
            statusHeader.textContent = online ? 'ONLINE' : 'OFFLINE';
            statusHeader.classList.toggle('online', online);
            statusHeader.classList.toggle('offline', !online);
        }

        // Update tags badge
        updateTagsBadge(device.tags || []);

        // Update badge counts
        document.getElementById('wan-count-badge').textContent = device.wan_details ? device.wan_details.length : 0;
        document.getElementById('devices-count-badge').textContent = device.connected_devices ? device.connected_devices.length : 0;

        // Populate Overview Tab - JR CONECT ACS V4 (modelo aprovado)
        const primaryWan = Array.isArray(device.wan_details)
            ? (device.wan_details.find(w => String(w.status || '').toLowerCase() === 'connected') || device.wan_details[0] || {})
            : {};

        const connectedDevices = Array.isArray(device.connected_devices) ? device.connected_devices : [];
        const wanCount = Array.isArray(device.wan_details) ? device.wan_details.length : 0;
        const wifiName = device.wifi_ssid || 'SSID não identificado';
        const lanPorts = Array.isArray(device.lan_ports) ? device.lan_ports : [];

        const renderLanVisual = (ports) => {
            const normalized = ports.length ? ports.slice(0, 4) : [1,2,3,4].map(i => ({ name: 'LAN ' + i, status: 'down' }));
            return normalized.map((port, idx) => {
                const name = port.name || ('LAN ' + (idx + 1));
                const status = String(port.status || '').toLowerCase();
                const isUp = status === 'up' || status === 'connected';
                const speedRaw = port.max_bit_rate || port.speed || '';
                let speed = '-';
                if (speedRaw !== '' && speedRaw !== null && speedRaw !== undefined && speedRaw !== 'N/A') {
                    const n = Number(speedRaw);
                    speed = Number.isFinite(n) ? (n >= 1000 ? (n / 1000) + ' Gbps' : n + ' Mbps') : String(speedRaw);
                }
                const portNumber = Number(port.port || (idx + 1));
                const ethernetDevices = connectedDevices.filter(d => String(d.interface_type || '').toLowerCase() === 'ethernet');
                const linked = ethernetDevices.find(d => Number(d.lan_port) === portNumber) || null;
                const linkedName = linked ? (linked.hostname || linked.vendor || linked.ip_address || 'Dispositivo') : (isUp ? 'Porta ativa' : '-');
                const linkedIp = linked?.ip_address || '';

                return `
                    <div class="acs-lan-visual ${isUp ? 'up' : 'down'}">
                        <strong>${name}</strong>
                        <div class="acs-rj45-port-face">
                            <span class="acs-rj45-slot"></span>
                            <span class="acs-rj45-pins"></span>
                        </div>
                        <span class="acs-lan-state">${isUp ? 'Conectada' : 'Desconectada'}</span>
                        <small>${isUp ? speed : '-'}</small>
                        <small>${isUp ? 'Full' : '-'}</small>
                        <div class="acs-lan-device ${linked ? 'identified' : ''}">
                            <i class="bi bi-pc-display"></i>
                            <span>${linkedName}</span>
                            ${linkedIp ? `<small>${linkedIp}</small>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        };

        const renderConnectedCompact = (items) => {
            if (!items.length) {
                return '<div class="acs-empty-state">Nenhum dispositivo conectado.</div>';
            }
            return `
                <div class="acs-connected-compact-head">
                    <span>Nome do dispositivo</span><span>IP</span><span>MAC</span><span>Via</span>
                </div>
                ${items.slice(0, 5).map((d, idx) => `
                    <div class="acs-connected-compact-row">
                        <strong>${d.vendor || d.hostname || 'Dispositivo ' + (idx + 1)}</strong>
                        <span>${d.ip_address || '-'}</span>
                        <span>${d.mac_address || '-'}</span>
                        <span>${d.lan_port ? ('LAN' + d.lan_port) : (d.interface_type || d.interface || '-')}</span>
                    </div>
                `).join('')}
            `;
        };

        document.getElementById('overview-content').innerHTML = `
            <div class="acs-overview-grid acs-approved-layout acs-overview-reorganized">

                <section class="acs-overview-card acs-card-device acs-approved-device acs-fiber-card">
                    <div class="acs-overview-card-header">
                        <div><span class="acs-kicker"><i class="bi bi-router"></i> Equipamento / Fibra</span></div>
                        <span class="acs-status-pill ${device.status === 'online' ? 'online' : 'offline'}">
                            <span class="acs-status-dot"></span>${device.status === 'online' ? 'ONLINE' : 'OFFLINE'}
                        </span>
                    </div>
                    <div class="acs-fiber-summary-grid">
                        <div class="acs-fiber-info">
                            <div class="acs-reference-list">
                                <div><span>Modelo</span><strong>${device.product_class || device.model || 'N/D'}</strong></div>
                                <div><span>ONU ID</span><strong>${device.serial_number || 'N/D'}</strong></div>
                                <div><span>Fabricante</span><strong>${device.manufacturer || 'N/D'}</strong></div>
                                <div><span>Firmware</span><strong>${device.software_version || 'N/D'}</strong></div>
                                <div><span>Hardware</span><strong>${device.hardware_version || 'N/D'}</strong></div>
                                <div><span>Uptime</span><strong>${formatUptime(device.uptime)}</strong></div>
                                <div><span>Última conexão</span><strong>${device.last_inform || 'N/D'}</strong></div>
                            </div>
                        </div>
                        <div class="acs-fiber-optical">
                            <div class="acs-device-combined-title"><i class="bi bi-reception-4"></i> Sinal óptico / GPON</div>
                            <div class="acs-reference-metrics acs-device-optical-metrics">
                                <div><span>RX Power</span><strong id="optical-rx-power">${renderOpticalCachedValue('rx_power', 'dBm', 'rx_status')}</strong></div>
                                <div><span>TX Power</span><strong id="optical-tx-power">${renderOpticalCachedValue('tx_power', 'dBm', 'tx_status')}</strong></div>
                                <div><span>Temperatura</span><strong id="optical-temperature">${renderOpticalCachedValue('temperature', '°C', 'temperature_status')}</strong></div>
                                <div><span>Voltagem</span><strong id="optical-voltage">${renderOpticalCachedValue('voltage', 'V', 'voltage_status')}</strong></div>
                            </div>
                            <div class="acs-reference-footer-grid acs-device-optical-footer">
                                <div><span>PON ID</span><strong id="optical-pon-id">${renderOpticalCachedPon()}</strong></div>
                                <div><span>Fonte</span><strong id="optical-source">${renderOpticalSource()}</strong></div>
                                <div><span>OLT</span><strong>-</strong></div>
                                <div><span>Atualização</span><strong id="optical-last-update">${renderOpticalLastUpdate()}</strong></div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="acs-overview-card acs-card-wan acs-approved-wan">
                    <div class="acs-overview-card-header">
                        <div><span class="acs-kicker"><i class="bi bi-globe2"></i> WAN / Internet</span></div>
                        <span class="acs-mini-badge ${String(primaryWan.status || '').toLowerCase()==='connected'?'success':''}">${primaryWan.status || 'STATUS'}</span>
                    </div>
                    <div class="acs-reference-list">
                        <div><span>Interface</span><strong>${primaryWan.name || 'WAN / TR-069'}</strong></div>
                        <div><span>IP</span><strong>${makeIPClickable(primaryWan.external_ip || extractIP(device.ip_tr069))}</strong></div>
                        <div><span>Método</span><strong>${primaryWan.type || primaryWan.connection_type || 'PPPoE'}</strong></div>
                        <div><span>Usuário PPPoE</span><strong>${primaryWan.username || 'Não disponível'}</strong></div>
                        <div><span>IPv6</span><strong>${primaryWan.ipv6 || 'Não disponível'}</strong></div>
                        <div><span>DNS</span><strong>${primaryWan.dns_servers || 'Não disponível'}</strong></div>
                        <div><span>VLAN</span><strong>${primaryWan.vlan_id || primaryWan.vlan || '-'}</strong></div>
                        <div><span>Último erro</span><strong>${primaryWan.last_error || '-'}</strong></div>
                    </div>
                </section>

                <section class="acs-overview-card acs-card-ai acs-approved-ai">
                    <div class="acs-overview-card-header">
                        <div><span class="acs-kicker"><i class="bi bi-stars"></i> Assistente IA</span></div>
                    </div>
                    <div class="acs-ai-question-only">
                        <div class="acs-ai-icon"><i class="bi bi-robot"></i></div>
                        <strong>Faça uma pergunta sobre este equipamento</strong>
                        <span>A IA vai analisar as informações e te ajudar.</span>
                        <div id="acs-ai-answer-overview" class="acs-ai-answer-overview"></div>
                        <div class="acs-ai-input-row">
                            <input id="acs-ai-question-overview" type="text" placeholder="Digite sua pergunta aqui..." onkeydown="if(event.key==='Enter'){runOverviewAIQuestion()}">
                            <button type="button" onclick="runOverviewAIQuestion()"><i class="bi bi-send-fill"></i></button>
                        </div>
                    </div>
                </section>

                <section class="acs-overview-card acs-card-wifi acs-approved-wifi">
                    <div class="acs-overview-card-header">
                        <div><span class="acs-kicker"><i class="bi bi-wifi"></i> Redes Wi-Fi</span></div>
                    </div>
                    <div class="acs-wifi-reference-grid"></div>
                </section>

                <section class="acs-overview-card acs-card-lan acs-approved-lan">
                    <div class="acs-overview-card-header">
                        <div><span class="acs-kicker"><i class="bi bi-ethernet"></i> Portas LAN</span></div>
                    </div>
                    <div class="acs-lan-visual-grid">${renderLanVisual(lanPorts)}</div>
                </section>

                <section class="acs-overview-card acs-card-connected acs-approved-connected">
                    <div class="acs-overview-card-header">
                        <div><span class="acs-kicker"><i class="bi bi-diagram-3-fill"></i> Dispositivos conectados</span></div>
                        <span class="acs-mini-badge">${connectedDevices.length} DISPOSITIVOS</span>
                    </div>
                    <div class="acs-connected-compact">${renderConnectedCompact(connectedDevices.slice(0,5))}</div>
                    <button type="button" class="acs-soft-btn acs-router-action" onclick="document.getElementById('devices-tab')?.click()">
                        <i class="bi bi-list-ul"></i> Ver todos
                    </button>
                </section>

                <section class="acs-overview-card acs-card-web acs-approved-web">
                    <div class="acs-overview-card-header">
                        <div><span class="acs-kicker"><i class="bi bi-browser-chrome"></i> Acesso Web</span></div>
                        <span class="acs-mini-badge ${device.status === 'online' ? 'success' : ''}">${device.status === 'online' ? 'DISPONÍVEL' : 'OFFLINE'}</span>
                    </div>
                    <div class="acs-reference-list">
                        <div><span>IP de gerenciamento</span><strong>${extractIP(device.ip_tr069) || device.ip_address || 'N/D'}</strong></div>
                        <div><span>Status</span><strong>${device.status === 'online' ? 'Disponível' : 'Equipamento offline'}</strong></div>
                    </div>
                    <button type="button" class="acs-soft-btn primary acs-router-action" onclick="openWebManagement()" ${device.status === 'online' ? '' : 'disabled'}>
                        <i class="bi bi-box-arrow-up-right"></i> Abrir gerenciamento web
                    </button>
                </section>

            </div>
        `;

        // Buscar dados ópticos FiberHome via TL1 sem bloquear o carregamento principal.
        // No auto-refresh, não inicia uma nova consulta se já existir uma em andamento
        // ou se já houver dados em cache para este equipamento.
        if (!isAutoRefresh) {
            loadFiberhomeOptical(device.device_id, true);
        } else if (opticalLoadedForDevice !== device.device_id && !opticalLoading) {
            loadFiberhomeOptical(device.device_id, false);
        }

        // Populate Topology Location Tab
        document.getElementById('topology-content').innerHTML = renderTopologyLocationTab(locationResult);

        // Populate WAN Connections Tab
        document.getElementById('wan-content').innerHTML = renderWANDetailsTab(device.wan_details);

        // Populate DHCP Server Tab
        document.getElementById('dhcp-content').innerHTML = renderDHCPServerTab(device.dhcp_server);

        // Populate Firmware Tab
        document.getElementById('firmware-content').innerHTML = renderFirmwareTab(device);

        // Populate Connected Devices Tab
        document.getElementById('devices-content').innerHTML = renderConnectedDevicesTab(device.connected_devices);

        // Populate Monitoring and AI tabs
        document.getElementById('monitoring-content').innerHTML = renderMonitoringTab(device);
        document.getElementById('ai-content').innerHTML = renderAIAssistantTab(device);
        updateRadiusBandwidthSample();

        // Restore hotspot data after re-render (if available)
        if (isAutoRefresh && Object.keys(savedHotspotData).length > 0) {
            setTimeout(() => {
                restoreSavedHotspotData();
                console.debug('[AUTO-REFRESH] Restored hotspot data for', Object.keys(savedHotspotData).length, 'devices');
            }, 50);
        }

        // Hide loading, show tabs (only if they were hidden)
        document.getElementById('loading-spinner').style.display = 'none';
        if (!isAutoRefresh) {
            document.getElementById('deviceTabs').style.display = 'flex';
            document.getElementById('deviceTabContent').style.display = 'block';
        }

        // Restore scroll position after refresh (if saved)
        if (isAutoRefresh && savedScrollPosition > 0) {
            setTimeout(() => {
                window.scrollTo(0, savedScrollPosition);
                const activeTab = getActiveTabName();
                console.debug(`[AUTO-REFRESH] ✓ Restored scroll position: ${savedScrollPosition}px (Active tab: ${activeTab})`);
            }, 50);
        }

        // Restore scroll position after Get Credentials (from sessionStorage)
        const credentialsScrollPos = sessionStorage.getItem('credentialsScrollPosition');
        if (credentialsScrollPos) {
            setTimeout(() => {
                window.scrollTo(0, parseInt(credentialsScrollPos));
                console.log('[GET-CREDENTIALS] ✓ Restored scroll position:', credentialsScrollPos);
                // Clear sessionStorage after use
                sessionStorage.removeItem('credentialsScrollPosition');
            }, 50);
        }

        // Restart hotspot monitoring if it was active before refresh
        if (hotspotMonitoringActive) {
            // Add delay to prevent immediate fetch after refresh (reduce load spikes)
            setTimeout(() => {
                const loadBtn = document.getElementById('load-hotspot-btn');
                if (loadBtn) {
                    console.log('[AUTO-REFRESH] Restarting hotspot monitoring (with 3s delay to reduce load)...');
                    // Don't call startHotspotTrafficMonitoring() directly - it fetches immediately
                    // Instead, just restart the interval without immediate fetch
                    const stopBtn = document.getElementById('stop-hotspot-btn');
                    const statusEl = document.getElementById('hotspot-status');

                    if (loadBtn) loadBtn.style.display = 'none';
                    if (stopBtn) stopBtn.style.display = 'inline-block';
                    if (statusEl) statusEl.innerHTML = '<span class="text-muted">⏱ Waiting for next update...</span>';

                    // 5 second interval - balanced between smooth updates and MikroTik load
                    hotspotTrafficInterval = setInterval(fetchHotspotTraffic, 5000);
                    console.log('[HOTSPOT] Monitoring restarted (5s interval)');
                }
            }, 3000); // 3 second delay before restarting
        }
    } else {
        // Show error in loading area
        document.getElementById('loading-spinner').innerHTML = '<div class="alert alert-danger">Failed to load device details</div>';
    }
}

function renderPhysicalPorts(ports) {
    if (!Array.isArray(ports) || ports.length === 0) {
        return `
            <div class="acs-physical-ports">
                <div class="acs-physical-ports-title">
                    <span><i class="bi bi-ethernet"></i> Portas físicas</span>
                    <small>Sem leitura</small>
                </div>
                <div class="acs-physical-ports-empty">Dados das portas LAN ainda não coletados.</div>
            </div>
        `;
    }

    const formatSpeed = (value) => {
        if (value === null || value === undefined || value === '' || value === 'N/A') return 'Auto';
        const text = String(value).trim();
        if (/^auto$/i.test(text)) return 'Auto';
        const speed = Number(text);
        if (!Number.isFinite(speed)) return text;
        if (speed >= 1000) return (speed / 1000) + 'G';
        return speed + 'M';
    };

    return `
        <div class="acs-physical-ports">
            <div class="acs-physical-ports-title">
                <span><i class="bi bi-ethernet"></i> Portas físicas</span>
                <small>${ports.length} LAN</small>
            </div>
            <div class="acs-physical-ports-grid">
                ${ports.map(port => {
                    const status = String(port.status || '').toLowerCase();
                    const isUp = status === 'up' || status === 'connected';
                    const isDisabled = port.enabled === false || String(port.enabled).toLowerCase() === 'false';

                    return `
                        <div class="acs-lan-port ${isUp ? 'link-up' : 'link-down'} ${isDisabled ? 'disabled' : ''}"
                             title="${port.name}: ${port.status || 'N/D'}">
                            <div class="acs-lan-port-icon"><i class="bi bi-ethernet"></i></div>
                            <strong>${port.name}</strong>
                            <span>${isUp ? formatSpeed(port.max_bit_rate) : 'Sem link'}</span>
                        </div>
                    `;
                }).join('')}
            </div>
        </div>
    `;
}

function extractIP(ipString) {
    if (!ipString || ipString === 'N/A') return 'N/A';
    const match = ipString.match(/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/);
    return match ? match[1] : 'N/A';
}

function makeIPClickable(ip) {
    if (!ip || ip === 'N/A' || ip === '0.0.0.0') {
        return ip;
    }

    // Check if it's a valid IP address
    const ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;
    if (!ipRegex.test(ip)) {
        return ip;
    }

    return `<a href="http://${ip}" target="_blank" rel="noopener noreferrer" title="Open http://${ip}">${ip}</a>`;
}

function renderTopologyLocationTab(locationResult) {
    if (!locationResult || !locationResult.success) {
        return '<div class="alert alert-info"><i class="bi bi-info-circle"></i> Unable to load topology location</div>';
    }

    // ONU not found in map
    if (!locationResult.location || !locationResult.location.found) {
        return `
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                <strong>Topology Location:</strong> This device has not been added to the network map yet.
                <a href="/map.php" class="alert-link">Add to map</a>
            </div>
        `;
    }

    const loc = locationResult.location;
    let html = '';

    // Build hierarchy path
    let pathParts = [];
    if (loc.server && loc.server.name) {
        pathParts.push(`<span class="badge bg-secondary">${loc.server.name}</span>`);
    }
    if (loc.olt && loc.olt.name) {
        pathParts.push(`<span class="badge bg-info">${loc.olt.name}</span>`);
    }
    if (loc.odc && loc.odc.name) {
        pathParts.push(`<span class="badge bg-warning text-dark">${loc.odc.name}</span>`);
    }
    if (loc.odp && loc.odp.name) {
        pathParts.push(`<span class="badge bg-success">${loc.odp.name}</span>`);
    }
    if (loc.onu && loc.onu.name) {
        pathParts.push(`<span class="badge bg-primary">${loc.onu.name}</span>`);
    }

    if (pathParts.length > 0) {
        html += '<div class="mb-3">';
        html += '<h6><i class="bi bi-diagram-3"></i> Hierarchy Path</h6>';
        html += '<div class="p-3 bg-light rounded">' + pathParts.join(' <i class="bi bi-arrow-right"></i> ') + '</div>';
        html += '</div>';
    }

    html += '<table class="table table-bordered">';

    // ODP Information
    if (loc.odp && loc.odp.name) {
        html += `
            <tr>
                <th width="30%"><i class="bi bi-box"></i> ODP</th>
                <td>
                    <strong>${loc.odp.name}</strong>
                    <a href="/map.php?focus_type=odp&focus_id=${loc.odp.id}" class="btn btn-sm btn-outline-primary ms-2" target="_blank">
                        <i class="bi bi-map"></i> View on Map
                    </a>
                </td>
            </tr>
        `;
    }

    // Port Number
    if (loc.onu && loc.onu.port && loc.onu.port !== 'N/A') {
        html += `
            <tr>
                <th><i class="bi bi-plug"></i> Port Number</th>
                <td><span class="badge bg-info">Port ${loc.onu.port}</span></td>
            </tr>
        `;
    }

    // ODC Information
    if (loc.odc && loc.odc.name) {
        html += `
            <tr>
                <th><i class="bi bi-building"></i> ODC</th>
                <td>
                    <strong>${loc.odc.name}</strong>
                    <a href="/map.php?focus_type=odc&focus_id=${loc.odc.id}" class="btn btn-sm btn-outline-warning ms-2" target="_blank">
                        <i class="bi bi-map"></i> View on Map
                    </a>
                </td>
            </tr>
        `;
    }

    // OLT Information
    if (loc.olt && loc.olt.name) {
        html += `
            <tr>
                <th><i class="bi bi-hdd-network"></i> OLT</th>
                <td><strong>${loc.olt.name}</strong></td>
            </tr>
        `;
    }

    // Coordinates and Google Maps
    if (loc.onu && loc.onu.lat && loc.onu.lng) {
        const lat = parseFloat(loc.onu.lat);
        const lng = parseFloat(loc.onu.lng);

        // Only show if coordinates are not 0,0
        if (lat !== 0 && lng !== 0) {
            const googleMapsUrl = `https://www.google.com/maps?q=${lat},${lng}`;

            html += `
                <tr>
                    <th><i class="bi bi-geo-alt"></i> Coordinates</th>
                    <td>
                        <code>${lat.toFixed(6)}, ${lng.toFixed(6)}</code>
                        <a href="${googleMapsUrl}" class="btn btn-sm btn-success ms-2" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-globe"></i> View on Google Maps
                        </a>
                        <a href="/map.php?focus_type=onu&focus_id=${loc.onu.id}" class="btn btn-sm btn-outline-primary ms-1" target="_blank">
                            <i class="bi bi-map"></i> View on Network Map
                        </a>
                    </td>
                </tr>
            `;
        }
    }

    html += '</table>';
    return html;
}

function renderTopologyLocation(locationResult) {
    if (!locationResult || !locationResult.success) {
        return '';
    }

    // ONU not found in map
    if (!locationResult.location || !locationResult.location.found) {
        return `
            <div class="alert alert-info mb-3">
                <i class="bi bi-info-circle"></i>
                <strong>Topology Location:</strong> This device has not been added to the network map yet.
                <a href="/map.php" class="alert-link">Add to map</a>
            </div>
        `;
    }

    const loc = locationResult.location;
    let html = '<div class="card mb-3 border-primary">';
    html += '<div class="card-header bg-primary text-white">';
    html += '<i class="bi bi-diagram-3"></i> <strong>Network Topology Location</strong>';
    html += '</div>';
    html += '<div class="card-body">';

    // Build hierarchy path
    let pathParts = [];
    if (loc.server && loc.server.name) {
        pathParts.push(`<span class="badge bg-secondary">${loc.server.name}</span>`);
    }
    if (loc.olt && loc.olt.name) {
        pathParts.push(`<span class="badge bg-info">${loc.olt.name}</span>`);
    }
    if (loc.odc && loc.odc.name) {
        pathParts.push(`<span class="badge bg-warning text-dark">${loc.odc.name}</span>`);
    }
    if (loc.odp && loc.odp.name) {
        pathParts.push(`<span class="badge bg-success">${loc.odp.name}</span>`);
    }
    if (loc.onu && loc.onu.name) {
        pathParts.push(`<span class="badge bg-primary">${loc.onu.name}</span>`);
    }

    if (pathParts.length > 0) {
        html += '<div class="mb-3">';
        html += '<strong>Hierarchy Path:</strong><br>';
        html += pathParts.join(' <i class="bi bi-arrow-right"></i> ');
        html += '</div>';
    }

    html += '<table class="table table-sm table-bordered mb-0">';

    // ODP Information
    if (loc.odp && loc.odp.name) {
        html += `
            <tr>
                <th width="30%"><i class="bi bi-box"></i> ODP</th>
                <td>
                    <strong>${loc.odp.name}</strong>
                    <a href="/map.php?focus_type=odp&focus_id=${loc.odp.id}" class="btn btn-sm btn-outline-primary ms-2" target="_blank">
                        <i class="bi bi-map"></i> View on Map
                    </a>
                </td>
            </tr>
        `;
    }

    // Port Number
    if (loc.onu && loc.onu.port && loc.onu.port !== 'N/A') {
        html += `
            <tr>
                <th><i class="bi bi-plug"></i> Port Number</th>
                <td><span class="badge bg-info">Port ${loc.onu.port}</span></td>
            </tr>
        `;
    }

    // ODC Information
    if (loc.odc && loc.odc.name) {
        html += `
            <tr>
                <th><i class="bi bi-building"></i> ODC</th>
                <td>
                    <strong>${loc.odc.name}</strong>
                    <a href="/map.php?focus_type=odc&focus_id=${loc.odc.id}" class="btn btn-sm btn-outline-warning ms-2" target="_blank">
                        <i class="bi bi-map"></i> View on Map
                    </a>
                </td>
            </tr>
        `;
    }

    // OLT Information
    if (loc.olt && loc.olt.name) {
        html += `
            <tr>
                <th><i class="bi bi-hdd-network"></i> OLT</th>
                <td><strong>${loc.olt.name}</strong></td>
            </tr>
        `;
    }

    // Coordinates and Google Maps
    if (loc.onu && loc.onu.lat && loc.onu.lng) {
        const lat = parseFloat(loc.onu.lat);
        const lng = parseFloat(loc.onu.lng);

        // Only show if coordinates are not 0,0
        if (lat !== 0 && lng !== 0) {
            const googleMapsUrl = `https://www.google.com/maps?q=${lat},${lng}`;

            html += `
                <tr>
                    <th><i class="bi bi-geo-alt"></i> Coordinates</th>
                    <td>
                        <code>${lat.toFixed(6)}, ${lng.toFixed(6)}</code>
                        <a href="${googleMapsUrl}" class="btn btn-sm btn-success ms-2" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-globe"></i> View on Google Maps
                        </a>
                        <a href="/map.php?focus_type=onu&focus_id=${loc.onu.id}" class="btn btn-sm btn-outline-primary ms-1" target="_blank">
                            <i class="bi bi-map"></i> View on Network Map
                        </a>
                    </td>
                </tr>
            `;
        }
    }

    html += '</table>';
    html += '</div>';
    html += '</div>';

    return html;
}

function renderWANDetailsTab(wanDetails) {
    const hasValue = (value) => (
        value !== null &&
        value !== undefined &&
        value !== '' &&
        value !== 'N/A' &&
        value !== '0.0.0.0'
    );

    if (!wanDetails || wanDetails.length === 0) {
        return `
            <div class="acs-wan-page">
                <div class="acs-wan-page-header">
                    <div>
                        <span class="acs-kicker"><i class="bi bi-globe2"></i> Conectividade</span>
                        <h4>Interfaces WAN</h4>
                        <p>Nenhuma conexão WAN foi identificada neste equipamento.</p>
                    </div>
                    <button class="acs-wan-primary-btn" type="button" onclick="openAddWANModal()">
                        <i class="bi bi-plus-lg"></i> Adicionar WAN
                    </button>
                </div>

                <div class="acs-wan-empty">
                    <i class="bi bi-router"></i>
                    <strong>Sem conexões WAN</strong>
                    <span>Quando o equipamento disponibilizar uma interface WAN pelo TR-069, ela aparecerá aqui.</span>
                    <button type="button" onclick="openAddWANModal()">
                        <i class="bi bi-plus-lg"></i> Adicionar primeira conexão
                    </button>
                </div>
            </div>
        `;
    }

    const connectedCount = wanDetails.filter(wan => String(wan.status || '').toLowerCase() === 'connected').length;

    let html = `
        <div class="acs-wan-page">
            <div class="acs-wan-page-header">
                <div>
                    <span class="acs-kicker"><i class="bi bi-globe2"></i> Conectividade</span>
                    <h4>Interfaces WAN</h4>
                    <p>Parâmetros de Internet coletados do equipamento via TR-069.</p>
                </div>

                <div class="acs-wan-header-actions">
                    <div class="acs-wan-summary-pill">
                        <strong>${connectedCount}</strong>
                        <span>conectada${connectedCount === 1 ? '' : 's'}</span>
                    </div>
                    <div class="acs-wan-summary-pill">
                        <strong>${wanDetails.length}</strong>
                        <span>total</span>
                    </div>
                    <button class="acs-wan-primary-btn" type="button" onclick="openAddWANModal()">
                        <i class="bi bi-plus-lg"></i> Adicionar WAN
                    </button>
                </div>
            </div>

            <div class="acs-wan-grid">
    `;

    wanDetails.forEach((wan, index) => {
        const isConnected = String(wan.status || '').toLowerCase() === 'connected';
        const connectionType = wan.connection_type || 'N/A';
        const isBridge = /bridge|bridged/i.test(connectionType);
        const vlanMatch = String(wan.name || '').match(/VID[_-]?(\d+)/i);
        const vlanId = vlanMatch ? vlanMatch[1] : null;
        const isTR069 = /TR069|CWMP/i.test(String(wan.service_list || '')) || /TR069|CWMP/i.test(String(wan.name || ''));
        const displayName = wan.name || `WAN ${index + 1}`;
        const typeLabel = wan.type || (isBridge ? 'Bridge' : 'WAN');

        html += `
            <section class="acs-wan-card">
                <div class="acs-wan-card-top">
                    <div class="acs-wan-card-identity">
                        <div class="acs-wan-card-icon">
                            <i class="bi ${isBridge ? 'bi-diagram-3' : (wan.type === 'PPPoE' ? 'bi-person-badge' : 'bi-globe2')}"></i>
                        </div>
                        <div>
                            <span class="acs-wan-index">WAN ${index + 1}</span>
                            <h5>${displayName}</h5>
                        </div>
                    </div>

                    <div class="acs-wan-card-status">
                        <span class="acs-wan-state ${isConnected ? 'online' : 'offline'}">
                            <span></span>
                            ${isConnected ? 'CONECTADA' : 'DESCONECTADA'}
                        </span>
                    </div>
                </div>

                <div class="acs-wan-tags">
                    <span>${typeLabel}</span>
                    <span>${connectionType}</span>
                    ${vlanId ? `<span>VLAN ${vlanId}</span>` : ''}
                    ${isTR069 ? '<span class="danger">TR-069</span>' : ''}
                </div>

                <div class="acs-wan-hero">
                    <span>Endereço IP</span>
                    <strong>${hasValue(wan.external_ip) ? makeIPClickable(wan.external_ip) : 'Não disponível'}</strong>
                </div>

                <div class="acs-wan-details">
                    ${hasValue(wan.gateway) ? `
                        <div>
                            <span><i class="bi bi-signpost-2"></i> Gateway</span>
                            <strong>${makeIPClickable(wan.gateway)}</strong>
                        </div>
                    ` : ''}

                    ${hasValue(wan.subnet_mask) ? `
                        <div>
                            <span><i class="bi bi-diagram-2"></i> Máscara</span>
                            <strong>${wan.subnet_mask}</strong>
                        </div>
                    ` : ''}

                    ${hasValue(wan.dns_servers) ? `
                        <div>
                            <span><i class="bi bi-hdd-network"></i> DNS</span>
                            <strong>${wan.dns_servers}</strong>
                        </div>
                    ` : ''}

                    ${hasValue(wan.mac_address) && wan.mac_address !== '00:00:00:00:00:00' ? `
                        <div>
                            <span><i class="bi bi-upc-scan"></i> MAC</span>
                            <strong>${wan.mac_address}</strong>
                        </div>
                    ` : ''}

                    ${wan.type === 'PPPoE' && hasValue(wan.username) ? `
                        <div>
                            <span><i class="bi bi-person"></i> Usuário PPPoE</span>
                            <strong>${wan.username}</strong>
                        </div>
                    ` : ''}

                    ${wan.type === 'IP' && hasValue(wan.addressing_type) ? `
                        <div>
                            <span><i class="bi bi-gear-wide-connected"></i> Endereçamento</span>
                            <strong>${wan.addressing_type}</strong>
                        </div>
                    ` : ''}

                    ${hasValue(wan.binding) ? `
                        <div>
                            <span><i class="bi bi-link-45deg"></i> Binding</span>
                            <strong>${wan.binding}</strong>
                        </div>
                    ` : ''}

                    ${hasValue(wan.uptime) && String(wan.uptime) !== '0' ? `
                        <div>
                            <span><i class="bi bi-clock-history"></i> Uptime</span>
                            <strong>${formatUptime(wan.uptime)}</strong>
                        </div>
                    ` : ''}

                    ${wan.type === 'PPPoE' && hasValue(wan.mru_size) && String(wan.mru_size) !== '0' ? `
                        <div>
                            <span><i class="bi bi-arrows-expand"></i> MRU</span>
                            <strong>${wan.mru_size}</strong>
                        </div>
                    ` : ''}

                    ${wan.type === 'PPPoE' && hasValue(wan.last_error) ? `
                        <div class="acs-wan-error-row">
                            <span><i class="bi bi-exclamation-triangle"></i> Último erro</span>
                            <strong>${wan.last_error}</strong>
                        </div>
                    ` : ''}
                </div>

                <div class="acs-wan-card-actions">
                    <button type="button" onclick='openEditWANModal(${JSON.stringify(wan)})'>
                        <i class="bi bi-pencil"></i> Editar
                    </button>
                    <button type="button" class="danger" onclick='openDeleteWANModal(${JSON.stringify(wan)})'>
                        <i class="bi bi-trash"></i> Excluir
                    </button>
                </div>
            </section>
        `;
    });

    html += `
            </div>

            <div class="acs-wan-footnote">
                <i class="bi bi-info-circle"></i>
                <span>IPv6 será exibido aqui quando adicionarmos a coleta desses parâmetros ao backend do ACS.</span>
            </div>
        </div>
    `;

    return html;
}

function renderWANDetails(wanDetails) {
    if (!wanDetails || wanDetails.length === 0) {
        return '';
    }

    let html = '<div class="row mt-3"><div class="col-md-12">';
    html += '<div class="d-flex justify-content-between align-items-center mb-2">';
    html += '<h6><i class="bi bi-globe"></i> WAN Connection Details</h6>';
    html += '<button class="btn btn-sm btn-success" onclick="openAddWANModal()"><i class="bi bi-plus-lg"></i> Add WAN Connection</button>';
    html += '</div>';

    wanDetails.forEach((wan, index) => {
        const statusBadge = wan.status === 'Connected' ?
            '<span class="badge online">Connected</span>' :
            '<span class="badge offline">Disconnected</span>';

        // Check if this is a bridge connection
        const isBridge = wan.connection_type && (
            wan.connection_type.includes('Bridge') ||
            wan.connection_type.includes('Bridged')
        );

        // Extract VLAN ID from connection name (e.g., "2_INTERNET_B_VID_20" -> "20")
        const vlanMatch = wan.name.match(/VID[_-]?(\d+)/i);
        const vlanId = vlanMatch ? vlanMatch[1] : null;

        // Check if this is TR069 connection
        const isTR069 = (wan.service_list && (wan.service_list.toUpperCase().includes('TR069') || wan.service_list.toUpperCase().includes('CWMP'))) ||
                        (wan.name && (wan.name.toUpperCase().includes('TR069') || wan.name.toUpperCase().includes('CWMP')));

        html += `
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${wan.name}</strong>
                            <span class="badge bg-info ms-2">${wan.type}</span>
                            ${statusBadge}
                            ${isBridge ? '<span class="badge bg-secondary ms-2">Bridge Mode</span>' : ''}
                            ${isTR069 ? '<span class="badge bg-danger ms-2"><i class="bi bi-exclamation-triangle"></i> TR069</span>' : ''}
                        </div>
                        <div>
                            <button class="btn btn-sm btn-warning" onclick='openEditWANModal(${JSON.stringify(wan)})'>
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <button class="btn btn-sm btn-danger" onclick='openDeleteWANModal(${JSON.stringify(wan)})'>
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">`;

        // For bridge connections, show minimal info
        if (isBridge) {
            html += `
                    <table class="table table-sm table-bordered mb-0">
                        <tr>
                            <th width="30%">Connection Type</th>
                            <td>${wan.connection_type}</td>
                        </tr>`;

            if (vlanId) {
                html += `
                        <tr>
                            <th>VLAN ID</th>
                            <td>${vlanId}</td>
                        </tr>`;
            }

            if (wan.binding && wan.binding !== 'N/A') {
                html += `
                        <tr>
                            <th>Bound to</th>
                            <td><span class="badge bg-primary">${wan.binding}</span></td>
                        </tr>`;
            }

            html += `
                    </table>`;
        } else {
            // For routed connections, show all available info
            html += `
                    <table class="table table-sm table-bordered mb-0">
                        <tr>
                            <th width="30%">Connection Type</th>
                            <td>${wan.connection_type}</td>
                        </tr>`;

            if (vlanId) {
                html += `
                        <tr>
                            <th>VLAN ID</th>
                            <td>${vlanId}</td>
                        </tr>`;
            }

            if (wan.binding && wan.binding !== 'N/A') {
                html += `
                        <tr>
                            <th>Bound to</th>
                            <td><span class="badge bg-primary">${wan.binding}</span></td>
                        </tr>`;
            }

            // Show External IP if available
            if (wan.external_ip && wan.external_ip !== 'N/A' && wan.external_ip !== '0.0.0.0') {
                html += `
                        <tr>
                            <th>External IP</th>
                            <td>${makeIPClickable(wan.external_ip)}</td>
                        </tr>`;
            }

            // Show Gateway if available
            if (wan.gateway && wan.gateway !== 'N/A' && wan.gateway !== '0.0.0.0') {
                html += `
                        <tr>
                            <th>Gateway</th>
                            <td>${makeIPClickable(wan.gateway)}</td>
                        </tr>`;
            }

            // Show Subnet Mask if available
            if (wan.subnet_mask && wan.subnet_mask !== 'N/A') {
                html += `
                        <tr>
                            <th>Subnet Mask</th>
                            <td>${wan.subnet_mask}</td>
                        </tr>`;
            }

            // Show DNS Servers if available
            if (wan.dns_servers && wan.dns_servers !== 'N/A' && wan.dns_servers !== '') {
                html += `
                        <tr>
                            <th>DNS Servers</th>
                            <td>${wan.dns_servers}</td>
                        </tr>`;
            }

            // Show MAC Address if available
            if (wan.mac_address && wan.mac_address !== 'N/A' && wan.mac_address !== '00:00:00:00:00:00') {
                html += `
                        <tr>
                            <th>MAC Address</th>
                            <td>${wan.mac_address}</td>
                        </tr>`;
            }

            // PPPoE specific fields
            if (wan.type === 'PPPoE') {
                if (wan.username && wan.username !== 'N/A' && wan.username !== '') {
                    html += `
                        <tr>
                            <th>Username</th>
                            <td>${wan.username}</td>
                        </tr>`;
                }

                if (wan.last_error && wan.last_error !== 'N/A') {
                    html += `
                        <tr>
                            <th>Last Error</th>
                            <td>${wan.last_error}</td>
                        </tr>`;
                }

                if (wan.mru_size && wan.mru_size !== 'N/A' && wan.mru_size !== '0' && wan.mru_size !== 0) {
                    html += `
                        <tr>
                            <th>MRU Size</th>
                            <td>${wan.mru_size}</td>
                        </tr>`;
                }
            }

            // IP Connection specific fields
            if (wan.type === 'IP') {
                if (wan.addressing_type && wan.addressing_type !== 'N/A') {
                    html += `
                        <tr>
                            <th>Addressing Type</th>
                            <td>${wan.addressing_type}</td>
                        </tr>`;
                }
            }

            // Show uptime if available and not 0
            if (wan.uptime && wan.uptime !== 'N/A' && wan.uptime !== '0' && wan.uptime !== 0) {
                html += `
                        <tr>
                            <th>Uptime</th>
                            <td>${formatUptime(wan.uptime)}</td>
                        </tr>`;
            }

            html += `
                    </table>`;
        }

        html += `
                </div>
            </div>
        `;
    });

    html += '</div></div>';
    return html;
}

function renderDHCPServerTab(dhcpServer) {
    if (!dhcpServer) {
        return '<div class="alert alert-info"><i class="bi bi-info-circle"></i> DHCP server not supported on this device</div>';
    }

    let html = '<div class="d-flex justify-content-between align-items-center mb-3">';
    html += '<h6><i class="bi bi-router"></i> DHCP Server Configuration</h6>';
    html += `<button class="btn btn-sm btn-primary" onclick='openEditDHCPModal(${JSON.stringify(dhcpServer)})'><i class="bi bi-pencil"></i> Edit DHCP</button>`;
    html += '</div>';

    const dhcpEnabled = dhcpServer.enabled === true || dhcpServer.enabled === 'true';
    const statusBadge = dhcpEnabled ?
        '<span class="badge online">Enabled</span>' :
        '<span class="badge offline">Disabled</span>';

    html += `
        <div class="card">
            <div class="card-header bg-light">
                <strong>DHCP Server Status</strong>
                ${statusBadge}
            </div>
            <div class="card-body">
                <table class="table table-sm table-bordered mb-0">
                    <tr>
                        <th width="30%">DHCP Server</th>
                        <td>${dhcpEnabled ? 'Enabled' : 'Disabled'}</td>
                    </tr>`;

    if (dhcpEnabled || dhcpServer.min_address !== 'N/A') {
        html += `
                    <tr>
                        <th>IP Address Pool Start</th>
                        <td>${dhcpServer.min_address}</td>
                    </tr>
                    <tr>
                        <th>IP Address Pool End</th>
                        <td>${dhcpServer.max_address || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Subnet Mask</th>
                        <td>${dhcpServer.subnet_mask || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Default Gateway</th>
                        <td>${makeIPClickable(dhcpServer.gateway || 'N/A')}</td>
                    </tr>
                    <tr>
                        <th>DNS Servers</th>
                        <td>${dhcpServer.dns_servers || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Lease Time</th>
                        <td>${dhcpServer.lease_time ? formatUptime(dhcpServer.lease_time) : 'N/A'}</td>
                    </tr>`;
    }

    html += `
                </table>
            </div>
        </div>
    `;

    return html;
}

function renderDHCPServer(dhcpServer) {
    if (!dhcpServer) {
        return '';
    }

    let html = '<div class="row mt-3"><div class="col-md-12">';
    html += '<div class="d-flex justify-content-between align-items-center mb-2">';
    html += '<h6><i class="bi bi-router"></i> DHCP Server Configuration</h6>';
    html += `<button class="btn btn-sm btn-primary" onclick='openEditDHCPModal(${JSON.stringify(dhcpServer)})'><i class="bi bi-pencil"></i> Edit DHCP</button>`;
    html += '</div>';

    const dhcpEnabled = dhcpServer.enabled === true || dhcpServer.enabled === 'true';
    const statusBadge = dhcpEnabled ?
        '<span class="badge online">Enabled</span>' :
        '<span class="badge offline">Disabled</span>';

    html += `
        <div class="card">
            <div class="card-header bg-light">
                <strong>DHCP Server Status</strong>
                ${statusBadge}
            </div>
            <div class="card-body">
                <table class="table table-sm table-bordered mb-0">
                    <tr>
                        <th width="30%">DHCP Server</th>
                        <td>${dhcpEnabled ? 'Enabled' : 'Disabled'}</td>
                    </tr>`;

    if (dhcpEnabled && dhcpServer.min_address) {
        html += `
                    <tr>
                        <th>IP Address Pool Start</th>
                        <td>${dhcpServer.min_address}</td>
                    </tr>
                    <tr>
                        <th>IP Address Pool End</th>
                        <td>${dhcpServer.max_address || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Subnet Mask</th>
                        <td>${dhcpServer.subnet_mask || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Default Gateway</th>
                        <td>${makeIPClickable(dhcpServer.gateway || 'N/A')}</td>
                    </tr>
                    <tr>
                        <th>DNS Servers</th>
                        <td>${dhcpServer.dns_servers || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Lease Time</th>
                        <td>${dhcpServer.lease_time ? formatUptime(dhcpServer.lease_time) : 'N/A'}</td>
                    </tr>`;
    }

    html += `
                </table>
            </div>
        </div>
    `;

    html += '</div></div>';
    return html;
}

function renderConnectedDevicesTab(connectedDevices) {
    if (!connectedDevices || connectedDevices.length === 0) {
        return `
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No devices currently connected to this ONU
            </div>
        `;
    }

    let html = '<h6><i class="bi bi-hdd-network"></i> Connected Devices <span class="badge bg-primary">' + connectedDevices.length + '</span></h6>';

    html += `
        <div class="mb-3">
            <small class="text-muted" id="hotspot-status"><i class="bi bi-router"></i> Hotspot monitoring will start automatically...</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover" id="connected-devices-table">
                <thead class="table-light">
                    <tr>
                        <th width="4%" class="text-center">#</th>
                        <th width="18%"><i class="bi bi-pc-display"></i> Device Name</th>
                        <th width="12%"><i class="bi bi-hdd-network"></i> IP Address</th>
                        <th width="15%"><i class="bi bi-ethernet"></i> MAC Address</th>
                        <th width="10%" class="text-center"><i class="bi bi-wifi"></i> Connection</th>
                        <th width="10%" class="text-center"><i class="bi bi-check-circle"></i> Status</th>
                        <th width="13%"><i class="bi bi-person-badge"></i> Hotspot User</th>
                        <th width="18%"><i class="bi bi-speedometer2"></i> Traffic (RX / TX)</th>
                    </tr>
                </thead>
                <tbody>
    `;

    connectedDevices.forEach((device, index) => {
        // Determine connection type icon
        let connectionIcon = 'bi-ethernet';
        let connectionBadge = 'bg-secondary';
        if (device.interface_type === 'WiFi') {
            connectionIcon = 'bi-wifi';
            connectionBadge = 'bg-info';
        } else if (device.interface_type === 'Ethernet') {
            connectionIcon = 'bi-ethernet';
            connectionBadge = 'bg-success';
        }

        // Determine status badge
        const statusBadge = device.active ?
            '<span class="badge online">Active</span>' :
            '<span class="badge offline">Inactive</span>';

        // Display vendor name if available, otherwise use hostname
        const displayName = device.vendor || device.hostname || 'Unknown Device';

        html += `
            <tr data-mac="${device.mac_address}">
                <td class="text-center">${index + 1}</td>
                <td>
                    <i class="bi bi-pc-display text-primary"></i> <strong>${displayName}</strong>
                    ${device.hostname && device.vendor && device.hostname !== device.vendor ? `<br><small class="text-muted">Hostname: ${device.hostname}</small>` : ''}
                </td>
                <td>${makeIPClickable(device.ip_address)}</td>
                <td><code>${device.mac_address}</code></td>
                <td class="text-center">
                    <span class="badge ${connectionBadge}">
                        <i class="${connectionIcon}"></i> ${device.interface_type}
                    </span>
                </td>
                <td class="text-center">${statusBadge}</td>
                <td class="hotspot-user" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
                <td class="hotspot-traffic" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    return html;
}

function renderConnectedDevices(connectedDevices) {
    if (!connectedDevices || connectedDevices.length === 0) {
        return `
            <div class="row mt-3">
                <div class="col-md-12">
                    <h6><i class="bi bi-hdd-network"></i> Connected Devices <span class="badge bg-secondary">0</span></h6>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No devices currently connected to this ONU
                    </div>
                </div>
            </div>
        `;
    }

    let html = '<div class="row mt-3"><div class="col-md-12">';
    html += `<h6><i class="bi bi-hdd-network"></i> Connected Devices <span class="badge bg-primary">${connectedDevices.length}</span></h6>`;

    html += `
        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover" id="connected-devices-table">
                <thead class="table-light">
                    <tr>
                        <th width="4%" class="text-center">#</th>
                        <th width="18%"><i class="bi bi-pc-display"></i> Device Name</th>
                        <th width="12%"><i class="bi bi-hdd-network"></i> IP Address</th>
                        <th width="15%"><i class="bi bi-ethernet"></i> MAC Address</th>
                        <th width="10%" class="text-center"><i class="bi bi-wifi"></i> Connection</th>
                        <th width="10%" class="text-center"><i class="bi bi-check-circle"></i> Status</th>
                        <th width="13%"><i class="bi bi-person-badge"></i> Hotspot User</th>
                        <th width="18%"><i class="bi bi-speedometer2"></i> Traffic (RX / TX)</th>
                    </tr>
                </thead>
                <tbody>
    `;

    connectedDevices.forEach((device, index) => {
        // Determine connection type icon
        let connectionIcon = 'bi-ethernet';
        let connectionBadge = 'bg-secondary';
        if (device.interface_type === 'WiFi') {
            connectionIcon = 'bi-wifi';
            connectionBadge = 'bg-info';
        } else if (device.interface_type === 'Ethernet') {
            connectionIcon = 'bi-ethernet';
            connectionBadge = 'bg-success';
        }

        // Determine status badge
        const statusBadge = device.active ?
            '<span class="badge online">Active</span>' :
            '<span class="badge offline">Inactive</span>';

        // Display vendor name if available, otherwise use hostname
        const displayName = device.vendor || device.hostname || 'Unknown Device';

        html += `
            <tr data-mac="${device.mac_address}">
                <td class="text-center">${index + 1}</td>
                <td>
                    <i class="bi bi-pc-display text-primary"></i> <strong>${displayName}</strong>
                    ${device.hostname && device.vendor && device.hostname !== device.vendor ? `<br><small class="text-muted">Hostname: ${device.hostname}</small>` : ''}
                </td>
                <td>${makeIPClickable(device.ip_address)}</td>
                <td><code>${device.mac_address}</code></td>
                <td class="text-center">
                    <span class="badge ${connectionBadge}">
                        <i class="${connectionIcon}"></i> ${device.interface_type}
                    </span>
                </td>
                <td class="text-center">${statusBadge}</td>
                <td class="hotspot-user" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
                <td class="hotspot-traffic" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    html += '</div></div>';
    return html;
}

function togglePassword() {
    const hidden = document.getElementById('wifi-pass-hidden');
    const shown = document.getElementById('wifi-pass-shown');
    const icon = document.getElementById('toggle-icon');

    if (hidden.style.display === 'none') {
        hidden.style.display = 'inline';
        shown.style.display = 'none';
        icon.className = 'bi bi-eye';
    } else {
        hidden.style.display = 'none';
        shown.style.display = 'inline';
        icon.className = 'bi bi-eye-slash';
    }
}

function toggleAdminPassword() {
    const hidden = document.getElementById('admin-pass-hidden');
    const shown = document.getElementById('admin-pass-shown');
    const icon = document.getElementById('admin-toggle-icon');

    if (hidden.style.display === 'none') {
        hidden.style.display = 'inline';
        shown.style.display = 'none';
        icon.className = 'bi bi-eye';
    } else {
        hidden.style.display = 'none';
        shown.style.display = 'inline';
        icon.className = 'bi bi-eye-slash';
    }
}

function toggleTelecomPassword() {
    const hidden = document.getElementById('telecom-pass-hidden');
    const shown = document.getElementById('telecom-pass-shown');
    const icon = document.getElementById('telecom-toggle-icon');

    if (hidden.style.display === 'none') {
        hidden.style.display = 'inline';
        shown.style.display = 'none';
        icon.className = 'bi bi-eye';
    } else {
        hidden.style.display = 'none';
        shown.style.display = 'inline';
        icon.className = 'bi bi-eye-slash';
    }
}

function summonDevice() {
    document.getElementById('summon-device-id').textContent = deviceId;
    const modal = new bootstrap.Modal(document.getElementById('summonModal'), {
        backdrop: false
    });
    modal.show();
}

// Summon device specifically to get admin credentials
// Uses GenieACS task to fetch VirtualParameters (superAdmin, superPassword)
async function summonForAdminCredentials() {
    const btn = document.getElementById('get-credentials-btn');
    const statusDiv = document.getElementById('credentials-status');
    const statusText = document.getElementById('credentials-status-text');

    // Disable button and show status
    if (btn) btn.disabled = true;
    if (statusDiv) statusDiv.style.display = 'block';
    if (statusText) statusText.textContent = 'Summoning device...';

    // Summon device and request VirtualParameters for admin credentials
    const result = await fetchAPI('/api/summon-device.php', {
        method: 'POST',
        body: JSON.stringify({ device_id: deviceId })
    });

    if (result && result.success) {
        // Single toast notification with longer duration (5 seconds)
        showToast('Device summon berhasil, mengambil credentials...', 'success', 5000);

        // Show countdown in status div only (not in toast)
        let countdown = 10;
        const countdownInterval = setInterval(() => {
            countdown--;
            if (countdown > 0) {
                if (statusText) statusText.textContent = `Menunggu credentials dari device (${countdown}s)...`;
            }
        }, 1000);

        setTimeout(() => {
            clearInterval(countdownInterval);
            if (statusText) statusText.textContent = 'Refreshing device data...';

            // Save scroll position before refresh
            const currentScrollPosition = window.pageYOffset || document.documentElement.scrollTop;
            console.log('[GET-CREDENTIALS] Saving scroll position:', currentScrollPosition);

            // Store in sessionStorage to persist across reload
            sessionStorage.setItem('credentialsScrollPosition', currentScrollPosition);

            // Reload device detail
            loadDeviceDetail();
        }, 10000);
    } else {
        // Hide status and show error (longer duration for error messages)
        if (statusDiv) statusDiv.style.display = 'none';
        if (btn) btn.disabled = false;
        showToast(result.message || 'Gagal summon device', 'danger', 5000);
    }
}

async function confirmSummon() {
    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('summonModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/summon-device.php', {
        method: 'POST',
        body: JSON.stringify({ device_id: deviceId })
    });

    hideLoading();

    if (result && result.success) {
        showToast('🚀 Device summon berhasil! Menunggu device response...', 'success');

        // Wait longer for device to respond and GenieACS to fetch all parameters
        // This is especially important for admin credentials (VirtualParameters)
        let countdown = 15;
        const countdownInterval = setInterval(() => {
            countdown--;
            if (countdown > 0) {
                showToast(`⏳ Auto-refresh dalam ${countdown} detik...`, 'info');
            }
        }, 1000);

        setTimeout(() => {
            clearInterval(countdownInterval);
            showToast('🔄 Refreshing device data...', 'info');
            loadDeviceDetail();
        }, 15000);
    } else {
        showToast(result.message || 'Gagal summon device', 'danger');
    }
}

function openEditWiFiModal(deviceId, currentSsid, currentPassword) {
    // Set form values
    document.getElementById('edit-device-id').value = deviceId;
    document.getElementById('edit-wifi-ssid').value = currentSsid;
    document.getElementById('edit-wifi-password').value = currentPassword;
    document.getElementById('edit-wlan-index').value = '1'; // Default to WLAN 1

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editWiFiModal'), {
        backdrop: false
    });
    modal.show();
}

function toggleEditPassword() {
    const passwordField = document.getElementById('edit-wifi-password');
    const icon = document.getElementById('edit-toggle-icon');

    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        passwordField.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

function togglePasswordField() {
    const securityMode = document.getElementById('edit-security-mode').value;
    const passwordFieldGroup = document.getElementById('password-field-group');
    const passwordField = document.getElementById('edit-wifi-password');

    if (securityMode === 'None') {
        // Hide password field for Open network
        passwordFieldGroup.style.display = 'none';
        passwordField.removeAttribute('required');
        passwordField.value = ''; // Clear password value
    } else {
        // Show password field for secured network
        passwordFieldGroup.style.display = 'block';
        passwordField.setAttribute('required', 'required');
    }
}

async function confirmUpdateWiFi() {
    const form = document.getElementById('editWiFiForm');

    // Validate form
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    // Get form values
    const deviceId = document.getElementById('edit-device-id').value;
    const wifiSsid = document.getElementById('edit-wifi-ssid').value.trim();
    const securityMode = document.getElementById('edit-security-mode').value;
    const wifiPassword = document.getElementById('edit-wifi-password').value;
    const wlanIndex = document.getElementById('edit-wlan-index').value;

    // Validate SSID length
    if (wifiSsid.length < 1 || wifiSsid.length > 32) {
        showToast('WiFi SSID harus antara 1-32 karakter', 'danger');
        return;
    }

    // Validate password length only if security mode is not Open
    if (securityMode !== 'None') {
        if (wifiPassword.length < 8 || wifiPassword.length > 63) {
            showToast('WiFi Password harus antara 8-63 karakter', 'danger');
            return;
        }
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('editWiFiModal'));
    modal.hide();

    // Show loading
    showLoading('Updating WiFi configuration...');

    try {
        const requestData = {
            device_id: deviceId,
            wifi_ssid: wifiSsid,
            security_mode: securityMode,
            wlan_index: parseInt(wlanIndex)
        };

        // Only include password if security mode is not Open
        if (securityMode !== 'None') {
            requestData.wifi_password = wifiPassword;
        }

        const result = await fetchAPI('/api/update-wifi-config.php', {
            method: 'POST',
            body: JSON.stringify(requestData)
        });

        hideLoading();

        if (result && result.success) {
            showToast(result.message || 'WiFi configuration updated successfully!', 'success');

            // Reload device detail after a short delay
            setTimeout(() => {
                loadDeviceDetail();
            }, 2000);
        } else {
            const errorMessage = result && result.message ? result.message : 'Failed to update WiFi configuration';
            showToast(errorMessage, 'danger');
            if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
                console.error('WiFi update failed:', result);
            }
        }
    } catch (error) {
        hideLoading();
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.error('WiFi update error:', error);
        }
        showToast('Connection error: ' + error.message, 'danger');
    }
}

// Global variable to store WAN data for delete confirmation
let currentWANDelete = null;

// WAN Modal Functions
function openEditWANModal(wanData) {
    // Populate form
    document.getElementById('edit-wan-device-id').value = deviceId;
    document.getElementById('edit-wan-connection-index').value = wanData.connection_index || '';
    document.getElementById('edit-wan-connection-type').value = wanData.type === 'PPPoE' ? 'ppp' : 'ip';
    document.getElementById('edit-wan-name').value = wanData.name || '';
    document.getElementById('edit-wan-enable').value = wanData.status === 'Connected' ? 'true' : 'false';

    // Show/hide PPPoE fields
    const isPPPoE = wanData.type === 'PPPoE';
    document.getElementById('edit-wan-username-group').style.display = isPPPoE ? 'block' : 'none';
    document.getElementById('edit-wan-password-group').style.display = isPPPoE ? 'block' : 'none';

    if (isPPPoE) {
        document.getElementById('edit-wan-username').value = wanData.username || '';
        document.getElementById('edit-wan-password').value = '';  // Don't prefill password
    }

    // NATEnabled may not be available in all WAN data
    if (wanData.nat_enabled !== undefined) {
        document.getElementById('edit-wan-nat').value = wanData.nat_enabled ? 'true' : 'false';
    }

    // Extract VLAN from name
    const vlanMatch = wanData.name.match(/VID[_-]?(\d+)/i);
    if (vlanMatch) {
        document.getElementById('edit-wan-vlan').value = vlanMatch[1];
    }

    // Check if TR069
    const isTR069 = (wanData.service_list && (wanData.service_list.toUpperCase().includes('TR069') || wanData.service_list.toUpperCase().includes('CWMP'))) ||
                    (wanData.name && (wanData.name.toUpperCase().includes('TR069') || wanData.name.toUpperCase().includes('CWMP')));

    document.getElementById('tr069-warning').style.display = isTR069 ? 'block' : 'none';

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editWANModal'), { backdrop: false });
    modal.show();
}

async function confirmUpdateWAN() {
    const connectionIndex = document.getElementById('edit-wan-connection-index').value;
    const connectionType = document.getElementById('edit-wan-connection-type').value;
    const enable = document.getElementById('edit-wan-enable').value === 'true';
    const username = document.getElementById('edit-wan-username').value.trim();
    const password = document.getElementById('edit-wan-password').value;
    const natEnabled = document.getElementById('edit-wan-nat').value === 'true';
    const vlanId = document.getElementById('edit-wan-vlan').value;

    const parameters = {
        Enable: enable,
        NATEnabled: natEnabled
    };

    if (connectionType === 'ppp' && username) {
        parameters.Username = username;
        if (password) {
            parameters.Password = password;
        }
    }

    if (vlanId) {
        parameters['X_CT-COM_VLANID'] = parseInt(vlanId);
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('editWANModal'));
    modal.hide();

    showLoading('Updating WAN configuration...');

    const result = await fetchAPI('/api/update-wan-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            connection_index: parseInt(connectionIndex),
            connection_type: connectionType,
            parameters: parameters
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'WAN configuration updated successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else {
        showToast(result.message || 'Failed to update WAN configuration', 'danger');
    }
}

function openAddWANModal() {
    // Reset form
    document.getElementById('addWANForm').reset();
    document.getElementById('add-wan-username-group').style.display = 'none';
    document.getElementById('add-wan-password-group').style.display = 'none';
    document.getElementById('add-wan-service-custom').style.display = 'none';

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('addWANModal'), { backdrop: false });
    modal.show();
}

function toggleAddWANFields() {
    const connectionType = document.getElementById('add-wan-type').value;
    const usernameGroup = document.getElementById('add-wan-username-group');
    const passwordGroup = document.getElementById('add-wan-password-group');

    if (connectionType === 'ppp') {
        usernameGroup.style.display = 'block';
        passwordGroup.style.display = 'block';
    } else {
        usernameGroup.style.display = 'none';
        passwordGroup.style.display = 'none';
    }
}

async function confirmAddWAN() {
    const form = document.getElementById('addWANForm');

    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const connectionIndex = parseInt(document.getElementById('add-wan-index').value);
    const connectionType = document.getElementById('add-wan-type').value;
    const connectionName = document.getElementById('add-wan-name').value.trim();
    const username = document.getElementById('add-wan-username').value.trim();
    const password = document.getElementById('add-wan-password').value;
    const serviceList = document.getElementById('add-wan-service').value;
    const vlanId = parseInt(document.getElementById('add-wan-vlan').value);
    const natEnabled = document.getElementById('add-wan-nat').value === 'true';

    // Build parameters
    const parameters = {
        Enable: true,
        ConnectionType: connectionType === 'ppp' ? 'IP_Routed' : 'IP_Routed',
        NATEnabled: natEnabled,
        'X_CT-COM_VLANID': vlanId,
        'X_CT-COM_ServiceList': serviceList === 'CUSTOM' ? document.getElementById('add-wan-service-custom').value : serviceList
    };

    if (connectionType === 'ppp') {
        if (!username || !password) {
            showToast('Username and password are required for PPPoE connections', 'danger');
            return;
        }
        parameters.Username = username;
        parameters.Password = password;
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('addWANModal'));
    modal.hide();

    showLoading('Creating WAN connection...');

    const result = await fetchAPI('/api/add-wan-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            connection_index: connectionIndex,
            connection_type: connectionType,
            name: connectionName,
            parameters: parameters
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'WAN connection created successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else {
        showToast(result.message || 'Failed to create WAN connection', 'danger');
    }
}

function openDeleteWANModal(wanData) {
    currentWANDelete = wanData;

    // Check if TR069
    const isTR069 = (wanData.service_list && (wanData.service_list.toUpperCase().includes('TR069') || wanData.service_list.toUpperCase().includes('CWMP'))) ||
                    (wanData.name && (wanData.name.toUpperCase().includes('TR069') || wanData.name.toUpperCase().includes('CWMP')));

    if (isTR069) {
        document.getElementById('delete-wan-normal-warning').style.display = 'none';
        document.getElementById('delete-wan-tr069-warning').style.display = 'block';
        document.getElementById('delete-wan-tr069-name').textContent = wanData.name;
        document.getElementById('delete-wan-tr069-service').textContent = wanData.service_list || 'N/A';
        document.getElementById('delete-wan-tr069-confirm').checked = false;
    } else {
        document.getElementById('delete-wan-normal-warning').style.display = 'block';
        document.getElementById('delete-wan-tr069-warning').style.display = 'none';
        document.getElementById('delete-wan-name').textContent = wanData.name;
        document.getElementById('delete-wan-service').textContent = wanData.service_list || 'N/A';
    }

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('deleteWANModal'), { backdrop: false });
    modal.show();
}

async function confirmDeleteWAN() {
    if (!currentWANDelete) return;

    // Check if TR069 and needs confirmation
    const isTR069 = (currentWANDelete.service_list && (currentWANDelete.service_list.toUpperCase().includes('TR069') || currentWANDelete.service_list.toUpperCase().includes('CWMP'))) ||
                    (currentWANDelete.name && (currentWANDelete.name.toUpperCase().includes('TR069') || currentWANDelete.name.toUpperCase().includes('CWMP')));

    if (isTR069 && !document.getElementById('delete-wan-tr069-confirm').checked) {
        showToast('Please confirm that you understand the risks before deleting TR069 connection', 'warning');
        return;
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteWANModal'));
    modal.hide();

    showLoading('Deleting WAN connection...');

    const result = await fetchAPI('/api/delete-wan-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            connection_index: currentWANDelete.connection_index,
            connection_type: currentWANDelete.type === 'PPPoE' ? 'ppp' : 'ip',
            connection_name: currentWANDelete.name,
            service_list: currentWANDelete.service_list || '',
            confirm_tr069_delete: isTR069
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'WAN connection deleted successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else if (result && result.requires_confirmation) {
        showToast('TR069 connection deletion blocked - please confirm deletion', 'warning');
    } else {
        showToast(result.message || 'Failed to delete WAN connection', 'danger');
    }

    currentWANDelete = null;
}

// DHCP Modal Functions
function openEditDHCPModal(dhcpData) {
    // Populate form
    const dhcpEnabled = dhcpData.enabled === true || dhcpData.enabled === 'true';
    document.getElementById('edit-dhcp-enable').value = dhcpEnabled ? 'true' : 'false';
    document.getElementById('edit-dhcp-min-address').value = dhcpData.min_address || '';
    document.getElementById('edit-dhcp-max-address').value = dhcpData.max_address || '';
    document.getElementById('edit-dhcp-subnet-mask').value = dhcpData.subnet_mask || '';
    document.getElementById('edit-dhcp-gateway').value = dhcpData.gateway || '';
    document.getElementById('edit-dhcp-dns').value = dhcpData.dns_servers || '';
    document.getElementById('edit-dhcp-lease-time').value = dhcpData.lease_time || '';

    // Show/hide fields
    toggleDHCPFields();

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editDHCPModal'), { backdrop: false });
    modal.show();
}

function toggleDHCPFields() {
    const dhcpEnabled = document.getElementById('edit-dhcp-enable').value === 'true';
    document.getElementById('dhcp-config-fields').style.display = dhcpEnabled ? 'block' : 'none';
}

async function confirmUpdateDHCP() {
    const dhcpEnabled = document.getElementById('edit-dhcp-enable').value === 'true';

    const parameters = {
        DHCPServerEnable: dhcpEnabled
    };

    if (dhcpEnabled) {
        parameters.MinAddress = document.getElementById('edit-dhcp-min-address').value.trim();
        parameters.MaxAddress = document.getElementById('edit-dhcp-max-address').value.trim();
        parameters.SubnetMask = document.getElementById('edit-dhcp-subnet-mask').value.trim();
        parameters.IPRouters = document.getElementById('edit-dhcp-gateway').value.trim();
        parameters.DNSServers = document.getElementById('edit-dhcp-dns').value.trim();

        const leaseTime = document.getElementById('edit-dhcp-lease-time').value.trim();
        if (leaseTime) {
            parameters.DHCPLeaseTime = parseInt(leaseTime);
        }
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('editDHCPModal'));
    modal.hide();

    showLoading('Updating DHCP configuration...');

    const result = await fetchAPI('/api/update-dhcp-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            parameters: parameters
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'DHCP configuration updated successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else {
        showToast(result.message || 'Failed to update DHCP configuration', 'danger');
    }
}

// Tags Management Functions
function updateTagsBadge(tags) {
    const badgeElement = document.getElementById('device-tags-badge');
    if (tags && tags.length > 0) {
        const tagBadges = tags.map(tag => `<span class="badge bg-info ms-1">${tag}</span>`).join('');
        badgeElement.innerHTML = tagBadges;
    } else {
        badgeElement.innerHTML = '';
    }
}

function showAddTagModal() {
    const modal = new bootstrap.Modal(document.getElementById('addTagModal'));
    document.getElementById('addTagName').value = '';
    modal.show();
}

async function addTagToDevice() {
    const tagName = document.getElementById('addTagName').value.trim();

    if (!tagName) {
        showToast('Please enter a tag name', 'warning');
        return;
    }

    const result = await fetchAPI('/api/bulk-tag.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'add',
            device_ids: [deviceId],
            tag: tagName
        })
    });

    if (result && result.success) {
        showToast(`Tag "${tagName}" added successfully`, 'success');
        bootstrap.Modal.getInstance(document.getElementById('addTagModal')).hide();
        loadDeviceDetail(); // Reload to show new tag
    } else {
        showToast(result.message || 'Failed to add tag', 'danger');
    }
}

function showRemoveTagModal() {
    fetchAPI('/api/get-device-detail.php?device_id=' + encodeURIComponent(deviceId))
        .then(result => {
            if (result && result.success && result.device.tags && result.device.tags.length > 0) {
                const tagsList = document.getElementById('deviceTagsList');
                tagsList.innerHTML = result.device.tags.map(tag =>
                    `<button class="btn btn-sm btn-outline-warning me-2 mb-2" onclick="removeTagFromDevice('${tag}')">
                        <i class="bi bi-x-circle"></i> ${tag}
                    </button>`
                ).join('');

                document.getElementById('removeTagContent').style.display = 'block';
                document.getElementById('noTagsMessage').style.display = 'none';
            } else {
                document.getElementById('removeTagContent').style.display = 'none';
                document.getElementById('noTagsMessage').style.display = 'block';
            }

            const modal = new bootstrap.Modal(document.getElementById('removeTagModal'));
            modal.show();
        });
}

async function removeTagFromDevice(tagName) {
    if (!confirm(`Remove tag "${tagName}"?`)) {
        return;
    }

    const result = await fetchAPI('/api/bulk-tag.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'remove',
            device_ids: [deviceId],
            tag: tagName
        })
    });

    if (result && result.success) {
        showToast(`Tag "${tagName}" removed successfully`, 'success');
        bootstrap.Modal.getInstance(document.getElementById('removeTagModal')).hide();
        loadDeviceDetail(); // Reload to update tags
    } else {
        showToast(result.message || 'Failed to remove tag', 'danger');
    }
}

// Hotspot Traffic Management
let hotspotTrafficInterval = null;
let previousBytesData = {}; // Store previous bytes for rate calculation
let hotspotMonitoringActive = false; // Track if monitoring is active
let hotspotFetchInProgress = false; // Prevent concurrent requests
let hotspotAbortController = null; // AbortController to cancel pending requests

async function fetchHotspotTraffic() {
    // Prevent concurrent requests - check FIRST before any cancellation
    if (hotspotFetchInProgress) {
        console.debug('[HOTSPOT] Fetch already in progress, skipping...');
        return;
    }

    // Get all MAC addresses from connected devices table
    const macElements = document.querySelectorAll('.hotspot-user[data-mac]');
    if (macElements.length === 0) {
        console.debug('[HOTSPOT] No MAC addresses found in table');
        return;
    }

    const macAddresses = Array.from(macElements).map(el => el.dataset.mac);
    console.log(`[HOTSPOT] Starting fetch for ${macAddresses.length} devices`);

    // Mark as in progress BEFORE creating AbortController
    hotspotFetchInProgress = true;

    // Create new AbortController for this request only
    hotspotAbortController = new AbortController();

    // Update status to show fetching (but keep it subtle)
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) {
        statusEl.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> <small class="text-muted">Updating...</small>';
    }

    try {
        const result = await fetchAPI('/api/get-hotspot-traffic.php', {
            method: 'POST',
            body: JSON.stringify({ mac_addresses: macAddresses }),
            timeout: 15000, // 15 second timeout (MikroTik may be slow)
            signal: hotspotAbortController.signal
        });

        console.log('[HOTSPOT] Fetch completed successfully', {
            from_cache: result.from_cache,
            cache_age: result.cache_age,
            total_matched: result.total_matched
        });

        if (result && result.success && result.data) {
            updateHotspotTrafficDisplay(result.data, result.timestamp);

            // Update status with last update time and cache info
            if (statusEl) {
                const now = new Date();
                const timeStr = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                const cacheInfo = result.from_cache ? ` <small class="text-info">[cached ${result.cache_age}s]</small>` : '';
                statusEl.innerHTML = `<span class="text-success">✓ ${timeStr}</span>${cacheInfo}`;
            }
        } else if (result && result.error === 'timeout') {
            // Request timeout (15s)
            console.warn('[HOTSPOT] Request timeout after 15s');
            const errorMsg = 'Request timeout. Retrying in 5s...';
            updateHotspotTrafficError(errorMsg);

            if (statusEl) {
                statusEl.innerHTML = '<i class="bi bi-clock text-warning"></i> <small>Timeout - retrying...</small>';
            }
        } else {
            // MikroTik offline or other error
            console.warn('[HOTSPOT] Error response:', result);
            const errorMsg = (result && result.message) ? result.message : 'MikroTik Offline';
            updateHotspotTrafficError(errorMsg);

            if (statusEl) {
                statusEl.innerHTML = '<i class="bi bi-exclamation-triangle text-warning"></i> ' + errorMsg;
            }
        }
    } catch (error) {
        // Check if it was aborted
        if (error.name === 'AbortError') {
            console.warn('[HOTSPOT] Request was aborted (likely due to page refresh)');
        } else {
            console.error('[HOTSPOT] Fetch failed:', error.message);
        }

        // Don't show error to user on every fetch, just keep last known data
        // Only update status, not the actual traffic data
        if (statusEl) {
            statusEl.innerHTML = '<i class="bi bi-x-circle text-danger"></i> <small>Connection error - showing last known data</small>';
        }
    } finally {
        // Always reset the flag when done (CRITICAL for preventing stuck state)
        console.debug('[HOTSPOT] Resetting fetch flag');
        hotspotFetchInProgress = false;
        hotspotAbortController = null;
    }
}

function updateHotspotTrafficDisplay(data, timestamp) {
    Object.keys(data).forEach(mac => {
        const userInfo = data[mac];
        const userCell = document.querySelector(`.hotspot-user[data-mac="${mac}"]`);
        const trafficCell = document.querySelector(`.hotspot-traffic[data-mac="${mac}"]`);

        if (!userCell || !trafficCell) return;

        // Update username
        if (userInfo.found) {
            userCell.innerHTML = `<strong>${userInfo.username}</strong>`;

            // Calculate RX/TX rate from bytes difference
            const currentBytesIn = userInfo.bytes_in || 0;
            const currentBytesOut = userInfo.bytes_out || 0;

            let rxRate = 0;
            let txRate = 0;

            if (previousBytesData[mac]) {
                const timeDiff = timestamp - previousBytesData[mac].timestamp;
                if (timeDiff > 0) {
                    const bytesDiffIn = Math.max(0, currentBytesIn - previousBytesData[mac].bytes_in);
                    const bytesDiffOut = Math.max(0, currentBytesOut - previousBytesData[mac].bytes_out);

                    // Calculate rate in bytes per second, then convert to bits per second
                    const rawRxRate = (bytesDiffIn / timeDiff) * 8; // bits per second
                    const rawTxRate = (bytesDiffOut / timeDiff) * 8; // bits per second

                    // Apply simple smoothing: 30% new value, 70% old value (reduces jitter)
                    const smoothingFactor = 0.3;
                    if (previousBytesData[mac].rxRate !== undefined) {
                        rxRate = (smoothingFactor * rawRxRate) + ((1 - smoothingFactor) * previousBytesData[mac].rxRate);
                        txRate = (smoothingFactor * rawTxRate) + ((1 - smoothingFactor) * previousBytesData[mac].txRate);
                    } else {
                        rxRate = rawRxRate;
                        txRate = rawTxRate;
                    }
                } else {
                    // Time diff is 0, use previous rate if available
                    rxRate = previousBytesData[mac].rxRate || 0;
                    txRate = previousBytesData[mac].txRate || 0;
                }
            }

            // Store current bytes and calculated rate for next iteration
            previousBytesData[mac] = {
                bytes_in: currentBytesIn,
                bytes_out: currentBytesOut,
                timestamp: timestamp,
                rxRate: rxRate,
                txRate: txRate
            };

            // Format and display traffic
            const rxFormatted = formatBitrate(rxRate);
            const txFormatted = formatBitrate(txRate);

            // Debug log for first device only (to avoid console spam)
            if (mac === Object.keys(data)[0]) {
                console.debug('[HOTSPOT] Traffic update:', {
                    mac: mac,
                    username: userInfo.username,
                    rx: rxFormatted,
                    tx: txFormatted,
                    bytes_in: currentBytesIn,
                    bytes_out: currentBytesOut
                });
            }

            trafficCell.innerHTML = `<span class="text-success">↓ ${rxFormatted}</span> / <span class="text-danger">↑ ${txFormatted}</span>`;
        } else {
            userCell.innerHTML = '<span class="text-muted">N/A</span>';
            trafficCell.innerHTML = '<span class="text-muted">N/A</span>';
        }

        // Also save to savedHotspotData for persistence across refreshes
        if (!savedHotspotData[mac]) savedHotspotData[mac] = {};
        savedHotspotData[mac].userHtml = userCell.innerHTML;
        savedHotspotData[mac].trafficHtml = trafficCell.innerHTML;
    });
}

function updateHotspotTrafficError(message) {
    // Only show error in status indicator, keep existing data in cells (don't overwrite)
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) {
        statusEl.innerHTML = `<span class="text-danger">⚠ ${message}</span>`;
    }

    // DON'T clear existing data - let users see last known good data
    // This prevents the annoying "all N/A" experience when timeout occurs
    console.warn('[HOTSPOT] Error:', message, '- Keeping last known data visible');
}

function formatBitrate(bps) {
    if (bps === 0 || isNaN(bps)) return '0 bps';

    const units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
    let value = Math.abs(bps);
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return value.toFixed(2) + ' ' + units[unitIndex];
}

function startHotspotTrafficMonitoring() {
    // Check if table exists (tab must be active)
    const table = document.getElementById('connected-devices-table');
    if (!table) {
        console.debug('[HOTSPOT] Table not found, skipping monitoring start');
        return;
    }

    // Update status
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) statusEl.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Starting...';

    // Clear existing interval if any
    if (hotspotTrafficInterval) {
        clearInterval(hotspotTrafficInterval);
    }

    // Set monitoring as active
    hotspotMonitoringActive = true;

    console.log('[HOTSPOT] Monitoring started (auto-mode)');

    // Fetch immediately
    fetchHotspotTraffic();

    // Then fetch every 5 seconds - balanced between smooth updates and stability
    // 5s interval with 15s timeout allows enough time for slow MikroTik response
    hotspotTrafficInterval = setInterval(fetchHotspotTraffic, 5000);
}

function stopHotspotTrafficMonitoring() {
    console.log('[HOTSPOT] Stopping monitoring...');

    // Clear status
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) statusEl.innerHTML = '<i class="bi bi-router text-muted"></i> <span class="text-muted">Monitoring stopped</span>';

    // Clear interval first
    if (hotspotTrafficInterval) {
        clearInterval(hotspotTrafficInterval);
        hotspotTrafficInterval = null;
    }

    // Abort any pending request
    if (hotspotAbortController) {
        console.log('[HOTSPOT] Aborting pending request...');
        hotspotAbortController.abort();
        hotspotAbortController = null;
    }

    // Reset flags
    hotspotFetchInProgress = false;
    previousBytesData = {};

    // Set monitoring as inactive
    hotspotMonitoringActive = false;

    console.log('[HOTSPOT] Monitoring stopped successfully');
}


/* =========================================================
   FIBERHOME TL1 - OPTICAL INFORMATION
   ========================================================= */

function opticalLoadingHtml() {
    return '<span class="text-muted">Consultando OLT...</span>';
}

function opticalUnavailableHtml(message = 'Não disponível') {
    return '<span class="text-muted">' + escapeOpticalHtml(message) + '</span>';
}

function formatOpticalValue(value, unit, status) {
    const numericValue = Number(value);

    if (!Number.isFinite(numericValue)) {
        return opticalUnavailableHtml();
    }

    const formatted = numericValue.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    let statusHtml = '';

    if (status) {
        const statusText = String(status).trim();

        if (statusText.toLowerCase() === 'normal') {
            statusHtml = ' <span class="badge bg-success ms-2">Normal</span>';
        } else {
            statusHtml =
                ' <span class="badge bg-warning text-dark ms-2">' +
                escapeOpticalHtml(statusText) +
                '</span>';
        }
    }

    return (
        '<strong>' +
        formatted +
        ' ' +
        escapeOpticalHtml(unit) +
        '</strong>' +
        statusHtml
    );
}

function renderOpticalCachedValue(valueKey, unit, statusKey) {
    if (cachedOpticalData && cachedOpticalData.device_id === deviceId) {
        if (cachedOpticalData.error) {
            return opticalUnavailableHtml();
        }

        const optical = cachedOpticalData.optical || {};
        return formatOpticalValue(optical[valueKey], unit, optical[statusKey]);
    }

    return opticalLoadingHtml();
}

function renderOpticalCachedPon() {
    if (cachedOpticalData && cachedOpticalData.device_id === deviceId) {
        if (cachedOpticalData.error) {
            return opticalUnavailableHtml();
        }

        if (cachedOpticalData.pon_id) {
            return '<strong>' + escapeOpticalHtml(cachedOpticalData.pon_id) + '</strong>';
        }

        return opticalUnavailableHtml('Não identificado');
    }

    return opticalLoadingHtml();
}

function formatOpticalDate(value) {
    if (!value) {
        return 'Não disponível';
    }

    const text = String(value).trim();
    const match = text.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/);

    if (!match) {
        return text;
    }

    return `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}:${match[6]}`;
}

function renderOpticalLastUpdate() {
    if (cachedOpticalData && cachedOpticalData.device_id === deviceId) {
        if (cachedOpticalData.error) {
            return opticalUnavailableHtml();
        }

        const optical = cachedOpticalData.optical || {};

        if (optical.last_update) {
            return '<strong>' + escapeOpticalHtml(formatOpticalDate(optical.last_update)) + '</strong>';
        }

        return opticalUnavailableHtml();
    }

    return opticalLoadingHtml();
}

function renderOpticalSource() {
    if (cachedOpticalData && cachedOpticalData.device_id === deviceId) {
        if (cachedOpticalData.error) {
            return opticalUnavailableHtml();
        }

        const source = cachedOpticalData.source || 'IXC';
        return '<span class="badge bg-info">' + escapeOpticalHtml(source) + '</span>';
    }

    return opticalLoadingHtml();
}

function updateOpticalDomFromCache() {
    // IMPORTANTE: busca os elementos novamente depois da resposta.
    // O auto-refresh pode ter recriado todo o HTML enquanto a API estava consultando a OLT.
    const rxEl = document.getElementById('optical-rx-power');
    const txEl = document.getElementById('optical-tx-power');
    const tempEl = document.getElementById('optical-temperature');
    const voltageEl = document.getElementById('optical-voltage');
    const ponEl = document.getElementById('optical-pon-id');
    const lastUpdateEl = document.getElementById('optical-last-update');
    const sourceEl = document.getElementById('optical-source');

    if (rxEl) rxEl.innerHTML = renderOpticalCachedValue('rx_power', 'dBm', 'rx_status');
    if (txEl) txEl.innerHTML = renderOpticalCachedValue('tx_power', 'dBm', 'tx_status');
    if (tempEl) tempEl.innerHTML = renderOpticalCachedValue('temperature', '°C', 'temperature_status');
    if (voltageEl) voltageEl.innerHTML = renderOpticalCachedValue('voltage', 'V', 'voltage_status');
    if (ponEl) ponEl.innerHTML = renderOpticalCachedPon();
    if (lastUpdateEl) lastUpdateEl.innerHTML = renderOpticalLastUpdate();
    if (sourceEl) sourceEl.innerHTML = renderOpticalSource();
    updateIxcOnuSummary();
}

function updateIxcOnuSummary() {
    const container = document.getElementById('ixc-onu-summary');
    if (!container) return;
    if (!cachedOpticalData || cachedOpticalData.error) {
        container.innerHTML = '<div class="acs-info-row"><span><i class="bi bi-database"></i> Cadastro oficial</span><strong>IXC indisponível</strong></div>';
        return;
    }
    const value = (v) => escapeOpticalHtml(v == null || v === '' ? 'Não informado' : v);
    const pon = [cachedOpticalData.slot, cachedOpticalData.pon, cachedOpticalData.onu_number]
        .filter(v => v !== null && v !== undefined && v !== '')
        .join(' / ') || cachedOpticalData.pon_id || 'Não informado';
    container.innerHTML = `
        <div class="acs-info-row"><span><i class="bi bi-database-check"></i> Cliente (IXC)</span><strong>${value(cachedOpticalData.nome)}</strong></div>
        <div class="acs-info-row"><span><i class="bi bi-person-vcard"></i> Login / contrato</span><strong>${value(cachedOpticalData.id_login)} / ${value(cachedOpticalData.id_contrato)}</strong></div>
        <div class="acs-info-row"><span><i class="bi bi-diagram-2"></i> Slot / PON / ONU</span><strong>${value(pon)}</strong></div>`;
}

async function loadFiberhomeOptical(deviceIdToLoad, forceRefresh = false) {
    if (!deviceIdToLoad) {
        return;
    }

    // Se já existe uma consulta para a mesma ONU, não abre outra.
    if (opticalLoading && opticalLoadedForDevice === deviceIdToLoad) {
        return;
    }

    // Se já temos os dados da mesma ONU e não foi solicitado refresh manual,
    // apenas redesenha usando o cache local.
    if (
        !forceRefresh &&
        cachedOpticalData &&
        cachedOpticalData.device_id === deviceIdToLoad &&
        !cachedOpticalData.error
    ) {
        updateOpticalDomFromCache();
        return;
    }

    opticalLoading = true;
    opticalLoadedForDevice = deviceIdToLoad;

    const currentRequest = ++opticalRequestCounter;

    // Mostra "Consultando OLT..." apenas quando realmente iniciamos nova consulta.
    const loadingIds = [
        'optical-rx-power',
        'optical-tx-power',
        'optical-temperature',
        'optical-voltage',
        'optical-pon-id',
        'optical-last-update',
        'optical-source'
    ];

    loadingIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = opticalLoadingHtml();
    });

    try {
        const response = await fetch(
            '/api/get-onu-optical.php?device_id=' + encodeURIComponent(deviceIdToLoad),
            {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                cache: 'no-store'
            }
        );

        const raw = await response.text();
        let data;

        try {
            data = JSON.parse(raw);
        } catch (jsonError) {
            throw new Error('Resposta inválida da API óptica.');
        }

        if (currentRequest !== opticalRequestCounter) {
            return;
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Falha ao consultar dados ópticos.');
        }

        cachedOpticalData = {
            device_id: deviceIdToLoad,
            source: data.source || 'IXC',
            nome: data.nome || null,
            id_login: data.id_login ?? null,
            id_contrato: data.id_contrato ?? null,
            pon_id: data.pon_id || null,
            onu_number: data.onu_number ?? null,
            olt_id: data.olt_id || null,
            slot: data.slot ?? null,
            pon: data.pon ?? null,
            optical: data.optical || {},
            error: null,
            loaded_at: Date.now()
        };

        opticalLoadedForDevice = deviceIdToLoad;

        // Busca os elementos atuais do DOM e atualiza a tela atual,
        // mesmo que o auto-refresh tenha acontecido durante a consulta.
        updateOpticalDomFromCache();

        console.log('[IXC] Dados ópticos carregados:', data);

    } catch (error) {
        console.error('[IXC] Erro:', error);

        if (currentRequest !== opticalRequestCounter) {
            return;
        }

        cachedOpticalData = {
            device_id: deviceIdToLoad,
            source: 'IXC',
            pon_id: null,
            onu_number: null,
            olt_id: null,
            optical: {},
            error: error && error.message ? error.message : 'Erro TL1',
            loaded_at: Date.now()
        };

        opticalLoadedForDevice = deviceIdToLoad;
        updateOpticalDomFromCache();

    } finally {
        if (currentRequest === opticalRequestCounter) {
            opticalLoading = false;
        }
    }
}

function escapeOpticalHtml(value) {
    return String(value == null ? '' : value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

document.addEventListener('DOMContentLoaded', function() {
    loadDeviceDetail(); // Initial load (manual, scroll to top)
    // Auto refresh every 30 seconds (preserve scroll position)
    setInterval(() => loadDeviceDetail(true), 30000);
    setInterval(updateRadiusBandwidthSample, 1000);

    // Auto-start/stop hotspot monitoring based on Connected Devices tab visibility
    const allTabs = document.querySelectorAll('[data-bs-toggle="tab"]');
    allTabs.forEach(tab => {
        tab.addEventListener('shown.bs.tab', function(event) {
            if (tab.id === 'devices-tab') {
                // Start monitoring when Connected Devices tab is opened
                console.log('[TAB] Connected Devices tab opened - starting hotspot monitoring...');
                // Small delay to ensure table is rendered
                setTimeout(() => {
                    startHotspotTrafficMonitoring();
                }, 200);
            } else {
                // Stop monitoring when switching to other tabs
                if (hotspotTrafficInterval) {
                    console.log('[TAB] Switched away from Connected Devices - stopping hotspot monitoring...');
                    stopHotspotTrafficMonitoring();
                }
            }
        });
    });
});


function renderFirmwareTab(device) {
    const manufacturer = device.manufacturer || 'Não disponível';
    const model = device.product_class || 'Não disponível';
    const currentVersion = device.software_version || 'Não disponível';
    const hardwareVersion = device.hardware_version || 'Não disponível';

    return `
        <div class="acs-firmware-page">
            <div class="acs-firmware-header">
                <div>
                    <span class="acs-kicker"><i class="bi bi-cloud-arrow-up"></i> MANUTENÇÃO</span>
                    <h4>Atualização de Firmware</h4>
                    <p>Gerencie versões de software do equipamento através do ACS.</p>
                </div>
                <span class="acs-firmware-safe"><i class="bi bi-shield-check"></i> Atualização controlada</span>
            </div>

            <div class="acs-firmware-grid">
                <section class="acs-firmware-card">
                    <div class="acs-firmware-card-title">
                        <div class="acs-firmware-icon"><i class="bi bi-router"></i></div>
                        <div>
                            <span>Equipamento</span>
                            <h5>${model}</h5>
                        </div>
                    </div>
                    <div class="acs-firmware-info">
                        <div><span>Fabricante</span><strong>${manufacturer}</strong></div>
                        <div><span>Hardware</span><strong>${hardwareVersion}</strong></div>
                        <div><span>Firmware atual</span><strong class="version">${currentVersion}</strong></div>
                    </div>
                </section>

                <section class="acs-firmware-card acs-firmware-update">
                    <div class="acs-firmware-card-title">
                        <div class="acs-firmware-icon"><i class="bi bi-file-earmark-arrow-up"></i></div>
                        <div>
                            <span>Nova versão</span>
                            <h5>Enviar firmware</h5>
                        </div>
                    </div>

                    <div class="acs-firmware-dropzone">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <strong>Selecione o arquivo de firmware</strong>
                        <span>O envio e a instalação serão habilitados na próxima etapa.</span>
                        <button type="button" disabled><i class="bi bi-folder2-open"></i> Selecionar arquivo</button>
                    </div>
                </section>

                <section class="acs-firmware-card acs-firmware-wide">
                    <div class="acs-firmware-card-title">
                        <div class="acs-firmware-icon"><i class="bi bi-activity"></i></div>
                        <div>
                            <span>Status</span>
                            <h5>Processo de atualização</h5>
                        </div>
                    </div>
                    <div class="acs-firmware-status">
                        <div class="acs-firmware-step active"><b>1</b><span><strong>Equipamento identificado</strong><small>Modelo e versão atual coletados pelo ACS.</small></span></div>
                        <div class="acs-firmware-line"></div>
                        <div class="acs-firmware-step"><b>2</b><span><strong>Arquivo validado</strong><small>Aguardando seleção de firmware compatível.</small></span></div>
                        <div class="acs-firmware-line"></div>
                        <div class="acs-firmware-step"><b>3</b><span><strong>Instalação</strong><small>A atualização será enviada ao equipamento via TR-069.</small></span></div>
                    </div>
                    <div class="acs-firmware-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span><strong>Proteção ativa.</strong> O botão de instalação permanecerá bloqueado até implementarmos a validação do arquivo, modelo e versão. Nenhum firmware será enviado nesta etapa.</span>
                    </div>
                </section>
            </div>
        </div>
    `;
}


function openWebManagement() {
    if (!currentDeviceData) {
        alert('Os dados do equipamento ainda não foram carregados.');
        return;
    }
    const raw = currentDeviceData.ip_tr069 || currentDeviceData.ip_address || '';
    let host = '';
    try {
        host = /^https?:\/\//i.test(raw) ? new URL(raw).hostname : extractIP(raw);
    } catch (e) {
        host = extractIP(raw);
    }
    if (!host) {
        alert('Não foi possível identificar o IP de gerenciamento deste equipamento.');
        return;
    }
    window.open('http://' + host, '_blank', 'noopener,noreferrer');
}


let bandwidthSamples = [];

function getPrimaryWAN(device) {
    const list = Array.isArray(device?.wan_details) ? device.wan_details : [];
    return list.find(w => String(w.status || '').toLowerCase() === 'connected') || list[0] || null;
}

function getTrafficCounters(device, wan) {
    const wanRx = toCounter(wan?.bytes_received);
    const wanTx = toCounter(wan?.bytes_sent);
    if (wanRx !== null && wanTx !== null && (wanRx > 0 || wanTx > 0)) {
        return { rx: wanRx, tx: wanTx, source: 'contador WAN' };
    }
    const lan = device?.lan_traffic;
    const lanRx = toCounter(lan?.bytes_received);
    const lanTx = toCounter(lan?.bytes_sent);
    if (lanRx !== null && lanTx !== null) {
        return { rx: lanRx, tx: lanTx, source: lan?.source || 'Portas LAN' };
    }
    return { rx: wanRx, tx: wanTx, source: 'contador WAN' };
}

function toCounter(value) {
    if (value === null || value === undefined || value === '' || value === 'N/A') return null;
    const n = Number(value);
    return Number.isFinite(n) && n >= 0 ? n : null;
}

function formatTrafficBytes(bytes) {
    let value = toCounter(bytes);
    if (value === null) return 'Sem leitura';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (value >= 1024 && i < units.length - 1) {
        value /= 1024;
        i++;
    }
    return (i === 0 ? value.toFixed(0) : value.toFixed(value >= 100 ? 0 : value >= 10 ? 1 : 2)) + ' ' + units[i];
}

function formatCounter(value) {
    const counter = toCounter(value);
    return counter === null ? 'Sem leitura' : counter.toLocaleString('pt-BR');
}

function formatUptimeValue(value) {
    const seconds = Number(value);
    if (!Number.isFinite(seconds)) return value || 'N/A';
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    return [d ? d + 'd' : '', h ? h + 'h' : '', m + 'min'].filter(Boolean).join(' ');
}

function renderModernBandwidthChart(samples) {
    if (!samples.length) return '<div class="acs-chart-wait">Aguardando segunda leitura do IXC/RADIUS.</div>';
    const max = Math.max(1, ...samples.flatMap(s => [s.rxMbps, s.txMbps]));
    const points = (field) => samples.map((s, i) => {
        const x = samples.length === 1 ? 0 : (i / (samples.length - 1)) * 100;
        const y = 100 - (s[field] / max) * 88 - 4;
        return `${x.toFixed(2)},${y.toFixed(2)}`;
    }).join(' ');
    const labels = samples.filter((_, i) => i === 0 || i === samples.length - 1).map((s, i) => `<span class="${i ? 'end' : ''}">${new Date(s.time).toLocaleTimeString('pt-BR')}</span>`).join('');
    return `<div class="acs-modern-chart"><div class="acs-chart-scale"><b>${max.toFixed(1)} Mbps</b><b>${(max / 2).toFixed(1)} Mbps</b><b>0 Mbps</b></div><div class="acs-chart-plot"><svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-label="Tráfego em tempo real"><defs><linearGradient id="rxArea" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#64d9ff" stop-opacity=".32"/><stop offset="1" stop-color="#64d9ff" stop-opacity="0"/></linearGradient><linearGradient id="txArea" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#55dda6" stop-opacity=".30"/><stop offset="1" stop-color="#55dda6" stop-opacity="0"/></linearGradient></defs><path class="grid" d="M0 4H100M0 48H100M0 92H100"/><polygon class="area rx" points="0,100 ${points('rxMbps')} 100,100"/><polygon class="area tx" points="0,100 ${points('txMbps')} 100,100"/><polyline class="line rx" points="${points('rxMbps')}"/><polyline class="line tx" points="${points('txMbps')}"/></svg><div class="acs-chart-times">${labels}</div></div></div><div class="acs-chart-legend"><span><i class="rx"></i>Download</span><span><i class="tx"></i>Upload</span><span>Últimos ${samples.length}s</span></div>`;
}

function renderMonitoringTab(device) {
    const wan = getPrimaryWAN(device);
    if (!wan) {
        return '<div class="acs-monitor-empty"><i class="bi bi-graph-up"></i><h5>Monitoramento indisponível</h5><p>Nenhuma conexão WAN foi identificada neste equipamento.</p></div>';
    }
    const traffic = getTrafficCounters(device, wan);
    const rx = traffic.rx;
    const tx = traffic.tx;
    const connected = String(wan.status || '').toLowerCase() === 'connected';
    return `
        <div class="acs-monitor-shell">
            <div class="acs-monitor-head">
                <div><span class="acs-kicker"><i class="bi bi-activity"></i> TR-069</span><h4>Monitoramento da conexão</h4><p>Banda calculada entre as leituras recebidas do equipamento.</p></div>
                <span class="acs-mini-badge ${connected ? 'success' : ''}">${wan.status || 'Unknown'}</span>
            </div>
            <div class="acs-live-grid">
                <div class="acs-live-card download"><span><i class="bi bi-arrow-down-circle"></i> Download em uso</span><strong id="live-rx-mbps">--</strong><small>Mbps</small></div>
                <div class="acs-live-card upload"><span><i class="bi bi-arrow-up-circle"></i> Upload em uso</span><strong id="live-tx-mbps">--</strong><small>Mbps</small></div>
                <div class="acs-live-card"><span><i class="bi bi-database-down"></i> Recebido</span><strong id="live-rx-total">${formatTrafficBytes(rx)}</strong><small id="live-traffic-source">${traffic.source}</small></div>
                <div class="acs-live-card"><span><i class="bi bi-database-up"></i> Enviado</span><strong id="live-tx-total">${formatTrafficBytes(tx)}</strong><small>contador da sessão</small></div>
            </div>
            <div class="acs-bandwidth-chart">
                <div class="acs-chart-title"><strong>Uso de banda</strong><span id="bandwidth-sample-status">Aguardando segunda leitura...</span></div>
                <div id="bandwidth-bars" class="acs-bandwidth-bars"></div>
            </div>
            <div class="acs-connection-report">
                <div class="acs-chart-title"><strong>Relatório da conexão</strong><span>Sessão atual</span></div>
                <div class="acs-report-grid">
                    <div><span>Interface</span><strong>${wan.name || 'N/A'}</strong></div>
                    <div><span>Tipo</span><strong>${wan.type || 'N/A'}</strong></div>
                    <div><span>IP WAN</span><strong>${wan.external_ip || 'N/A'}</strong></div>
                    <div><span>Uptime</span><strong>${formatUptimeValue(wan.uptime)}</strong></div>
                    <div><span>Pacotes RX</span><strong>${formatCounter(wan.packets_received)}</strong></div>
                    <div><span>Pacotes TX</span><strong>${formatCounter(wan.packets_sent)}</strong></div>
                    <div><span>Erros RX/TX</span><strong>${formatCounter(wan.errors_received)} / ${formatCounter(wan.errors_sent)}</strong></div>
                    <div><span>Último erro</span><strong>${wan.last_error || 'N/A'}</strong></div>
                </div>
            </div>
        </div>`;
}

function updateBandwidthSample(device) {
    const wan = getPrimaryWAN(device);
    if (!wan) return;
    const now = Date.now();
    const traffic = getTrafficCounters(device, wan);
    const rx = traffic.rx;
    const tx = traffic.tx;
    const previous = bandwidthSamples.length ? bandwidthSamples[bandwidthSamples.length - 1] : null;
    let rxMbps = null, txMbps = null;
    if (previous && rx !== null && tx !== null && previous.rx !== null && previous.tx !== null && now > previous.time && rx >= previous.rx && tx >= previous.tx) {
        const seconds = (now - previous.time) / 1000;
        rxMbps = ((rx - previous.rx) * 8) / seconds / 1000000;
        txMbps = ((tx - previous.tx) * 8) / seconds / 1000000;
    }
    bandwidthSamples.push({time: now, rx, tx, rxMbps, txMbps});
    if (bandwidthSamples.length > 24) bandwidthSamples.shift();

    const rxEl = document.getElementById('live-rx-mbps');
    const txEl = document.getElementById('live-tx-mbps');
    if (rxEl) rxEl.textContent = rxMbps === null ? '--' : rxMbps.toFixed(2);
    if (txEl) txEl.textContent = txMbps === null ? '--' : txMbps.toFixed(2);

    const status = document.getElementById('bandwidth-sample-status');
    if (status) status.textContent = (rx === null || tx === null) ? 'Contadores ainda não foram coletados. Clique em Comunicar.' : (rxMbps === null ? 'Aguardando segunda leitura...' : 'Última amostra (' + traffic.source + '): ' + new Date(now).toLocaleTimeString('pt-BR'));

    const chart = document.getElementById('bandwidth-bars');
    if (chart) {
        const valid = bandwidthSamples.filter(s => s.rxMbps !== null);
        chart.innerHTML = renderModernBandwidthChart(valid);
    }
}

async function updateRadiusBandwidthSample() {
    const chart = document.getElementById('bandwidth-bars');
    if (!chart || !deviceId) return;
    try {
        const response = await fetch('/api/get-radius-session.php?device_id=' + encodeURIComponent(deviceId), { credentials: 'same-origin' });
        const data = await response.json();
        const session = data?.session;
        const rx = toCounter(session?.bytes_received), tx = toCounter(session?.bytes_sent);
        if (!data?.success || !data?.online || rx === null || tx === null) return;
        const now = Date.now(), previous = bandwidthSamples[bandwidthSamples.length - 1];
        let rxMbps = null, txMbps = null;
        if (previous && now > previous.time && rx >= previous.rx && tx >= previous.tx) {
            const seconds = (now - previous.time) / 1000;
            rxMbps = ((rx - previous.rx) * 8) / seconds / 1000000;
            txMbps = ((tx - previous.tx) * 8) / seconds / 1000000;
        }
        bandwidthSamples.push({ time: now, rx, tx, rxMbps, txMbps });
        if (bandwidthSamples.length > 60) bandwidthSamples.shift();
        const rxRate = document.getElementById('live-rx-mbps'), txRate = document.getElementById('live-tx-mbps');
        if (rxRate) rxRate.textContent = rxMbps === null ? '--' : rxMbps.toFixed(2);
        if (txRate) txRate.textContent = txMbps === null ? '--' : txMbps.toFixed(2);
        const rxTotal = document.getElementById('live-rx-total'), txTotal = document.getElementById('live-tx-total'), source = document.getElementById('live-traffic-source');
        if (rxTotal) rxTotal.textContent = formatTrafficBytes(rx);
        if (txTotal) txTotal.textContent = formatTrafficBytes(tx);
        if (source) source.textContent = 'IXC/RADIUS • ' + (session.interface || 'sessão PPPoE');
        const status = document.getElementById('bandwidth-sample-status');
        if (status) status.textContent = rxMbps === null ? 'IXC/RADIUS: aguardando segunda leitura...' : 'IXC/RADIUS • ' + new Date(now).toLocaleTimeString('pt-BR');
        const valid = bandwidthSamples.filter(s => s.rxMbps !== null);
        chart.innerHTML = renderModernBandwidthChart(valid);
    } catch (_) { /* mantém a última amostra válida */ }
}

function renderAIAssistantTab(device) {
    const wan = getPrimaryWAN(device);
    const optical = cachedOpticalData || {};
    const context = [
        'Modelo: ' + (device.product_class || device.model || 'N/A'),
        'Serial: ' + (device.serial_number || 'N/A'),
        'Status: ' + (device.status || 'N/A'),
        'WAN: ' + (wan ? (wan.status || 'N/A') : 'N/A'),
        'Uptime: ' + (wan ? formatUptimeValue(wan.uptime) : 'N/A'),
        'Último erro WAN: ' + (wan ? (wan.last_error || 'N/A') : 'N/A'),
        'Dispositivos conectados: ' + (device.connected_devices_count ?? 0)
    ];
    return `
        <div class="acs-ai-shell">
            <div class="acs-ai-hero">
                <div class="acs-ai-icon"><i class="bi bi-stars"></i></div>
                <div><span class="acs-kicker">JR CONECT IA</span><h4>Assistente técnico do equipamento</h4><p>Área preparada para analisar diagnóstico, WAN, sinal óptico, Wi-Fi, clientes e histórico da conexão.</p></div>
                <span class="acs-mini-badge">PREPARADO</span>
            </div>
            <div class="acs-ai-grid">
                <div class="acs-ai-context"><strong>Contexto técnico disponível</strong>${context.map(x => '<span><i class="bi bi-check-circle"></i>'+x+'</span>').join('')}</div>
                <div class="acs-ai-chat">
                    <label for="acs-ai-question">Pergunte sobre este equipamento</label>
                    <textarea id="acs-ai-question" rows="5" placeholder="Ex.: Analise esta conexão e indique possíveis problemas."></textarea>
                    <button type="button" class="acs-soft-btn" onclick="runDeviceAIAnalysis()"><i class="bi bi-stars"></i> Analisar com IA</button>
                    <div id="acs-ai-answer" class="acs-ai-answer">A integração com o provedor de IA será conectada na próxima etapa. Nenhum dado será enviado sem configuração explícita.</div>
                </div>
            </div>
        </div>`;
}

function runDeviceAIAnalysis() {
    const answer = document.getElementById('acs-ai-answer');
    if (answer) answer.innerHTML = '<i class="bi bi-info-circle"></i> Interface de IA pronta. Agora falta definir o provedor/API que será usado no servidor para realizar as análises.';
}


function runOverviewAIQuestion() {
    const input = document.getElementById('acs-ai-question-overview');
    const answer = document.getElementById('acs-ai-answer-overview');
    if (!input || !answer) return;
    const question = input.value.trim();
    if (!question) return;
    answer.innerHTML = '<i class="bi bi-stars"></i> ' + question;
    const tabQuestion = document.getElementById('acs-ai-question');
    if (tabQuestion) tabQuestion.value = question;
    runDeviceAIAnalysis();
    const tabAnswer = document.getElementById('acs-ai-answer');
    if (tabAnswer && tabAnswer.textContent.trim()) {
        answer.innerHTML = tabAnswer.innerHTML;
    } else {
        answer.innerHTML = '<i class="bi bi-info-circle"></i> Assistente preparado para responder quando o provedor de IA estiver configurado.';
    }
}
