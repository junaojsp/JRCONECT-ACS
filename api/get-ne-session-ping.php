<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/ConcentratorConfig.php';

use phpseclib3\Net\SSH2;

if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');

function nePingOut(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function nePingPrompt(): string {
    return '/(?:<[^<>\\r\\n]+>|\\[[^\\[\\]\\r\\n]+\\])\\s*$/';
}
function nePingRun(SSH2 $ssh, string $ip): string {
    $command = 'ping -c 3 ' . $ip;
    $ssh->setTimeout(15);
    $ssh->write($command . "\r\n");
    $output = $ssh->read(nePingPrompt(), SSH2::READ_REGEX);
    if (!is_string($output) || trim($output) === '') {
        throw new RuntimeException('O NE8000 não respondeu ao teste de ping.', 502);
    }
    return $output;
}
function nePingParse(string $output, string $ip): array {
    preg_match_all('/time[=<]\s*([0-9.]+)\s*ms/i', $output, $times);
    $values = array_map('floatval', $times[1] ?? []);
    preg_match('/(\d+(?:\.\d+)?)%\s*(?:packet\s*)?loss/i', $output, $loss);
    preg_match('/(\d+)\s+packet\(s\)\s+transmitted/i', $output, $sent);
    preg_match('/(\d+)\s+packet\(s\)\s+received/i', $output, $received);
    $count = count($values);
    $transmitted = isset($sent[1]) ? (int)$sent[1] : 3;
    $receivedPackets = isset($received[1]) ? (int)$received[1] : $count;
    $lossPercent = isset($loss[1])
        ? (float)$loss[1]
        : ($transmitted > 0 ? max(0, 100 - ($receivedPackets / $transmitted * 100)) : 100.0);

    return [
        'available' => $count > 0,
        'target' => $ip,
        'transmitted' => $transmitted,
        'received' => $receivedPackets,
        'loss_percent' => round($lossPercent, 2),
        'min_ms' => $count ? round(min($values), 3) : null,
        'avg_ms' => $count ? round(array_sum($values) / $count, 3) : null,
        'max_ms' => $count ? round(max($values), 3) : null,
        'sample_count' => $count,
    ];
}

try {
    $ip = trim((string)($_GET['ip'] ?? ''));
    $nasIp = trim((string)($_GET['nas_ip'] ?? ''));
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new RuntimeException('IP PPPoE inválido.', 400);
    }

    $conn = getDBConnection();
    $concentrator = getConcentratorForNas($conn, $nasIp !== '' ? $nasIp : null, true);
    if (!$concentrator) throw new RuntimeException('Concentrador ativo não localizado para a sessão.', 404);
    if (!class_exists(SSH2::class)) throw new RuntimeException('Biblioteca SSH indisponível.', 503);

    $ssh = new SSH2((string)$concentrator['host'], (int)$concentrator['port'], 8);
    if (!$ssh->login((string)$concentrator['username'], (string)$concentrator['password'])) {
        throw new RuntimeException('O concentrador recusou a autenticação SSH.', 502);
    }
    $expected = trim((string)($concentrator['server_fingerprint'] ?? ''));
    $fingerprint = concentratorFingerprint($ssh);
    if ($expected !== '' && $fingerprint !== null && !hash_equals($expected, $fingerprint)) {
        throw new RuntimeException('A chave SSH do concentrador não corresponde à chave salva.', 502);
    }

    $ssh->setWindowSize(220, 100);
    $ssh->setTimeout(8);
    $ssh->read(nePingPrompt(), SSH2::READ_REGEX);
    try {
        $ssh->write("screen-length 0 temporary\r\n");
        $ssh->setTimeout(8);
        $ssh->read(nePingPrompt(), SSH2::READ_REGEX);
    } catch (Throwable) {
    }

    $result = nePingParse(nePingRun($ssh, $ip), $ip);
    nePingOut([
        'success' => true,
        'source' => 'Huawei NE8000 / SSH',
        'concentrator' => [
            'id' => $concentrator['id'],
            'name' => $concentrator['name'],
            'model' => $concentrator['model'],
            'nas_ip' => $concentrator['nas_ip'] ?? $concentrator['host'],
        ],
        'result' => $result,
    ]);
} catch (Throwable $e) {
    $status = $e->getCode();
    nePingOut([
        'success' => false,
        'message' => $e->getMessage(),
    ], $status >= 400 && $status < 600 ? $status : 502);
}
