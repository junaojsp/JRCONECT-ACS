<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../lib/IXCConfig.php';
require_once __DIR__ . '/../../../lib/SIMSAuth.php';

use App\GenieACS;
use App\CPEProfiles;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function simsOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function simsIxcList(string $baseUrl, string $token, string $table, string $qtype, string $query, int $rp = 20): array
{
    $ch = curl_init($baseUrl . '/webservice/v1/' . rawurlencode($table));
    if ($ch === false) throw new RuntimeException('Falha ao iniciar consulta IXC.');

    $payload = json_encode([
        'qtype' => $qtype,
        'query' => $query,
        'oper' => '=',
        'page' => '1',
        'rp' => (string)$rp,
        'sortname' => $table . '.id',
        'sortorder' => 'desc',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException('Falha na consulta IXC: ' . $error);
    if ($http < 200 || $http >= 300) throw new RuntimeException('IXC retornou HTTP ' . $http . '.');

    $json = json_decode($body, true);
    if (!is_array($json)) return [];
    $rows = $json['registros'] ?? $json['records'] ?? (array_is_list($json) ? $json : []);
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function simsResolveFiber(string $idLogin, string $idContrato): array
{
    $cfg = getIxcConfig();
    $rows = [];

    if ($idLogin !== '') {
        $rows = simsIxcList(
            $cfg['base_url'],
            $cfg['token'],
            'radpop_radio_cliente_fibra',
            'radpop_radio_cliente_fibra.id_login',
            $idLogin,
            10
        );
    }

    if (!$rows && $idContrato !== '') {
        $rows = simsIxcList(
            $cfg['base_url'],
            $cfg['token'],
            'radpop_radio_cliente_fibra',
            'radpop_radio_cliente_fibra.id_contrato',
            $idContrato,
            10
        );
    }

    if (!$rows) {
        throw new RuntimeException('Equipamento não encontrado no IXC para o contrato/login informado.');
    }

    return $rows[0];
}

function simsGenie(): GenieACS
{
    $db = getDBConnection();
    $stmt = $db->prepare('SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1');
    if (!$stmt) throw new RuntimeException('Falha ao ler configuração GenieACS.');
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) throw new RuntimeException('GenieACS não configurado.');

    return new GenieACS($row['host'], $row['port'], $row['username'], $row['password']);
}

function simsFindDeviceBySerial(GenieACS $genie, string $serial): array
{
    $aliases = CPEProfiles::serialAliases($serial);
    foreach ($aliases as $alias) {
        foreach ([
            ['_deviceId._SerialNumber' => $alias],
            ['InternetGatewayDevice.DeviceInfo.SerialNumber._value' => $alias],
            ['Device.DeviceInfo.SerialNumber._value' => $alias],
        ] as $query) {
            $r = $genie->getDevices($query, 2);
            if (!empty($r['success']) && !empty($r['data'][0]) && is_array($r['data'][0])) {
                return $r['data'][0];
            }
        }
    }

    throw new RuntimeException('Equipamento localizado no IXC, mas não encontrado no GenieACS.');
}

function simsDeviceContext(string $idLogin, string $idContrato): array
{
    $fiber = simsResolveFiber($idLogin, $idContrato);
    $serial = strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', (string)($fiber['mac'] ?? '')));
    if ($serial === '') throw new RuntimeException('Serial/MAC da ONU não disponível no IXC.');

    $genie = simsGenie();
    $device = simsFindDeviceBySerial($genie, $serial);

    return [
        'genie' => $genie,
        'device' => $device,
        'device_id' => (string)($device['_id'] ?? ''),
        'serial' => $serial,
        'fiber' => $fiber,
    ];
}

function simsGet(array $device, string $path): mixed
{
    $node = $device;
    foreach (explode('.', $path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) return null;
        $node = $node[$part];
    }
    return is_array($node) && array_key_exists('_value', $node) ? $node['_value'] : (is_scalar($node) ? $node : null);
}

try {
    jrSimsAuthorize();

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $idLogin = trim((string)($_GET['id_login'] ?? ''));
        $idContrato = trim((string)($_GET['id_contrato'] ?? ''));

        if ($idLogin === '' && $idContrato === '') {
            simsOut(['success' => false, 'message' => 'Informe id_login ou id_contrato.'], 400);
        }

        $ctx = simsDeviceContext($idLogin, $idContrato);
        $d = $ctx['device'];

        simsOut([
            'success' => true,
            'device' => [
                'serial' => $ctx['serial'],
                'model' => simsGet($d, '_deviceId._ProductClass')
                    ?? simsGet($d, 'InternetGatewayDevice.DeviceInfo.ProductClass'),
                'manufacturer' => simsGet($d, '_deviceId._Manufacturer')
                    ?? simsGet($d, 'InternetGatewayDevice.DeviceInfo.Manufacturer'),
                'last_inform' => $d['_lastInform'] ?? null,
            ],
            'wifi' => [
                'ssid_24' => simsGet($d, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID')
                    ?? simsGet($d, 'Device.WiFi.SSID.1.SSID'),
                'ssid_5' => simsGet($d, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID')
                    ?? simsGet($d, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID')
                    ?? simsGet($d, 'Device.WiFi.SSID.5.SSID')
                    ?? simsGet($d, 'Device.WiFi.SSID.2.SSID'),
            ],
        ]);
    }

    if (!in_array($method, ['POST', 'PUT'], true)) {
        simsOut(['success' => false, 'message' => 'Método não permitido.'], 405);
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) simsOut(['success' => false, 'message' => 'JSON inválido.'], 400);

    $idLogin = trim((string)($input['id_login'] ?? ''));
    $idContrato = trim((string)($input['id_contrato'] ?? ''));
    $ssid = trim((string)($input['ssid'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $wlanIndex = (int)($input['wlan_index'] ?? 1);

    if ($idLogin === '' && $idContrato === '') {
        simsOut(['success' => false, 'message' => 'Informe id_login ou id_contrato.'], 400);
    }
    if ($ssid === '' || strlen($ssid) > 32) {
        simsOut(['success' => false, 'message' => 'SSID deve ter entre 1 e 32 caracteres.'], 422);
    }
    if (strlen($password) < 8 || strlen($password) > 63) {
        simsOut(['success' => false, 'message' => 'Senha deve ter entre 8 e 63 caracteres.'], 422);
    }
    if ($wlanIndex < 1 || $wlanIndex > 8) {
        simsOut(['success' => false, 'message' => 'wlan_index inválido.'], 422);
    }

    $ctx = simsDeviceContext($idLogin, $idContrato);
    if ($ctx['device_id'] === '') {
        throw new RuntimeException('Device ID não disponível no GenieACS.');
    }

    $result = $ctx['genie']->setWiFiConfig(
        $ctx['device_id'],
        $ssid,
        $password,
        $wlanIndex,
        'WPA2PSK'
    );

    if (empty($result['success'])) {
        simsOut([
            'success' => false,
            'message' => $result['error'] ?? 'Não foi possível alterar o Wi-Fi.',
            'http_code' => $result['http_code'] ?? null,
        ], 502);
    }

    simsOut([
        'success' => true,
        'message' => 'Alteração de Wi-Fi enviada ao equipamento.',
        'device' => [
            'serial' => $ctx['serial'],
            'device_id' => $ctx['device_id'],
        ],
        'wifi' => [
            'ssid' => $ssid,
            'wlan_index' => $wlanIndex,
        ],
        'task' => [
            'http_code' => $result['http_code'] ?? null,
            'status' => (($result['http_code'] ?? 0) === 200) ? 'applied' : 'queued',
        ],
    ]);
} catch (Throwable $e) {
    simsOut([
        'success' => false,
        'message' => $e->getMessage(),
    ], 502);
}
