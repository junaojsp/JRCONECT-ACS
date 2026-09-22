<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');

function pingOut(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function pingProbe(string $ip): array {
    $binary = is_executable('/bin/ping') ? '/bin/ping' : (is_executable('/usr/bin/ping') ? '/usr/bin/ping' : null);
    if ($binary === null) return ['available' => false, 'reason' => 'ping_binary_missing'];
    $lines = [];
    $code = 1;
    exec($binary . ' -n -c 3 -W 1 ' . escapeshellarg($ip) . ' 2>&1', $lines, $code);
    $output = implode("
", $lines);
    preg_match('/(\d+)\s+packets transmitted,\s+(\d+).+?(\d+(?:\.\d+)?)%\s+packet loss/i', $output, $packets);
    preg_match('/(?:rtt|round-trip).*?=\s*([0-9.]+)\/([0-9.]+)\/([0-9.]+)(?:\/([0-9.]+))?\s*ms/i', $output, $rtt);
    return [
        'available' => !empty($packets) && (int)$packets[2] > 0,
        'target' => $ip,
        'transmitted' => isset($packets[1]) ? (int)$packets[1] : 0,
        'received' => isset($packets[2]) ? (int)$packets[2] : 0,
        'loss_percent' => isset($packets[3]) ? (float)$packets[3] : 100.0,
        'min_ms' => isset($rtt[1]) ? (float)$rtt[1] : null,
        'avg_ms' => isset($rtt[2]) ? (float)$rtt[2] : null,
        'max_ms' => isset($rtt[3]) ? (float)$rtt[3] : null,
        'mdev_ms' => isset($rtt[4]) ? (float)$rtt[4] : null,
        'reason' => $code === 0 ? null : 'target_unreachable',
    ];
}
try {
    $clientIp = trim((string)($_GET['client_ip'] ?? ''));
    $concentratorIp = trim((string)($_GET['concentrator_ip'] ?? ''));
    foreach ([$clientIp, $concentratorIp] as $ip) {
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Endereço IP inválido.', 400);
        }
    }
    if ($clientIp === '' && $concentratorIp === '') throw new RuntimeException('Nenhum destino informado.', 400);
    $results = [];
    if ($clientIp !== '') $results['client'] = pingProbe($clientIp);
    if ($concentratorIp !== '') $results['concentrator'] = pingProbe($concentratorIp);
    pingOut(['success' => true, 'source' => 'Servidor ACS', 'results' => $results]);
} catch (Throwable $e) {
    $code = $e->getCode();
    pingOut(['success' => false, 'message' => $e->getMessage()], $code >= 400 && $code < 600 ? $code : 502);
}
