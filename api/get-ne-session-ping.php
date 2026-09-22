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
    return '/(?:<[^<>\r\n]+>|\[[^\[\]\r\n]+\])\s*$/';
}

function nePingNormalizeOutput(string $output): string {
    $clean = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output);
    if (is_string($clean)) {
        $output = $clean;
    }

    $output = str_replace(["\r", "\x08"], '', $output);
    return trim($output);
}

function nePingHasStatistics(string $output): bool {
    return preg_match(
        '/(?:\d+\s+packet\(s\)\s+transmitted|\d+(?:\.\d+)?\s*%\s*packet(?:\(s\))?\s+loss|round-trip\s+min\/avg\/max\s*=)/i',
        $output
    ) === 1;
}

function nePingRun(SSH2 $ssh, string $ip, int $count): string {
    $command = 'ping -c ' . $count . ' ' . $ip;

    $ssh->setTimeout(15);
    $ssh->write($command . "\r\n");

    $output = '';
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $chunk = $ssh->read(nePingPrompt(), SSH2::READ_REGEX);
        if (is_string($chunk) && $chunk !== '') {
            $output .= $chunk;
        }

        $normalized = nePingNormalizeOutput($output);
        if (nePingHasStatistics($normalized)) {
            break;
        }

        if (!$ssh->isTimeout()) {
            break;
        }

        $ssh->setTimeout(5);
    }

    $output = nePingNormalizeOutput($output);

    if ($output === '') {
        throw new RuntimeException('O NE8000 não respondeu ao teste de ping.', 502);
    }

    if (preg_match(
        '/^(?:Error:|Unrecognized command|Incomplete command|Wrong parameter|Too many parameters|Ambiguous command).*$/mi',
        $output,
        $cliError
    )) {
        throw new RuntimeException(
            'O NE8000 recusou o comando de ping: ' . trim((string)$cliError[0]),
            502
        );
    }

    if (!nePingHasStatistics($output)) {
        $preview = preg_replace('/\s+/', ' ', $output);
        error_log(
            '[NE8000 ping] Resposta sem estatisticas para ' . $ip . ': ' .
            substr((string)$preview, 0, 500)
        );
        throw new RuntimeException('O NE8000 respondeu, mas não retornou estatísticas de ping.', 502);
    }

    return $output;
}

function nePingParse(string $output, string $ip, int $expectedCount): array {
    $output = nePingNormalizeOutput($output);

    preg_match_all('/time\s*[=<]\s*([0-9.]+)\s*ms/i', $output, $times);
    $values = array_map('floatval', $times[1] ?? []);

    preg_match(
        '/(\d+(?:\.\d+)?)\s*%\s*packet(?:\(s\))?\s+loss/i',
        $output,
        $loss
    );
    preg_match('/(\d+)\s+packet\(s\)\s+transmitted/i', $output, $sent);
    preg_match('/(\d+)\s+packet\(s\)\s+received/i', $output, $received);
    preg_match(
        '/round-trip\s+min\/avg\/max\s*=\s*([0-9.]+)\/([0-9.]+)\/([0-9.]+)\s*ms/i',
        $output,
        $summary
    );

    $sampleCount = count($values);
    $transmitted = isset($sent[1]) ? (int)$sent[1] : $expectedCount;
    $receivedPackets = isset($received[1]) ? (int)$received[1] : $sampleCount;

    $lossPercent = isset($loss[1])
        ? (float)$loss[1]
        : ($transmitted > 0
            ? max(0.0, 100.0 - (($receivedPackets / $transmitted) * 100.0))
            : 100.0);

    $minMs = $sampleCount > 0 ? min($values) : (isset($summary[1]) ? (float)$summary[1] : null);
    $avgMs = $sampleCount > 0
        ? array_sum($values) / $sampleCount
        : (isset($summary[2]) ? (float)$summary[2] : null);
    $maxMs = $sampleCount > 0 ? max($values) : (isset($summary[3]) ? (float)$summary[3] : null);

    $available = $receivedPackets > 0 || $avgMs !== null;

    return [
        'available' => $available,
        'target' => $ip,
        'transmitted' => $transmitted,
        'received' => $receivedPackets,
        'loss_percent' => round($lossPercent, 2),
        'min_ms' => $minMs !== null ? round($minMs, 3) : null,
        'avg_ms' => $avgMs !== null ? round($avgMs, 3) : null,
        'max_ms' => $maxMs !== null ? round($maxMs, 3) : null,
        'sample_count' => $receivedPackets > 0 ? $receivedPackets : $sampleCount,
    ];
}

try {
    $ip = trim((string)($_GET['ip'] ?? ''));
    $nasIp = trim((string)($_GET['nas_ip'] ?? ''));
    $count = max(1, min(3, (int)($_GET['count'] ?? 1)));

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new RuntimeException('IP PPPoE inválido.', 400);
    }

    $conn = getDBConnection();
    $concentrator = getConcentratorForNas($conn, $nasIp !== '' ? $nasIp : null, true);
    if (!$concentrator) {
        throw new RuntimeException('Concentrador ativo não localizado para a sessão.', 404);
    }
    if (!class_exists(SSH2::class)) {
        throw new RuntimeException('Biblioteca SSH indisponível.', 503);
    }

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

    $result = nePingParse(nePingRun($ssh, $ip, $count), $ip, $count);

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
