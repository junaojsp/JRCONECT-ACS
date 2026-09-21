<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
requireLogin();

use App\GenieACS;

function out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function scalarValue($value) {
    if (is_array($value) && array_key_exists('_value', $value)) return $value['_value'];
    return is_scalar($value) ? $value : null;
}

function walkRelevant($node, string $path = '', array &$out = [], int $depth = 0): void {
    if (!is_array($node) || $depth > 14) return;

    foreach ($node as $key => $value) {
        $keyString = (string)$key;
        $nextPath = $path === '' ? $keyString : $path . '.' . $keyString;

        $lower = strtolower($nextPath);
        $sensitive = preg_match('/password|passphrase|presharedkey|secret|credential|authkey/i', $nextPath);
        $interesting = preg_match('/wifi|wlan|ssid|radio|gpon|pon|optic|rxpower|txpower|receivepower|transmitpower|signal|temperature|voltage|laser|transceiver/i', $nextPath);

        if ($interesting && !$sensitive) {
            $scalar = scalarValue($value);
            if ($scalar !== null && trim((string)$scalar) !== '') {
                $out[$nextPath] = $scalar;
            }
        }

        if (is_array($value) && strpos($keyString, '_') !== 0) {
            walkRelevant($value, $nextPath, $out, $depth + 1);
        }
    }
}

try {
    if (!isGenieACSConfigured()) {
        out(['success' => false, 'message' => 'GenieACS não configurado.'], 500);
    }

    $conn = getDBConnection();
    $result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
    $credentials = $result ? $result->fetch_assoc() : null;
    if (!$credentials) out(['success' => false, 'message' => 'GenieACS não conectado.'], 500);

    $genieacs = new GenieACS(
        $credentials['host'],
        $credentials['port'],
        $credentials['username'],
        $credentials['password']
    );

    $deviceId = trim((string)($_GET['device_id'] ?? ''));
    $devices = [];

    if ($deviceId !== '') {
        $res = $genieacs->getDevice($deviceId);
        if (!$res['success'] || empty($res['data'])) out(['success' => false, 'message' => 'Equipamento não encontrado.'], 404);
        $devices[] = $res['data'];
    } else {
        $res = $genieacs->getDevices([], 500, 0);
        if (!$res['success']) out(['success' => false, 'message' => 'Falha ao consultar GenieACS.'], 502);
        foreach (($res['data'] ?? []) as $device) {
            $model =
                $device['_deviceId']['_ProductClass'] ??
                $device['InternetGatewayDevice']['DeviceInfo']['ProductClass']['_value'] ??
                $device['Device']['DeviceInfo']['ProductClass']['_value'] ??
                '';
            $manufacturer =
                $device['_deviceId']['_Manufacturer'] ??
                $device['InternetGatewayDevice']['DeviceInfo']['Manufacturer']['_value'] ??
                $device['Device']['DeviceInfo']['Manufacturer']['_value'] ??
                '';

            $haystack = strtoupper((string)$manufacturer . ' ' . (string)$model);
            if (str_contains($haystack, 'EG8145V5') || (str_contains($haystack, 'HUAWEI') && str_contains($haystack, 'EG8145'))) {
                $devices[] = $device;
            }
        }
    }

    $output = [];
    foreach ($devices as $device) {
        $params = [];
        walkRelevant($device, '', $params);

        $output[] = [
            'device_id' => $device['_id'] ?? null,
            'serial' => $device['_deviceId']['_SerialNumber'] ?? $device['InternetGatewayDevice']['DeviceInfo']['SerialNumber']['_value'] ?? null,
            'manufacturer' => $device['_deviceId']['_Manufacturer'] ?? $device['InternetGatewayDevice']['DeviceInfo']['Manufacturer']['_value'] ?? null,
            'product_class' => $device['_deviceId']['_ProductClass'] ?? $device['InternetGatewayDevice']['DeviceInfo']['ProductClass']['_value'] ?? null,
            'parameters' => $params,
        ];
    }

    out([
        'success' => true,
        'count' => count($output),
        'devices' => $output,
    ]);
} catch (Throwable $e) {
    out(['success' => false, 'message' => $e->getMessage()], 500);
}
