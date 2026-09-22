<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../lib/SIMSAuth.php';
require_once __DIR__ . '/../../../lib/SIMSIntegration.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function simsWifiOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    jrSimsAuthorize();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $idLogin = trim((string)($_GET['id_login'] ?? ''));
        $idContrato = trim((string)($_GET['id_contrato'] ?? ''));

        if ($idLogin === '' && $idContrato === '') {
            simsWifiOut(['success' => false, 'message' => 'Informe id_login ou id_contrato.'], 400);
        }

        $ctx = jrSimsDeviceContext($idLogin, $idContrato);
        $d = $ctx['device'];
        $status = jrSimsStatus($d);

        jrSimsAudit('wifi_read', [
            'id_login' => $idLogin,
            'id_contrato' => $idContrato,
            'serial' => $ctx['serial'],
        ]);

        simsWifiOut([
            'success' => true,
            'device' => [
                'serial' => $ctx['serial'],
                'model' => jrSimsGet($d, '_deviceId._ProductClass')
                    ?? jrSimsGet($d, 'InternetGatewayDevice.DeviceInfo.ProductClass'),
                'manufacturer' => jrSimsGet($d, '_deviceId._Manufacturer')
                    ?? jrSimsGet($d, 'InternetGatewayDevice.DeviceInfo.Manufacturer'),
                'online' => $status['online'],
                'last_inform' => $status['last_inform'],
            ],
            'wifi' => [
                'ssid_24' => jrSimsGet($d, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID')
                    ?? jrSimsGet($d, 'Device.WiFi.SSID.1.SSID'),
                'ssid_5' => jrSimsGet($d, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID')
                    ?? jrSimsGet($d, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID')
                    ?? jrSimsGet($d, 'Device.WiFi.SSID.5.SSID')
                    ?? jrSimsGet($d, 'Device.WiFi.SSID.2.SSID'),
            ],
        ]);
    }

    if (!in_array($method, ['POST', 'PUT'], true)) {
        simsWifiOut(['success' => false, 'message' => 'Método não permitido.'], 405);
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        simsWifiOut(['success' => false, 'message' => 'JSON inválido.'], 400);
    }

    $idLogin = trim((string)($input['id_login'] ?? ''));
    $idContrato = trim((string)($input['id_contrato'] ?? ''));
    $ssid = trim((string)($input['ssid'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $band = trim((string)($input['band'] ?? ''));
    $explicitIndex = isset($input['wlan_index']) ? (int)$input['wlan_index'] : null;

    if ($idLogin === '' && $idContrato === '') {
        simsWifiOut(['success' => false, 'message' => 'Informe id_login ou id_contrato.'], 400);
    }
    if ($ssid === '' || strlen($ssid) > 32) {
        simsWifiOut(['success' => false, 'message' => 'SSID deve ter entre 1 e 32 caracteres.'], 422);
    }
    if (strlen($password) < 8 || strlen($password) > 63) {
        simsWifiOut(['success' => false, 'message' => 'Senha deve ter entre 8 e 63 caracteres.'], 422);
    }

    $ctx = jrSimsDeviceContext($idLogin, $idContrato);
    if ($ctx['device_id'] === '') {
        throw new RuntimeException('Device ID não disponível no GenieACS.');
    }

    if ($explicitIndex !== null) {
        if ($explicitIndex < 1 || $explicitIndex > 8) {
            simsWifiOut(['success' => false, 'message' => 'wlan_index inválido.'], 422);
        }
        $indexes = [$explicitIndex];
        $bandLabel = 'index:' . $explicitIndex;
    } else {
        $bandLabel = $band !== '' ? $band : '2.4';
        $indexes = jrSimsBandIndexes($ctx['device'], $bandLabel);
    }

    $results = [];
    $ok = true;
    foreach (array_values(array_unique($indexes)) as $index) {
        $result = $ctx['genie']->setWiFiConfig(
            $ctx['device_id'],
            $ssid,
            $password,
            $index,
            'WPA2PSK'
        );

        $results[] = [
            'wlan_index' => $index,
            'success' => !empty($result['success']),
            'http_code' => $result['http_code'] ?? null,
            'status' => (($result['http_code'] ?? 0) === 200) ? 'applied' : 'queued',
            'error' => $result['error'] ?? null,
        ];

        if (empty($result['success'])) $ok = false;
    }

    jrSimsAudit($ok ? 'wifi_change' : 'wifi_change_failed', [
        'id_login' => $idLogin,
        'id_contrato' => $idContrato,
        'serial' => $ctx['serial'],
        'ssid' => $ssid,
        'band' => $bandLabel,
        'indexes' => $indexes,
        'results' => $results,
    ]);

    if (!$ok) {
        simsWifiOut([
            'success' => false,
            'message' => 'Uma ou mais alterações de Wi-Fi não foram aceitas pelo GenieACS.',
            'results' => $results,
        ], 502);
    }

    simsWifiOut([
        'success' => true,
        'message' => 'Alteração de Wi-Fi enviada ao equipamento.',
        'device' => [
            'serial' => $ctx['serial'],
            'device_id' => $ctx['device_id'],
        ],
        'wifi' => [
            'ssid' => $ssid,
            'band' => $bandLabel,
            'wlan_indexes' => $indexes,
        ],
        'tasks' => $results,
    ]);
} catch (InvalidArgumentException $e) {
    jrSimsAudit('wifi_request_invalid', ['message' => $e->getMessage()]);
    simsWifiOut(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    jrSimsAudit('wifi_error', ['message' => $e->getMessage()]);
    simsWifiOut(['success' => false, 'message' => $e->getMessage()], 502);
}
