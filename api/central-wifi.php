<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use App\GenieACS;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function centralWifiOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function centralWifiBearer(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $match)) {
        return '';
    }
    return trim($match[1]);
}

function centralWifiNormalizeSerial(string $value): string
{
    return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $value));
}

function centralWifiGetCredentials(): array
{
    $db = getDBConnection();
    $stmt = $db->prepare(
        "SELECT host, port, username, password
         FROM genieacs_credentials
         WHERE is_connected = 1
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new RuntimeException('GenieACS não está conectado.');
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    return $row;
}

function centralWifiFindDevice(GenieACS $genieacs, string $serial): array
{
    $queries = [
        ['_deviceId._SerialNumber' => $serial],
        ['InternetGatewayDevice.DeviceInfo.SerialNumber' => $serial],
        ['Device.DeviceInfo.SerialNumber' => $serial],
    ];

    foreach ($queries as $query) {
        $result = $genieacs->getDevices($query, 10, 0);

        if (empty($result['success']) || empty($result['data']) || !is_array($result['data'])) {
            continue;
        }

        foreach ($result['data'] as $device) {
            $parsed = $genieacs->parseDeviceData($device);
            $candidate = centralWifiNormalizeSerial((string)($parsed['serial_number'] ?? ''));

            if ($candidate === $serial) {
                return [
                    'raw' => $device,
                    'parsed' => $parsed,
                ];
            }
        }
    }

    throw new RuntimeException('Equipamento não localizado no ACS.');
}

$expectedKey = trim((string)getenv('CENTRAL_WIFI_API_KEY'));

if ($expectedKey === '') {
    centralWifiOut([
        'success' => false,
        'message' => 'CENTRAL_WIFI_API_KEY não configurada no servidor ACS.',
    ], 503);
}

$providedKey = centralWifiBearer();

if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    centralWifiOut([
        'success' => false,
        'message' => 'Não autorizado.',
    ], 401);
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    centralWifiOut([
        'success' => false,
        'message' => 'Método não permitido.',
    ], 405);
}

$input = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        centralWifiOut([
            'success' => false,
            'message' => 'JSON inválido.',
        ], 400);
    }
}

$serial = centralWifiNormalizeSerial(
    (string)($input['serial'] ?? $_GET['serial'] ?? '')
);

if ($serial === '' || strlen($serial) < 6 || strlen($serial) > 64) {
    centralWifiOut([
        'success' => false,
        'message' => 'Serial inválido.',
    ], 422);
}

try {
    $credentials = centralWifiGetCredentials();

    $genieacs = new GenieACS(
        $credentials['host'],
        $credentials['port'],
        $credentials['username'],
        $credentials['password']
    );

    $found = centralWifiFindDevice($genieacs, $serial);
    $device = $found['parsed'];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        centralWifiOut([
            'success' => true,
            'device' => [
                'serial' => (string)($device['serial_number'] ?? $serial),
                'device_id' => (string)($device['device_id'] ?? ''),
                'ssid' => (string)($device['wifi_ssid'] ?? ''),
                'status' => (string)($device['status'] ?? 'offline'),
                'manufacturer' => (string)($device['manufacturer'] ?? ''),
                'model' => (string)($device['product_class'] ?? ''),
                'last_inform' => (string)($device['last_inform'] ?? ''),
            ],
        ]);
    }

    $newSsid = trim((string)($input['ssid'] ?? ''));
    $newPassword = (string)($input['password'] ?? '');
    $wlanIndex = (int)($input['wlan_index'] ?? 1);

    if ($wlanIndex < 1 || $wlanIndex > 4) {
        centralWifiOut([
            'success' => false,
            'message' => 'Índice WLAN inválido.',
        ], 422);
    }

    $currentSsid = trim((string)($device['wifi_ssid'] ?? ''));
    $currentPassword = (string)($device['wifi_password'] ?? '');

    $ssid = $newSsid !== '' ? $newSsid : $currentSsid;
    $password = $newPassword !== '' ? $newPassword : $currentPassword;

    if ($ssid === '' || $ssid === 'N/A' || strlen($ssid) > 32) {
        centralWifiOut([
            'success' => false,
            'message' => 'Informe um nome de rede entre 1 e 32 caracteres.',
        ], 422);
    }

    if ($password === '' || $password === 'N/A' || strlen($password) < 8 || strlen($password) > 63) {
        centralWifiOut([
            'success' => false,
            'message' => 'A senha Wi-Fi deve ter entre 8 e 63 caracteres.',
        ], 422);
    }

    $result = $genieacs->setWiFiConfig(
        (string)$device['device_id'],
        $ssid,
        $password,
        $wlanIndex,
        'WPA2PSK'
    );

    if (empty($result['success'])) {
        centralWifiOut([
            'success' => false,
            'message' => (string)($result['error'] ?? 'Não foi possível atualizar o Wi-Fi.'),
            'http_code' => (int)($result['http_code'] ?? 0),
        ], 502);
    }

    $httpCode = (int)($result['http_code'] ?? 0);
    $message = 'Configuração Wi-Fi enviada ao equipamento.';

    if ($httpCode === 200) {
        $message = 'Nome e senha do Wi-Fi atualizados com sucesso.';
    } elseif ($httpCode === 202) {
        $message = 'Alteração enviada. O roteador aplicará a configuração no próximo contato com o ACS.';
    }

    centralWifiOut([
        'success' => true,
        'message' => $message,
        'device' => [
            'serial' => (string)($device['serial_number'] ?? $serial),
            'ssid' => $ssid,
            'status' => (string)($device['status'] ?? 'offline'),
        ],
        'acs_http_code' => $httpCode,
    ]);
} catch (Throwable $e) {
    centralWifiOut([
        'success' => false,
        'message' => $e->getMessage(),
    ], 502);
}
