<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$deviceId = $_GET['device_id'] ?? '';
if ($deviceId === '') {
    jsonResponse(['success' => false, 'message' => 'ID do equipamento é obrigatório.'], 400);
}
if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS não está configurado.'], 503);
}

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result ? $result->fetch_assoc() : null;
if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'Não foi possível acessar o GenieACS.'], 503);
}

use App\GenieACS;
$genieacs = new GenieACS($credentials['host'], $credentials['port'], $credentials['username'], $credentials['password']);
$device = $genieacs->getDevice($deviceId);
if (!$device['success']) {
    jsonResponse(['success' => false, 'message' => 'Equipamento não encontrado no GenieACS.'], 404);
}

$parameters = [];
$walk = function ($node, $path = '') use (&$walk, &$parameters) {
    if (!is_array($node)) return;
    if (array_key_exists('_value', $node)) {
        $isSensitive = preg_match('/(password|passphrase|presharedkey|secret|key)$/i', $path) === 1;
        $parameters[] = [
            'path' => $path,
            'value' => $isSensitive ? '[oculto]' : $node['_value'],
            'timestamp' => $node['_timestamp'] ?? null,
        ];
        return;
    }
    foreach ($node as $key => $value) {
        if (str_starts_with((string)$key, '_')) continue;
        $walk($value, $path === '' ? $key : $path . '.' . $key);
    }
};

foreach (['InternetGatewayDevice', 'Device', 'VirtualParameters'] as $root) {
    if (isset($device['data'][$root])) $walk([$root => $device['data'][$root]]);
}
usort($parameters, fn($a, $b) => strcmp($a['path'], $b['path']));
jsonResponse([
    'success' => true,
    'device_id' => $deviceId,
    'parameter_count' => count($parameters),
    'parameters' => $parameters,
]);