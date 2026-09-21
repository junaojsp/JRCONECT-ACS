<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

use App\GenieACS;
use App\CPEProfiles;

function cpeDiscoveryOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {
    $deviceId = trim((string)($_GET['device_id'] ?? ''));

    if ($deviceId === '') {
        cpeDiscoveryOut([
            'success' => false,
            'message' => 'Informe device_id para executar a descoberta automática.'
        ], 400);
    }

    if (!isGenieACSConfigured()) {
        cpeDiscoveryOut([
            'success' => false,
            'message' => 'GenieACS não configurado.'
        ], 500);
    }

    $conn = getDBConnection();
    $result = $conn->query(
        "SELECT * FROM genieacs_credentials
         WHERE is_connected = 1
         ORDER BY id DESC
         LIMIT 1"
    );
    $credentials = $result ? $result->fetch_assoc() : null;

    if (!$credentials) {
        cpeDiscoveryOut([
            'success' => false,
            'message' => 'GenieACS não conectado.'
        ], 500);
    }

    $genieacs = new GenieACS(
        $credentials['host'],
        $credentials['port'],
        $credentials['username'],
        $credentials['password']
    );

    $deviceResult = $genieacs->getDevice($deviceId);

    if (!$deviceResult['success'] || empty($deviceResult['data'])) {
        cpeDiscoveryOut([
            'success' => false,
            'message' => 'Equipamento não encontrado.'
        ], 404);
    }

    $device = $deviceResult['data'];
    $report = CPEProfiles::discoveryReport($device);
    $parsed = $genieacs->parseDeviceData($device);

    cpeDiscoveryOut([
        'success' => true,
        'device_id' => $deviceId,
        'serial' => $parsed['serial_number'] ?? null,
        'manufacturer' => $parsed['manufacturer'] ?? null,
        'product_class' => $parsed['product_class'] ?? null,
        'profile' => $report,
        'resolved_values' => [
            'wifi_ssid_24ghz' => $parsed['wifi_ssid_24ghz'] ?? null,
            'wifi_ssid_5ghz' => $parsed['wifi_ssid_5ghz'] ?? null,
            'wifi_channel_24ghz' => $parsed['wifi_channel_24ghz'] ?? null,
            'wifi_channel_5ghz' => $parsed['wifi_channel_5ghz'] ?? null,
            'wifi_enabled_24ghz' => $parsed['wifi_enabled_24ghz'] ?? null,
            'wifi_enabled_5ghz' => $parsed['wifi_enabled_5ghz'] ?? null,
            'wifi_clients_24ghz' => $parsed['wifi_clients_24ghz'] ?? null,
            'wifi_clients_5ghz' => $parsed['wifi_clients_5ghz'] ?? null,
            'pppoe_username' => $parsed['pppoe_username'] ?? null,
            'rx_power' => $parsed['rx_power'] ?? null,
            'temperature' => $parsed['temperature'] ?? null,
        ],
        'note' => 'A descoberta é somente leitura e não retorna senhas ou credenciais do CPE.'
    ]);
} catch (Throwable $e) {
    cpeDiscoveryOut([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}
