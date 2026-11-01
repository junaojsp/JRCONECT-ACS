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

PHP_MARKER = "JRC_CONNECTED_CLIENTS_V2"
JS_MARKER = "JRC_CONNECTED_CLIENTS_UI_V2"


def php_check(path):
    p = subprocess.run(["php", "-l", str(path)], capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout)[:3000])


def js_check(path):
    node = shutil.which("node")
    if not node:
        return
    p = subprocess.run([node, "--check", str(path)], capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout)[:3000])


def atomic_write(path, text):
    meta = path.stat()
    fd, tmp = tempfile.mkstemp(prefix=".jrc-connected-clients-v2-", dir=str(path.parent))
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


def patch_php(text):
    if PHP_MARKER in text or "JRC_WIFI_CLIENTS_BY_BAND_V1" in text:
        return text

    hosts_anchor = "        // Try to get hosts object\n"
    if hosts_anchor not in text:
        raise RuntimeError("Bloco Hosts.Host nao localizado em lib/GenieACS.php")

    detector = r'''
        // JRC_CONNECTED_CLIENTS_V2
        // Classifica a banda do host pela interface L2 ja recebida via TR-069.
        // Nenhuma consulta adicional ao GenieACS e criada.
        $detectWifiBandFromHost = static function (?string $interfacePath) use ($device): ?string {
            if (!$interfacePath) {
                return null;
            }

            if (preg_match('/InternetGatewayDevice\.LANDevice\.(\d+)\.WLANConfiguration\.(\d+)/i', $interfacePath, $m)) {
                $lanIndex = (string)$m[1];
                $wlanIndex = (string)$m[2];
                $w = $device['InternetGatewayDevice']['LANDevice'][$lanIndex]['WLANConfiguration'][$wlanIndex] ?? [];

                $read = static function (array $node, string $field) {
                    return isset($node[$field]) && is_array($node[$field]) && array_key_exists('_value', $node[$field])
                        ? $node[$field]['_value']
                        : null;
                };

                foreach (['OperatingFrequencyBand', 'FrequencyBand', 'Band'] as $field) {
                    $value = strtolower(trim((string)($read($w, $field) ?? '')));
                    if ($value !== '') {
                        if (str_contains($value, '2.4')) return '2.4';
                        if (preg_match('/(^|[^0-9])5(?:\.8)?(?:\s*g(?:hz)?)?([^0-9]|$)/i', $value)) return '5';
                    }
                }

                $channel = $read($w, 'Channel');
                if (is_numeric($channel)) {
                    $channel = (int)$channel;
                    if ($channel >= 36 && $channel <= 196) return '5';
                    if ($channel >= 1 && $channel <= 14) return '2.4';
                }

                $possible = (string)($read($w, 'PossibleChannels') ?? '');
                if ($possible !== '') {
                    preg_match_all('/\d+/', $possible, $matches);
                    $channels = array_values(array_filter(array_map('intval', $matches[0] ?? [])));
                    foreach ($channels as $candidate) {
                        if ($candidate >= 36 && $candidate <= 196) return '5';
                    }
                    if ($channels) {
                        $only24 = true;
                        foreach ($channels as $candidate) {
                            if ($candidate < 1 || $candidate > 14) {
                                $only24 = false;
                                break;
                            }
                        }
                        if ($only24) return '2.4';
                    }
                }

                $ssid = strtolower((string)($read($w, 'SSID') ?? ''));
                if (preg_match('/(^|[-_.\s])(5g|5ghz|5\.8g)([-_.\s]|$)/i', $ssid)) return '5';

                // Mapeamento validado no HG6245D/RP2661 da JR Conect.
                if ((int)$wlanIndex === 5) return '5';
                if ((int)$wlanIndex === 1) return '2.4';
            }

            return null;
        };

'''
    text = text.replace(hosts_anchor, detector + hosts_anchor, 1)

    timestamp_line = "                $timestamp = isset($hostData['_timestamp']) ? $hostData['_timestamp'] : null;\n"
    if timestamp_line not in text:
        raise RuntimeError("Ponto de leitura do host nao localizado")

    nearby_start = text.find(timestamp_line)
    nearby_end = text.find("// Only add devices with valid IP and MAC", nearby_start)
    nearby = text[nearby_start:nearby_end]

    if "$hostInterfacePath" not in nearby:
        interface_code = r'''
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
                    if (isset($hostData[$interfaceField]['_value']) && $hostData[$interfaceField]['_value'] !== '') {
                        $hostInterfacePath = (string)$hostData[$interfaceField]['_value'];
                        break;
                    }
                }

                $lanPortNumber = null;
                if ($hostInterfacePath) {
                    if (preg_match('/LANEthernetInterfaceConfig\.(\d+)/i', $hostInterfacePath, $m)) {
                        $lanPortNumber = (int)$m[1];
                    } elseif (preg_match('/Ethernet\.Interface\.(\d+)/i', $hostInterfacePath, $m)) {
                        $lanPortNumber = (int)$m[1];
                    }
                }
'''
        text = text.replace(timestamp_line, timestamp_line + interface_code, 1)

    only_add = "                // Only add devices with valid IP and MAC\n"
    if only_add not in text:
        raise RuntimeError("Marcador de connected_devices nao localizado")

    section_start = text.rfind(timestamp_line, 0, text.find(only_add))
    pre = text[section_start:text.find(only_add)]
    if "$wifiBand =" not in pre:
        text = text.replace(
            only_add,
            "                $wifiBand = $detectWifiBandFromHost($hostInterfacePath);\n\n" + only_add,
            1
        )

    old_class = r"""                    // Determine interface type (WiFi/LAN)
                    $connectionType = 'LAN';
                    if ($interfaceType === '802.11') {
                        $connectionType = 'WiFi';
                    } elseif ($interfaceType === 'Ethernet') {
                        $connectionType = 'Ethernet';
                    }
"""
    new_class = r"""                    // Determine interface type (WiFi/LAN)
                    $connectionType = 'LAN';
                    $interfacePathLower = strtolower((string)($hostInterfacePath ?? ''));

                    if (
                        $interfaceType === '802.11' ||
                        $wifiBand !== null ||
                        str_contains($interfacePathLower, 'wlanconfiguration')
                    ) {
                        $connectionType = 'WiFi';
                    } elseif (
                        $interfaceType === 'Ethernet' ||
                        str_contains($interfacePathLower, 'lanethernetinterfaceconfig') ||
                        str_contains($interfacePathLower, 'ethernet.interface')
                    ) {
                        $connectionType = 'Ethernet';
                    }
"""
    if old_class in text:
        text = text.replace(old_class, new_class, 1)
    elif "$interfacePathLower" not in text:
        raise RuntimeError("Bloco de classificacao WiFi/Ethernet nao localizado")

    array_pos = text.find("$connectedDevices[] = [")
    if array_pos < 0:
        raise RuntimeError("Array connectedDevices nao localizado")
    array_end = text.find("];", array_pos)
    block = text[array_pos:array_end]

    if "'interface_path'" not in block:
        needle = "                        'interface_type' => $connectionType,\n"
        if needle not in block:
            raise RuntimeError("Campo interface_type nao localizado")
        text = text[:array_pos] + block.replace(
            needle,
            needle + "                        'interface_path' => $hostInterfacePath,\n",
            1
        ) + text[array_end:]
        array_end = text.find("];", array_pos)
        block = text[array_pos:array_end]

    if "'lan_port'" not in block:
        needle = "                        'interface_path' => $hostInterfacePath,\n"
        text = text[:array_pos] + block.replace(
            needle,
            needle + "                        'lan_port' => $lanPortNumber,\n",
            1
        ) + text[array_end:]
        array_end = text.find("];", array_pos)
        block = text[array_pos:array_end]

    if "'wifi_band'" not in block:
        needle = "                        'lan_port' => $lanPortNumber,\n"
        if needle not in block:
            raise RuntimeError("Campo lan_port nao localizado")
        text = text[:array_pos] + block.replace(
            needle,
            needle + "                        'wifi_band' => $wifiBand,\n",
            1
        ) + text[array_end:]

    return text


def patch_js(text):
    if JS_MARKER in text:
        return text

    target = "function renderConnectedDevicesTab(connectedDevices) {"
    pos = text.find(target)
    if pos < 0:
        raise RuntimeError("renderConnectedDevicesTab nao localizado em device-detail.js")

    helpers = r'''// JRC_CONNECTED_CLIENTS_UI_V2
function jrcClientIsActive(client) {
    if (!client) return false;
    const value = client.active;
    return ![false, 0, '0', 'false', 'False', 'FALSE'].includes(value);
}

function jrcClientBandList(device, band) {
    if (!device || !Array.isArray(device.connected_devices)) return [];
    return device.connected_devices.filter(function (client) {
        return jrcClientIsActive(client) &&
            String(client.interface_type || '').toLowerCase() === 'wifi' &&
            String(client.wifi_band || '') === band;
    });
}

function jrcEscapeClientHtml(value) {
    return String(value == null ? '' : value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function jrcConnectionText(client) {
    const type = String((client && client.interface_type) || '').toLowerCase();

    if (type === 'wifi') {
        if (String(client.wifi_band || '') === '5') return 'Wi-Fi · 5 GHz';
        if (String(client.wifi_band || '') === '2.4') return 'Wi-Fi · 2,4 GHz';
        return 'Wi-Fi';
    }

    if (type === 'ethernet') {
        const port = Number(client.lan_port || 0);
        return port > 0 ? 'Cabo · LAN ' + port : 'Cabo';
    }

    return (client && client.interface_type) || 'Rede';
}

function jrcEnhanceConnectedClientsTable(connectedDevices) {
    const table = document.getElementById('connected-devices-table');
    if (!table || !Array.isArray(connectedDevices)) return;

    const byMac = new Map();
    connectedDevices.forEach(function (client) {
        const mac = String((client && client.mac_address) || '').trim().toLowerCase();
        if (mac) byMac.set(mac, client);
    });

    table.querySelectorAll('tbody tr[data-mac]').forEach(function (row) {
        const mac = String(row.getAttribute('data-mac') || '').trim().toLowerCase();
        const client = byMac.get(mac);
        if (!client) return;

        const cell = row.querySelector('td:nth-child(5)');
        if (!cell) return;

        const isWifi = String(client.interface_type || '').toLowerCase() === 'wifi';
        cell.innerHTML =
            '<span class="badge ' + (isWifi ? 'bg-secondary' : 'bg-success') + '">' +
            '<i class="bi ' + (isWifi ? 'bi-wifi' : 'bi-ethernet') + '"></i> ' +
            jrcEscapeClientHtml(jrcConnectionText(client)) +
            '</span>';
    });
}

function jrcApplyWifiClientCounters(device) {
    const root = document.querySelector('.acs-wifi-reference-grid');
    if (!root) return;

    const rows = Array.from(
        root.querySelectorAll('.acs-wifi-health-row:not(.acs-wifi-unified-row)')
    );

    [
        { row: rows[0], band: '2.4', label: '2,4 GHz' },
        { row: rows[1], band: '5', label: '5 GHz' }
    ].forEach(function (spec) {
        if (!spec.row) return;

        const clients = jrcClientBandList(device, spec.band);
        const detail = Array.from(
            spec.row.querySelectorAll('.acs-wifi-health-detail')
        ).find(function (item) {
            const small = item.querySelector('small');
            return small && small.textContent.trim().toLowerCase() === 'clientes';
        });

        if (!detail) return;
        const strong = detail.querySelector('strong');
        if (!strong) return;

        const label = clients.length === 1
            ? '1 conectado'
            : String(clients.length) + ' conectados';

        if (!clients.length) {
            strong.textContent = label;
            return;
        }

        strong.innerHTML =
            '<button type="button" class="btn btn-link p-0 text-decoration-none" ' +
            'style="color:inherit;font-size:inherit;font-weight:700" ' +
            'onclick="jrcOpenWifiClients(\'' + spec.band + '\', \'' + spec.label + '\')" ' +
            'title="Ver clientes desta banda">' +
            jrcEscapeClientHtml(label) +
            '</button>';
    });
}

function jrcOpenWifiClients(band, bandLabel) {
    const device = (typeof currentDeviceData === 'object' && currentDeviceData)
        ? currentDeviceData
        : {};
    const clients = jrcClientBandList(device, band);

    let modal = document.getElementById('jrcWifiClientsModalV2');
    if (!modal) {
        modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = 'jrcWifiClientsModalV2';
        modal.tabIndex = -1;
        modal.innerHTML =
            '<div class="modal-dialog modal-lg modal-dialog-centered">' +
                '<div class="modal-content">' +
                    '<div class="modal-header">' +
                        '<div>' +
                            '<small class="text-danger fw-bold">JR CONECT · WI-FI</small>' +
                            '<h5 class="modal-title mt-1" id="jrcWifiClientsTitleV2">Clientes conectados</h5>' +
                        '</div>' +
                        '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>' +
                    '</div>' +
                    '<div class="modal-body" id="jrcWifiClientsBodyV2"></div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
    }

    const title = modal.querySelector('#jrcWifiClientsTitleV2');
    const body = modal.querySelector('#jrcWifiClientsBodyV2');

    if (title) title.textContent = 'Clientes conectados · ' + bandLabel;

    if (body) {
        if (!clients.length) {
            body.innerHTML = '<div class="alert alert-info mb-0">Nenhum cliente conectado nesta banda.</div>';
        } else {
            let rows = '';
            clients.forEach(function (client) {
                const name = client.hostname || client.vendor || 'Dispositivo';
                const vendor = client.vendor && client.vendor !== client.hostname
                    ? '<br><small class="text-muted">' + jrcEscapeClientHtml(client.vendor) + '</small>'
                    : '';

                rows +=
                    '<tr>' +
                        '<td><strong>' + jrcEscapeClientHtml(name) + '</strong>' + vendor + '</td>' +
                        '<td>' + jrcEscapeClientHtml(client.ip_address || '-') + '</td>' +
                        '<td><code>' + jrcEscapeClientHtml(client.mac_address || '-') + '</code></td>' +
                    '</tr>';
            });

            body.innerHTML =
                '<div class="table-responsive">' +
                    '<table class="table table-sm table-hover align-middle mb-0">' +
                        '<thead><tr><th>Dispositivo</th><th>IP</th><th>MAC</th></tr></thead>' +
                        '<tbody>' + rows + '</tbody>' +
                    '</table>' +
                '</div>';
        }
    }

    if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }
}

window.jrcOpenWifiClients = jrcOpenWifiClients;

'''
    text = text[:pos] + helpers + text[pos:]

    devices_line = "        document.getElementById('devices-content').innerHTML = renderConnectedDevicesTab(device.connected_devices);"
    if devices_line in text and "jrcEnhanceConnectedClientsTable(device.connected_devices);" not in text:
        text = text.replace(
            devices_line,
            devices_line + "\n        jrcEnhanceConnectedClientsTable(device.connected_devices);",
            1
        )
    elif "jrcEnhanceConnectedClientsTable(device.connected_devices);" not in text:
        raise RuntimeError("Hook da aba Rede/Hosts nao localizado")

    pattern = re.compile(r'(^[ \t]*)renderWifiHealthCard\(device\);', re.MULTILINE)
    if not pattern.search(text):
        raise RuntimeError("Hook renderWifiHealthCard(device) nao localizado")

    text = pattern.sub(
        lambda m: m.group(0) + "\n" + m.group(1) + "jrcApplyWifiClientCounters(device);",
        text
    )

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

    if "wifi_band" not in php_after:
        raise RuntimeError("Backend nao ficou com wifi_band")
    if JS_MARKER not in js_after:
        raise RuntimeError("Frontend V2 nao foi inserido")

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

    print("VALIDADO: clientes conectados V2.")
    print("Rede/Hosts: Cabo LAN X, Wi-Fi 2,4 GHz ou Wi-Fi 5 GHz.")
    print("Wi-Fi: contagem separada por banda e lista clicavel.")
    print("Fonte: Hosts.Host ja existente; nenhuma consulta adicional.")
    print("Layout atual sera preservado.")

    if args.dry_run:
        print("SIMULACAO OK: nenhum arquivo alterado.")
        return

    backup_parent = Path(args.backup_dir).resolve()
    lock_path = backup_parent / ".jrc-connected-clients-v2.lock"

    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        if php.read_text(encoding="utf-8") != php_before:
            raise RuntimeError("lib/GenieACS.php mudou durante a instalacao")
        if js.read_text(encoding="utf-8") != js_before:
            raise RuntimeError("device-detail.js mudou durante a instalacao")

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-connected-clients-v2-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
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

        print("INSTALADO: clientes conectados V2.")
        print("Backup: " + str(backup))


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
