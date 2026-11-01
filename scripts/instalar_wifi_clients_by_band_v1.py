#!/usr/bin/env python3
import argparse
import datetime
import fcntl
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path


PHP_MARKER = "JRC_WIFI_CLIENTS_BY_BAND_V1"
JS_MARKER = "JRC_WIFI_CLIENTS_BY_BAND_UI_V1"


def php_check(path: Path):
    p = subprocess.run(["php", "-l", str(path)], capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout)[:3000])


def js_check(path: Path):
    node = shutil.which("node")
    if not node:
        return
    p = subprocess.run([node, "--check", str(path)], capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout)[:3000])


def atomic_write(path: Path, text: str):
    meta = path.stat()
    fd, tmp = tempfile.mkstemp(prefix=".jrc-wifi-clients-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(text)
            f.flush()
            os.fsync(f.fileno())
            os.fchmod(f.fileno(), stat.S_IMODE(meta.st_mode))
            if os.geteuid() == 0:
                os.fchown(f.fileno(), meta.st_uid, meta.st_gid)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def insert_after(text: str, marker: str, addition: str, label: str) -> str:
    pos = text.find(marker)
    if pos < 0:
        raise RuntimeError("Marcador nao encontrado para " + label)
    pos += len(marker)
    return text[:pos] + addition + text[pos:]


def patch_php(text: str) -> str:
    original = text

    # 1) Detector de banda a partir do Layer2Interface do host.
    if PHP_MARKER not in text:
        anchor = """        // Try to get hosts object
"""
        if anchor not in text:
            raise RuntimeError("Bloco de Hosts.Host nao localizado em lib/GenieACS.php")

        helper = r'''
        // JRC_WIFI_CLIENTS_BY_BAND_V1
        // Classifica clientes Wi-Fi usando a interface L2 realmente reportada pelo CPE.
        // Nao faz consulta adicional ao GenieACS.
        $detectHostWifiBand = static function (?string $interfacePath) use ($device): ?string {
            if (!$interfacePath) {
                return null;
            }

            // TR-098: InternetGatewayDevice.LANDevice.X.WLANConfiguration.Y
            if (preg_match('/InternetGatewayDevice\.LANDevice\.(\d+)\.WLANConfiguration\.(\d+)/i', $interfacePath, $m)) {
                $lanIndex = (string)$m[1];
                $wifiIndex = (string)$m[2];
                $w = $device['InternetGatewayDevice']['LANDevice'][$lanIndex]['WLANConfiguration'][$wifiIndex] ?? [];

                $read = static function (array $node, string $key) {
                    return isset($node[$key]) && is_array($node[$key]) && array_key_exists('_value', $node[$key])
                        ? $node[$key]['_value']
                        : null;
                };

                foreach (['OperatingFrequencyBand', 'FrequencyBand', 'Band', 'X_HW_FrequencyBand', 'X_FH_OperatingFrequencyBand'] as $field) {
                    $value = strtolower(trim((string)($read($w, $field) ?? '')));
                    if ($value !== '') {
                        if (str_contains($value, '2.4')) {
                            return '2.4';
                        }
                        if (preg_match('/(^|[^0-9])5(?:\.8)?(?:\s*g(?:hz)?)?([^0-9]|$)/i', $value)) {
                            return '5';
                        }
                        if (str_contains($value, '6')) {
                            return '6';
                        }
                    }
                }

                $channel = $read($w, 'Channel');
                if (is_numeric($channel)) {
                    $channel = (int)$channel;
                    if ($channel >= 36 && $channel <= 196) {
                        return '5';
                    }
                    if ($channel >= 1 && $channel <= 14) {
                        return '2.4';
                    }
                }

                $possible = (string)($read($w, 'PossibleChannels') ?? '');
                if ($possible !== '') {
                    preg_match_all('/\d+/', $possible, $matches);
                    $channels = array_values(array_filter(array_map('intval', $matches[0] ?? [])));
                    if ($channels) {
                        foreach ($channels as $candidate) {
                            if ($candidate >= 36 && $candidate <= 196) {
                                return '5';
                            }
                        }
                        $only24 = true;
                        foreach ($channels as $candidate) {
                            if ($candidate < 1 || $candidate > 14) {
                                $only24 = false;
                                break;
                            }
                        }
                        if ($only24) {
                            return '2.4';
                        }
                    }
                }

                $standard = strtolower(
                    (string)($read($w, 'Standard') ?? '') . ' ' .
                    (string)($read($w, 'OperatingStandards') ?? '') . ' ' .
                    (string)($read($w, 'SupportedStandards') ?? '')
                );

                if ($standard !== '') {
                    if (
                        str_contains($standard, '802.11ac') ||
                        preg_match('/(^|[^a-z0-9])ac([^a-z0-9]|$)/i', $standard) ||
                        preg_match('/(^|[^a-z0-9])802\.11a([^a-z0-9]|$)/i', $standard)
                    ) {
                        return '5';
                    }
                    if (
                        preg_match('/(^|[^a-z0-9])802\.11b([^a-z0-9]|$)/i', $standard) ||
                        preg_match('/(^|[^a-z0-9])802\.11g([^a-z0-9]|$)/i', $standard)
                    ) {
                        return '2.4';
                    }
                }

                $ssid = (string)($read($w, 'SSID') ?? '');
                if (preg_match('/(^|[-_.\s])(5g|5ghz|5\.8g)([-_.\s]|$)/i', $ssid)) {
                    return '5';
                }
                if (preg_match('/(^|[-_.\s])(2g|2\.4g|2\.4ghz)([-_.\s]|$)/i', $ssid)) {
                    return '2.4';
                }

                // Fallback validado no FiberHome HG6245D:
                // WLANConfiguration.1 = 2,4 GHz e WLANConfiguration.5 = 5 GHz.
                if ((int)$wifiIndex === 5) {
                    return '5';
                }
                if ((int)$wifiIndex === 1) {
                    return '2.4';
                }
            }

            // TR-181: Device.WiFi.SSID.X -> Radio via LowerLayers.
            if (preg_match('/Device\.WiFi\.SSID\.(\d+)/i', $interfacePath, $m)) {
                $ssidIndex = (string)$m[1];
                $ssid = $device['Device']['WiFi']['SSID'][$ssidIndex] ?? [];
                $lower = isset($ssid['LowerLayers']['_value']) ? (string)$ssid['LowerLayers']['_value'] : '';
                if (preg_match('/Device\.WiFi\.Radio\.(\d+)/i', $lower, $rm)) {
                    $radio = $device['Device']['WiFi']['Radio'][(string)$rm[1]] ?? [];
                    foreach (['OperatingFrequencyBand', 'X_HW_FrequencyBand'] as $field) {
                        $value = isset($radio[$field]['_value']) ? strtolower((string)$radio[$field]['_value']) : '';
                        if (str_contains($value, '2.4')) {
                            return '2.4';
                        }
                        if (preg_match('/(^|[^0-9])5(?:\.8)?([^0-9]|$)/', $value)) {
                            return '5';
                        }
                        if (str_contains($value, '6')) {
                            return '6';
                        }
                    }
                }
            }

            return null;
        };

'''
        text = text.replace(anchor, helper + anchor, 1)

    # 2) Captura Layer2Interface (se a versao atual ainda nao faz isso).
    host_section_start = text.find("// Try to get hosts object")
    host_section_end = text.find("$data['connected_devices']", host_section_start)
    if host_section_start < 0 or host_section_end < 0:
        raise RuntimeError("Secao de clientes conectados nao localizada")

    host_section = text[host_section_start:host_section_end]

    if "$hostInterfacePath" not in host_section:
        timestamp_line = """                $timestamp = isset($hostData['_timestamp']) ? $hostData['_timestamp'] : null;
"""
        if timestamp_line not in text:
            raise RuntimeError("Linha de timestamp do host nao localizada")

        interface_code = r'''
                // Interface fisica/logica reportada pelo CPE.
                $hostInterfacePath = null;
                foreach ([
                    'Layer2Interface',
                    'Interface',
                    'AssociatedDevice',
                    'X_CT-COM_Layer2Interface',
                    'X_HW_Layer2Interface',
                    'X_CU_Layer2Interface',
                    'X_FH_Layer2Interface',
                    'X_FH_Interface',
                    'X_CT-COM_Interface'
                ] as $interfaceField) {
                    if (
                        isset($hostData[$interfaceField]['_value']) &&
                        $hostData[$interfaceField]['_value'] !== ''
                    ) {
                        $hostInterfacePath = (string)$hostData[$interfaceField]['_value'];
                        break;
                    }
                }
'''
        text = text.replace(timestamp_line, timestamp_line + interface_code, 1)

    # 3) Corrige classificacao WiFi/Ethernet usando o caminho L2 quando disponivel.
    if "JRC_WIFI_CLIENT_CONNECTION_CLASS_V1" not in text:
        old_block = r"""                // Determine interface type (WiFi/LAN)
                $connectionType = 'LAN';
                if ($interfaceType === '802.11') {
                    $connectionType = 'WiFi';
                } elseif ($interfaceType === 'Ethernet') {
                    $connectionType = 'Ethernet';
                }
"""
        new_block = r"""                // Determine interface type (WiFi/LAN)
                // JRC_WIFI_CLIENT_CONNECTION_CLASS_V1
                $connectionType = 'LAN';
                $interfacePathLower = strtolower((string)($hostInterfacePath ?? ''));

                if (
                    $interfaceType === '802.11' ||
                    str_contains($interfacePathLower, 'wlanconfiguration') ||
                    str_contains($interfacePathLower, 'device.wifi.')
                ) {
                    $connectionType = 'WiFi';
                } elseif (
                    $interfaceType === 'Ethernet' ||
                    str_contains($interfacePathLower, 'lanethernetinterfaceconfig') ||
                    str_contains($interfacePathLower, 'ethernet.interface')
                ) {
                    $connectionType = 'Ethernet';
                }

                $wifiBand = $connectionType === 'WiFi'
                    ? $detectHostWifiBand($hostInterfacePath)
                    : null;
"""
        if old_block in text:
            text = text.replace(old_block, new_block, 1)
        else:
            # Versoes mais novas podem ja ter interface_path, mas ainda sem wifiBand.
            anchor = """                // Get MAC vendor name
"""
            if anchor not in text:
                raise RuntimeError("Bloco de classificacao do host nao localizado")
            if "$wifiBand =" not in text[host_section_start:text.find(anchor, host_section_start)]:
                text = text.replace(
                    anchor,
                    """                // JRC_WIFI_CLIENT_CONNECTION_CLASS_V1
                $interfacePathLower = strtolower((string)($hostInterfacePath ?? ''));
                if (
                    str_contains($interfacePathLower, 'wlanconfiguration') ||
                    str_contains($interfacePathLower, 'device.wifi.')
                ) {
                    $connectionType = 'WiFi';
                } elseif (
                    str_contains($interfacePathLower, 'lanethernetinterfaceconfig') ||
                    str_contains($interfacePathLower, 'ethernet.interface')
                ) {
                    $connectionType = 'Ethernet';
                }

                $wifiBand = $connectionType === 'WiFi'
                    ? $detectHostWifiBand($hostInterfacePath)
                    : null;

""" + anchor,
                    1
                )

    # 4) Acrescenta interface_path e wifi_band ao JSON de connected_devices.
    array_start = text.find("$connectedDevices[] = [", host_section_start)
    if array_start < 0:
        raise RuntimeError("Array connectedDevices nao localizado")
    array_end = text.find("];", array_start)
    if array_end < 0:
        raise RuntimeError("Fim do array connectedDevices nao localizado")

    block = text[array_start:array_end]
    if "'interface_path'" not in block:
        needle = """                        'interface_type' => $connectionType,
"""
        if needle not in block:
            raise RuntimeError("Campo interface_type nao localizado")
        replacement = needle + """                        'interface_path' => $hostInterfacePath,
"""
        text = text[:array_start] + block.replace(needle, replacement, 1) + text[array_end:]
        array_end = text.find("];", array_start)
        block = text[array_start:array_end]

    if "'wifi_band'" not in block:
        needle = """                        'interface_path' => $hostInterfacePath,
"""
        if needle not in block:
            raise RuntimeError("Campo interface_path nao localizado apos patch")
        replacement = needle + """                        'wifi_band' => $wifiBand,
"""
        text = text[:array_start] + block.replace(needle, replacement, 1) + text[array_end:]

    # 5) Calcula contagem/lista por banda sem consulta adicional.
    if "wifi_clients_24ghz_devices" not in text:
        marker = """        $data['connected_devices_count'] = count($connectedDevices);
"""
        if marker not in text:
            raise RuntimeError("connected_devices_count nao localizado")

        counts = r'''
        // Clientes realmente ativos separados por banda Wi-Fi.
        $activeConnectedDevices = array_values(array_filter(
            $connectedDevices,
            static function (array $item): bool {
                $active = $item['active'] ?? true;
                return !in_array($active, [false, 0, '0', 'false', 'False', 'FALSE'], true);
            }
        ));

        $wifiClients24 = array_values(array_filter(
            $activeConnectedDevices,
            static fn(array $item): bool =>
                ($item['interface_type'] ?? '') === 'WiFi' &&
                ($item['wifi_band'] ?? null) === '2.4'
        ));

        $wifiClients5 = array_values(array_filter(
            $activeConnectedDevices,
            static fn(array $item): bool =>
                ($item['interface_type'] ?? '') === 'WiFi' &&
                ($item['wifi_band'] ?? null) === '5'
        ));

        $data['wifi_clients_24ghz'] = count($wifiClients24);
        $data['wifi_clients_5ghz'] = count($wifiClients5);
        $data['wifi_clients_24ghz_devices'] = $wifiClients24;
        $data['wifi_clients_5ghz_devices'] = $wifiClients5;
'''
        text = text.replace(marker, marker + counts, 1)

    required = [
        PHP_MARKER,
        "$detectHostWifiBand",
        "'interface_path' => $hostInterfacePath",
        "'wifi_band' => $wifiBand",
        "wifi_clients_24ghz_devices",
        "wifi_clients_5ghz_devices",
    ]
    for item in required:
        if item not in text:
            raise RuntimeError("Patch PHP incompleto: " + item)

    if text == original:
        print("PHP ja estava corrigido.")
    return text


def patch_js(text: str) -> str:
    original = text

    if JS_MARKER not in text:
        marker = "function renderWifiHealthCard(device) {"
        pos = text.find(marker)
        if pos < 0:
            raise RuntimeError("renderWifiHealthCard nao localizado em device-detail.js")

        helpers = r'''// JRC_WIFI_CLIENTS_BY_BAND_UI_V1
function jrWifiClientIsActive(client) {
    if (!client) return false;
    return ![false, 0, '0', 'false', 'False', 'FALSE'].includes(client.active);
}

function jrWifiBandClients(device, band) {
    if (!device) return null;

    const direct = band === '2.4'
        ? device.wifi_clients_24ghz_devices
        : band === '5'
            ? device.wifi_clients_5ghz_devices
            : null;

    if (Array.isArray(direct)) {
        return direct.filter(jrWifiClientIsActive);
    }

    if (!Array.isArray(device.connected_devices)) {
        return null;
    }

    return device.connected_devices.filter(client => {
        if (!jrWifiClientIsActive(client)) return false;
        if (String(client.interface_type || '').toLowerCase() !== 'wifi') return false;
        return String(client.wifi_band || '') === band;
    });
}

function jrWifiClientCountLabel(count) {
    if (count === 1) return '1 conectado';
    return String(count) + ' conectados';
}

function jrWifiEscape(value) {
    return String(value == null ? '' : value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function jrEnhanceWifiClientCounters(device) {
    const container = document.querySelector('.acs-wifi-reference-grid');
    if (!container) return;

    const rows = Array.from(
        container.querySelectorAll('.acs-wifi-health-row:not(.acs-wifi-unified-row)')
    );

    const specs = [
        { band: '2.4', label: '2,4 GHz', row: rows[0] || null },
        { band: '5', label: '5 GHz', row: rows[1] || null }
    ];

    specs.forEach(spec => {
        if (!spec.row) return;

        const details = Array.from(spec.row.querySelectorAll('.acs-wifi-health-detail'));
        const target = details.find(detail => {
            const small = detail.querySelector('small');
            return small && small.textContent.trim().toLowerCase() === 'clientes';
        });
        if (!target) return;

        const strong = target.querySelector('strong');
        if (!strong) return;

        const clients = jrWifiBandClients(device, spec.band);
        if (clients === null) {
            strong.textContent = 'Não informado';
            return;
        }

        const label = jrWifiClientCountLabel(clients.length);

        if (clients.length === 0) {
            strong.textContent = label;
            return;
        }

        strong.innerHTML =
            '<button type="button" ' +
            'style="background:none;border:0;padding:0;color:inherit;font:inherit;font-weight:700;cursor:pointer;text-decoration:underline;text-decoration-color:#ef1b22;text-underline-offset:3px" ' +
            'onclick="jrOpenWifiClientsModal(\'' + spec.band + '\', \'' + spec.label + '\')" ' +
            'title="Ver clientes conectados">' +
            jrWifiEscape(label) +
            '</button>';
    });
}

function jrOpenWifiClientsModal(band, bandLabel) {
    const device = typeof currentDeviceData === 'object' && currentDeviceData
        ? currentDeviceData
        : null;
    const clients = jrWifiBandClients(device, band) || [];

    let modalEl = document.getElementById('jrWifiClientsModal');
    if (!modalEl) {
        modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = 'jrWifiClientsModal';
        modalEl.tabIndex = -1;
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.innerHTML =
            '<div class="modal-dialog modal-lg modal-dialog-centered">' +
              '<div class="modal-content" style="background:#111419;color:#f4f6f8;border:1px solid #2b3038">' +
                '<div class="modal-header" style="border-color:#2b3038">' +
                  '<div>' +
                    '<small style="color:#ef1b22;font-weight:700">JR CONECT · WI-FI</small>' +
                    '<h5 class="modal-title" id="jrWifiClientsTitle" style="margin-top:3px">Clientes conectados</h5>' +
                  '</div>' +
                  '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>' +
                '</div>' +
                '<div class="modal-body" id="jrWifiClientsBody"></div>' +
              '</div>' +
            '</div>';
        document.body.appendChild(modalEl);
    }

    const title = modalEl.querySelector('#jrWifiClientsTitle');
    const body = modalEl.querySelector('#jrWifiClientsBody');

    if (title) {
        title.textContent = 'Clientes conectados · ' + bandLabel;
    }

    if (body) {
        if (!clients.length) {
            body.innerHTML =
                '<div style="padding:18px;border:1px solid #2b3038;border-radius:10px;color:#aeb6c2">' +
                'Nenhum cliente conectado nesta banda.' +
                '</div>';
        } else {
            const header =
                '<div style="display:grid;grid-template-columns:minmax(180px,1.3fr) minmax(120px,.8fr) minmax(160px,1fr);gap:12px;padding:0 12px 9px;color:#8f9aaa;font-size:11px;font-weight:700;text-transform:uppercase">' +
                  '<span>Dispositivo</span><span>IP</span><span>MAC</span>' +
                '</div>';

            const rows = clients.map(client => {
                const name = client.hostname || client.vendor || 'Dispositivo';
                const vendor = client.vendor && client.vendor !== name ? client.vendor : '';
                const ip = client.ip_address || '-';
                const mac = client.mac_address || '-';
                const path = client.interface_path || '';

                return (
                    '<div style="display:grid;grid-template-columns:minmax(180px,1.3fr) minmax(120px,.8fr) minmax(160px,1fr);gap:12px;align-items:center;padding:12px;border:1px solid #2b3038;border-radius:10px;margin-bottom:8px;background:#15191f">' +
                      '<div><strong>' + jrWifiEscape(name) + '</strong>' +
                        (vendor ? '<small style="display:block;color:#8f9aaa;margin-top:3px">' + jrWifiEscape(vendor) + '</small>' : '') +
                        (path ? '<small style="display:block;color:#6f7885;margin-top:3px;word-break:break-all">' + jrWifiEscape(path) + '</small>' : '') +
                      '</div>' +
                      '<div>' + jrWifiEscape(ip) + '</div>' +
                      '<div><code style="color:#f4f6f8">' + jrWifiEscape(mac) + '</code></div>' +
                    '</div>'
                );
            }).join('');

            body.innerHTML = header + rows;
        }
    }

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

window.jrOpenWifiClientsModal = jrOpenWifiClientsModal;

'''
        text = text[:pos] + helpers + text[pos:]

    # Executa o enriquecimento logo apos qualquer render da caixa Wi-Fi.
    if "jrEnhanceWifiClientCounters(device);" not in text:
        pattern = re.compile(r'(^[ \t]*)renderWifiHealthCard\(device\);', re.MULTILINE)
        text, count = pattern.subn(
            lambda m: m.group(0) + "\n" + m.group(1) + "jrEnhanceWifiClientCounters(device);",
            text,
            count=1
        )
        if count == 0:
            pattern = re.compile(r'(^[ \t]*)renderWifiHealthCard\(currentDeviceData\);', re.MULTILINE)
            text, count = pattern.subn(
                lambda m: m.group(0) + "\n" + m.group(1) + "jrEnhanceWifiClientCounters(currentDeviceData);",
                text,
                count=1
            )
        if count == 0:
            raise RuntimeError("Chamada de renderWifiHealthCard nao localizada")

    if JS_MARKER not in text or "jrOpenWifiClientsModal" not in text:
        raise RuntimeError("Patch JS incompleto")

    if text == original:
        print("JS ja estava corrigido.")
    return text


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default="/var/www/gacs")
    ap.add_argument("--backup-dir", default="/root")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    php = root / "lib/GenieACS.php"
    js = root / "assets/js/device-detail.js"

    for path in (php, js):
        if not path.is_file() or path.is_symlink():
            raise RuntimeError("Arquivo ausente ou invalido: " + str(path))

    php_before = php.read_text(encoding="utf-8")
    js_before = js.read_text(encoding="utf-8")

    php_after = patch_php(php_before)
    js_after = patch_js(js_before)

    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as f:
        f.write(php_after)
        tmp_php = Path(f.name)

    with tempfile.NamedTemporaryFile("w", suffix=".js", delete=False, encoding="utf-8") as f:
        f.write(js_after)
        tmp_js = Path(f.name)

    try:
        php_check(tmp_php)
        js_check(tmp_js)
    finally:
        tmp_php.unlink(missing_ok=True)
        tmp_js.unlink(missing_ok=True)

    print("VALIDADO: clientes Wi-Fi por banda.")
    print("Fonte: Hosts.Host ja coletado pelo GenieACS; nenhuma consulta adicional sera criada.")
    print("2.4/5 GHz serao classificados pelo Layer2Interface e metadados da WLAN.")
    print("Contagem e lista clicavel serao exibidas na aba Wi-Fi.")

    if args.dry_run:
        print("SIMULACAO OK: nenhum arquivo alterado.")
        return

    backup_parent = Path(args.backup_dir).resolve()
    lock_path = backup_parent / ".jrc-wifi-clients-by-band-v1.lock"

    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        if php.read_text(encoding="utf-8") != php_before:
            raise RuntimeError("lib/GenieACS.php mudou durante a instalacao")
        if js.read_text(encoding="utf-8") != js_before:
            raise RuntimeError("assets/js/device-detail.js mudou durante a instalacao")

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-wifi-clients-by-band-v1-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent)
        ))
        os.chmod(backup, 0o700)

        (backup / "lib").mkdir(parents=True, exist_ok=True)
        (backup / "assets/js").mkdir(parents=True, exist_ok=True)
        shutil.copy2(php, backup / "lib/GenieACS.php")
        shutil.copy2(js, backup / "assets/js/device-detail.js")
        shutil.copy2(Path(__file__), backup / "install.py")

        atomic_write(php, php_after)
        atomic_write(js, js_after)

        php_check(php)
        js_check(js)

        print("INSTALADO: clientes Wi-Fi por banda V1.")
        print("Backup: " + str(backup))


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
