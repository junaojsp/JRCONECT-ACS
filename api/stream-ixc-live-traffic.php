<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/IXCConfig.php';

if (function_exists('requireLogin')) requireLogin();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@set_time_limit(70);
ignore_user_abort(false);

function streamFlush(): void
{
    while (function_exists('ob_get_level') && ob_get_level() > 0) @ob_end_flush();
    @flush();
}

function streamEvent(string $event, array $payload): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    streamFlush();
}

function parseUpstreamEvent(string $block): ?array
{
    $type = '';
    $data = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($block)) ?: [] as $line) {
        if ($line === '' || str_starts_with($line, ':')) continue;
        [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
        $field = trim($field);
        $value = ltrim($value);
        if ($field === 'event') $type = $value;
        elseif ($field === 'data') $data[] = $value;
    }
    if (!$data) return null;
    return ['event' => $type, 'data' => implode("\n", $data)];
}

$loginId = trim((string)($_GET['login_id'] ?? ''));
if ($loginId === '' || !preg_match('/^\d+$/', $loginId)) {
    streamEvent('error', ['success' => false, 'message' => 'login_id IXC invalido.']);
    exit;
}

try {
    $cfg = getIxcConfig();
    $url = rtrim((string)$cfg['base_url'], '/') . '/aplicativo/radusuarios/rel_22021.php?trafego=' . rawurlencode($loginId);
    $token = (string)$cfg['token'];
    $started = microtime(true);
    $lastHeartbeat = 0.0;
    $buffer = '';
    $sent = 0;

    echo "retry: 1500\n";
    echo ": jrconect ixcsse start\n\n";
    streamFlush();

    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('curl_init_failed');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: text/event-stream',
            'Cache-Control: no-cache',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
            'X-Requested-With: XMLHttpRequest',
        ],
        CURLOPT_WRITEFUNCTION => static function($ch, string $chunk) use (&$buffer, &$sent, $loginId): int {
            $buffer .= $chunk;
            while (preg_match('/^(.*?)(?:\r\n\r\n|\n\n|\r\r)/s', $buffer, $m)) {
                $block = $m[1];
                $buffer = substr($buffer, strlen($m[0]));
                $event = parseUpstreamEvent($block);
                if (!$event) continue;
                $json = json_decode($event['data'], true);
                if (!is_array($json) || strtolower((string)$event['event']) !== 'trafego') continue;
                if (!isset($json['rx'], $json['tx']) || !is_numeric($json['rx']) || !is_numeric($json['tx'])) continue;

                $rxBps = max(0.0, (float)$json['rx']);
                $txBps = max(0.0, (float)$json['tx']);
                streamEvent('traffic', [
                    'success' => true,
                    'source' => 'IXC EventSource',
                    'login_id' => (int)$loginId,
                    'download_mbps' => $rxBps / 1000000,
                    'upload_mbps' => $txBps / 1000000,
                    'download_bps' => $rxBps,
                    'upload_bps' => $txBps,
                    'sample_time' => date(DATE_ATOM),
                ]);
                $sent++;
                if (connection_aborted()) return 0;
            }
            if (strlen($buffer) > 65536) $buffer = substr($buffer, -65536);
            return strlen($chunk);
        },
        CURLOPT_NOPROGRESS => false,
        CURLOPT_XFERINFOFUNCTION => static function($ch, float $dltotal, float $dlnow, float $ultotal, float $ulnow) use ($started, &$lastHeartbeat): int {
            $now = microtime(true);
            if ($now - $lastHeartbeat >= 10) {
                echo ': heartbeat ' . time() . "\n\n";
                streamFlush();
                $lastHeartbeat = $now;
            }
            if (connection_aborted() || ($now - $started) >= 55) return 1;
            return 0;
        },
    ]);

    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!connection_aborted()) {
        streamEvent('end', [
            'success' => true,
            'source' => 'IXC EventSource',
            'http_code' => $http,
            'samples' => $sent,
            'reconnect' => true,
            'message' => $sent > 0 ? 'Janela SSE concluida; reconectando.' : ($error !== '' ? $error : 'Stream sem amostras; reconectando.'),
        ]);
    }
} catch (Throwable $e) {
    if (!connection_aborted()) {
        streamEvent('error', ['success' => false, 'message' => 'Falha ao abrir stream IXC.', 'reconnect' => true]);
    }
}
