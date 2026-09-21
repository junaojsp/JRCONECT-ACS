<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

$deviceId = $_GET['device_id'] ?? '';

if (empty($deviceId)) {
    jsonResponse(['success' => false, 'message' => 'Device ID required']);
}

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
}

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS tidak terhubung']);
}

use App\GenieACS;

$genieacs = new GenieACS(
    $credentials['host'],
    $credentials['port'],
    $credentials['username'],
    $credentials['password']
);

$deviceResult = $genieacs->getDevice($deviceId);

if ($deviceResult['success']) {
    $parsed = $genieacs->parseDeviceData($deviceResult['data']);

    // Política de fontes JR CONECT:
    // - IXC: dados oficiais de cliente/rede/OLT/PPPoE/VLAN/óptico.
    // - TR-069: estado e gerenciamento do CPE (Wi-Fi, LAN, firmware, uptime).
    // Este endpoint entrega apenas a camada CPE; o front complementa rede/cliente via IXC.
    $parsed['source_policy'] = [
        'equipment' => 'TR-069 / GenieACS',
        'wifi_read' => 'TR-069 / GenieACS',
        'wifi_write' => 'TR-069 / GenieACS',
        'lan' => 'TR-069 / GenieACS',
        'firmware' => 'TR-069 / GenieACS',
        'network' => 'IXC principal / TR-069 fallback',
        'optical' => 'IXC principal / TR-069 fallback',
        'customer' => 'IXC',
    ];

    jsonResponse(['success' => true, 'device' => $parsed]);
} else {
    jsonResponse(['success' => false, 'message' => 'Device tidak ditemukan']);
}
