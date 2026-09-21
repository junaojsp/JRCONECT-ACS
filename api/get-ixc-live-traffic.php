<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
if (function_exists('requireLogin')) requireLogin();

header('Content-Type: application/json; charset=utf-8');

function liveOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function liveConfig(): array
{
    $source = (string)file_get_contents(__DIR__ . '/get-onu-optical.php');

    if (!preg_match('/\\$ixcBaseUrl\\s*=\\s*\'([^\']+)\'/', $source, $urlMatch)) {
        throw new RuntimeException('URL IXC não localizada.');
    }
    if (!preg_match('/\\$ixcToken\\s*=\\s*\'([^\']+)\'/', $source, $tokenMatch)) {
        throw new RuntimeException('Token IXC não localizado.');
    }

    return [rtrim($urlMatch[1], '/'), $tokenMatch[1]];
}

function liveIxcListOne(
    string $baseUrl,
    string $token,
    string $table,
    string $qtype,
    string $query
): array {
    $url = rtrim($baseUrl, '/') . '/webservice/v1/' . rawurlencode($table);
    $payload = json_encode([
        'qtype' => $qtype,
        'query' => $query,
        'oper' => '=',
        'page' => '1',
        'rp' => '1',
        'sortname' => $table . '.id',
        'sortorder' => 'desc',
    ]);

    $ch = curl_init($url);
    if ($ch === false) return [];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $http < 200 || $http >= 300) return [];
    $json = json_decode($body, true);
    if (!is_array($json)) return [];
    $rows = $json['registros'] ?? $json['records'] ?? (array_is_list($json) ? $json : []);
    return is_array($rows) && !empty($rows[0]) && is_array($rows[0]) ? $rows[0] : [];
}

function liveCounterBytes(mixed $value): ?float
{
    if ($value === null || $value === '') return null;

    if (is_int($value) || is_float($value)) {
        $n = (float)$value;
        return is_finite($n) && $n >= 0 ? $n : null;
    }

    $text = trim(str_replace(',', '.', (string)$value));
    if ($text === '') return null;
    if (!preg_match('/([0-9]+(?:\.[0-9]+)?)/', $text, $m)) return null;
    $n = (float)$m[1];
    if (!is_finite($n) || $n < 0) return null;

    if (preg_match('/\b(TB|GB|MB|KB|B)\b/i', $text, $u)) {
        return match (strtoupper($u[1])) {
            'TB' => $n * 1024 ** 4,
            'GB' => $n * 1024 ** 3,
            'MB' => $n * 1024 ** 2,
            'KB' => $n * 1024,
            default => $n,
        };
    }

    // O IXC normalmente expõe esses contadores como octetos.
    return $n;
}

function liveCacheFile(int $loginId): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'jrconect_ixc_live_' . $loginId . '.json';
}

function liveRateFromLoginCounters(int $loginId, mixed $downloadRaw, mixed $uploadRaw): array
{
    $download = liveCounterBytes($downloadRaw);
    $upload = liveCounterBytes($uploadRaw);
    $now = microtime(true);

    if ($download === null || $upload === null) {
        return [
            'available' => false,
            'reason' => 'ixc_live_counters_missing',
            'download_bytes' => $download,
            'upload_bytes' => $upload,
        ];
    }

    $file = liveCacheFile($loginId);
    $previous = null;
    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) $previous = $decoded;
    }

    @file_put_contents($file, json_encode([
        'at' => $now,
        'download' => $download,
        'upload' => $upload,
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);

    if (!$previous) {
        return [
            'available' => false,
            'reason' => 'collecting_second_sample',
            'download_bytes' => $download,
            'upload_bytes' => $upload,
        ];
    }

    $dt = $now - (float)($previous['at'] ?? 0);
    $prevDown = (float)($previous['download'] ?? $download);
    $prevUp = (float)($previous['upload'] ?? $upload);

    if ($dt < 0.8 || $dt > 30 || $download < $prevDown || $upload < $prevUp) {
        return [
            'available' => false,
            'reason' => 'counter_window_invalid',
            'download_bytes' => $download,
            'upload_bytes' => $upload,
        ];
    }

    if ($download === $prevDown && $upload === $prevUp) {
        return [
            'available' => true,
            'reason' => null,
            'download_mbps' => 0.0,
            'upload_mbps' => 0.0,
            'download_bytes' => $download,
            'upload_bytes' => $upload,
            'interval_seconds' => round($dt, 3),
        ];
    }

    return [
        'available' => true,
        'reason' => null,
        'download_mbps' => (($download - $prevDown) * 8) / $dt / 1000000,
        'upload_mbps' => (($upload - $prevUp) * 8) / $dt / 1000000,
        'download_bytes' => $download,
        'upload_bytes' => $upload,
        'interval_seconds' => round($dt, 3),
    ];
}


function liveRateToMbps(mixed $value, ?string $unit = null): ?float
{
    if (is_int($value) || is_float($value)) {
        $number = (float)$value;
    } elseif (is_string($value)) {
        $text = trim(str_replace(',', '.', $value));
        if (!preg_match('/-?\d+(?:\.\d+)?/', $text, $m)) return null;
        $number = (float)$m[0];

        if ($unit === null && preg_match('/\b(Gbps|Mbps|Kbps|bps)\b/i', $text, $u)) {
            $unit = $u[1];
        }
    } else {
        return null;
    }

    if (!is_finite($number) || $number < 0) return null;

    $unit = strtolower((string)$unit);
    return match ($unit) {
        'gbps' => $number * 1000,
        'kbps' => $number / 1000,
        'bps' => $number / 1000000,
        default => $number, // sem unidade explícita: assume Mbps
    };
}

function liveFlattenKeys(mixed $data, string $prefix = '', int $depth = 0): array
{
    if (!is_array($data) || $depth > 8) return [];
    $out = [];

    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
        if (is_array($value)) {
            $out += liveFlattenKeys($value, $path, $depth + 1);
        } else {
            $out[$path] = $value;
        }
    }

    return $out;
}

function liveExtractJsonRates(array $json): array
{
    $flat = liveFlattenKeys($json);

    $downloadPatterns = [
        '/(^|\.)(download_mbps|download|down_mbps|down|rx_mbps|rx|trafego_download|taxa_download|rate_down)$/i',
    ];
    $uploadPatterns = [
        '/(^|\.)(upload_mbps|upload|up_mbps|up|tx_mbps|tx|trafego_upload|taxa_upload|rate_up)$/i',
    ];

    $find = static function(array $patterns) use ($flat): array {
        foreach ($flat as $path => $value) {
            foreach ($patterns as $pattern) {
                if (!preg_match($pattern, $path)) continue;
                $rate = liveRateToMbps($value);
                if ($rate !== null) {
                    return ['value' => $rate, 'path' => $path];
                }
            }
        }
        return ['value' => null, 'path' => null];
    };

    $down = $find($downloadPatterns);
    $up = $find($uploadPatterns);

    return [
        'download_mbps' => $down['value'],
        'upload_mbps' => $up['value'],
        'download_path' => $down['path'],
        'upload_path' => $up['path'],
        'keys' => array_slice(array_keys($flat), 0, 80),
    ];
}

function liveExtractHtmlRate(string $body, string $label): ?float
{
    $patterns = [
        '/\b' . preg_quote($label, '/') . '\b.{0,120}?([0-9]+(?:[.,][0-9]+)?)\s*(Gbps|Mbps|Kbps|bps)/is',
        '/["\']' . preg_quote($label, '/') . '[^"\']*["\']\s*[:=]\s*["\']?([0-9]+(?:[.,][0-9]+)?)(?:\s*(Gbps|Mbps|Kbps|bps))?/is',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $body, $m)) continue;
        return liveRateToMbps($m[1], $m[2] ?? null);
    }

    return null;
}

function liveParseSseEvents(string $body): array
{
    $events = [];
    $current = [];

    foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
        if ($line === '') {
            if ($current) {
                $events[] = $current;
                $current = [];
            }
            continue;
        }

        if (str_starts_with($line, ':')) {
            continue;
        }

        $parts = explode(':', $line, 2);
        $field = trim($parts[0] ?? '');
        $value = isset($parts[1]) ? ltrim($parts[1]) : '';

        if ($field === 'data') {
            $current['data'] = isset($current['data'])
                ? $current['data'] . "\n" . $value
                : $value;
        } elseif ($field !== '') {
            $current[$field] = $value;
        }
    }

    if ($current) $events[] = $current;
    return $events;
}

function liveExtractRatesFromSseData(string $data): array
{
    $trimmed = trim($data);
    if ($trimmed === '') {
        return ['download_mbps' => null, 'upload_mbps' => null, 'shape' => 'empty'];
    }

    $json = json_decode($trimmed, true);
    if (is_array($json)) {
        $rates = liveExtractJsonRates($json);
        return [
            'download_mbps' => $rates['download_mbps'],
            'upload_mbps' => $rates['upload_mbps'],
            'shape' => 'json',
            'keys' => $rates['keys'] ?? [],
            'paths' => [
                'download' => $rates['download_path'] ?? null,
                'upload' => $rates['upload_path'] ?? null,
            ],
        ];
    }

    $download = liveExtractHtmlRate($trimmed, 'download')
        ?? liveExtractHtmlRate($trimmed, 'rx');
    $upload = liveExtractHtmlRate($trimmed, 'upload')
        ?? liveExtractHtmlRate($trimmed, 'tx');

    if ($download !== null || $upload !== null) {
        return [
            'download_mbps' => $download,
            'upload_mbps' => $upload,
            'shape' => 'labeled-text',
        ];
    }

    // Fallback para payloads simples como "12345;678" ou "12345,678".
    // Se não houver unidade explícita, interpreta os números como bits/s.
    if (preg_match_all('/-?\d+(?:[.,]\d+)?/', $trimmed, $m) && count($m[0]) >= 2) {
        $a = (float)str_replace(',', '.', $m[0][0]);
        $b = (float)str_replace(',', '.', $m[0][1]);

        if ($a >= 0 && $b >= 0) {
            return [
                'download_mbps' => $a / 1000000,
                'upload_mbps' => $b / 1000000,
                'shape' => 'numeric-pair-bps',
            ];
        }
    }

    return [
        'download_mbps' => null,
        'upload_mbps' => null,
        'shape' => 'unknown',
    ];
}

function liveReadSseSample(
    string $url,
    string $token,
    int $seconds = 4
): array {
    $buffer = '';
    $headers = [];
    $started = microtime(true);
    $ch = curl_init($url);

    if ($ch === false) {
        return ['http_code' => 0, 'body' => '', 'headers' => [], 'error' => 'curl_init_failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => max(2, $seconds + 2),
        CURLOPT_HTTPHEADER => [
            'Accept: text/event-stream',
            'Cache-Control: no-cache',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
            'X-Requested-With: XMLHttpRequest',
        ],
        CURLOPT_HEADERFUNCTION => static function($ch, string $line) use (&$headers): int {
            $headers[] = trim($line);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function($ch, string $chunk) use (&$buffer, $started, $seconds): int {
            $buffer .= $chunk;

            // Evita respostas enormes: bastam alguns eventos recentes.
            if (strlen($buffer) > 32768) {
                $buffer = substr($buffer, -32768);
            }

            if ((microtime(true) - $started) >= $seconds) {
                return 0; // encerra após a janela de amostragem
            }

            return strlen($chunk);
        },
    ]);

    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $http,
        'content_type' => $contentType,
        'redirect_url' => $redirect,
        'body' => $buffer,
        'headers' => $headers,
        'error' => $error,
    ];
}

function liveSafeTitle(string $body): ?string
{
    if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) return null;
    $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $title !== '' ? mb_substr($title, 0, 120) : null;
}

try {
    $loginId = trim((string)($_GET['login_id'] ?? ''));
    if ($loginId === '' || !preg_match('/^\d+$/', $loginId)) {
        liveOut([
            'success' => false,
            'available' => false,
            'message' => 'login_id IXC não informado ou inválido.',
        ], 400);
    }

    [$baseUrl, $token] = liveConfig();

    // Fonte fiel ao IXC: EventSource/SSE do diagnóstico de Login.
    // Captura alguns segundos do stream e usa o evento mais recente.
    $url = $baseUrl . '/aplicativo/radusuarios/rel_22021.php?trafego=' . rawurlencode($loginId);
    $sse = liveReadSseSample($url, $token, 4);

    $events = liveParseSseEvents((string)($sse['body'] ?? ''));
    $lastParsed = null;
    $lastRawData = null;

    foreach ($events as $event) {
        if (!isset($event['data'])) continue;
        $parsed = liveExtractRatesFromSseData((string)$event['data']);
        if (
            $parsed['download_mbps'] !== null ||
            $parsed['upload_mbps'] !== null
        ) {
            $lastParsed = $parsed;
            $lastRawData = (string)$event['data'];
        }
    }

    $download = $lastParsed['download_mbps'] ?? null;
    $upload = $lastParsed['upload_mbps'] ?? null;

    $body = (string)($sse['body'] ?? '');
    $looksLikeLogin = (bool)preg_match(
        '/(?:login\.php|name=["\'](?:login|usuario|user)["\']|senha|password)/i',
        $body
    );

    $available =
        ($sse['http_code'] ?? 0) >= 200 &&
        ($sse['http_code'] ?? 0) < 300 &&
        $download !== null &&
        $upload !== null;

    // Fallback secundário: contadores do cadastro Login IXC.
    // Só é usado quando o EventSource não pôde ser interpretado.
    $loginRecord = liveIxcListOne(
        $baseUrl,
        $token,
        'radusuarios',
        'radusuarios.id',
        $loginId
    );
    $counterRate = [];
    if ($loginRecord) {
        $counterRate = liveRateFromLoginCounters(
            (int)$loginId,
            $loginRecord['download_atual'] ?? null,
            $loginRecord['upload_atual'] ?? null
        );
    }

    if (!$available && !empty($counterRate['available'])) {
        $counterDown = (float)($counterRate['download_mbps'] ?? 0.0);
        $counterUp = (float)($counterRate['upload_mbps'] ?? 0.0);

        // Contador parado não deve se passar por tráfego "ao vivo".
        // Só usa esse fallback se houver movimento mensurável.
        if ($counterDown > 0.0001 || $counterUp > 0.0001) {
            liveOut([
                'success' => true,
                'available' => true,
                'source' => 'IXC Login counters fallback',
                'read_only' => true,
                'login_id' => (int)$loginId,
                'live' => [
                    'download_mbps' => round($counterDown, 3),
                    'upload_mbps' => round($counterUp, 3),
                    'sample_time' => date(DATE_ATOM),
                    'transport' => 'counter-delta',
                ],
                'diagnostic' => [
                    'transport' => 'counter-delta',
                    'sse_http_code' => (int)($sse['http_code'] ?? 0),
                    'sse_event_count' => count($events),
                    'sse_last_event_shape' => $lastParsed['shape'] ?? null,
                    'sse_content_type' => ($sse['content_type'] ?? '') !== '' ? $sse['content_type'] : null,
                ],
            ]);
        }
    }

    liveOut([
        'success' => true,
        'available' => $available,
        'source' => $available
            ? 'IXC EventSource / Concentrador'
            : 'IXC Diagnóstico / Concentrador',
        'read_only' => true,
        'login_id' => (int)$loginId,
        'live' => $available ? [
            'download_mbps' => round((float)$download, 3),
            'upload_mbps' => round((float)$upload, 3),
            'sample_time' => date(DATE_ATOM),
            'transport' => 'sse',
        ] : null,
        'reason' => $available
            ? null
            : ($looksLikeLogin || in_array((int)($sse['http_code'] ?? 0), [401, 403], true) || ($sse['redirect_url'] ?? '') !== ''
                ? 'web_session_required'
                : 'sse_payload_not_identified'),
        'diagnostic' => [
            'transport' => 'eventsource',
            'login_counter_reason' => $counterRate['reason'] ?? null,
            'login_counter_download' => $counterRate['download_bytes'] ?? null,
            'login_counter_upload' => $counterRate['upload_bytes'] ?? null,
            'http_code' => (int)($sse['http_code'] ?? 0),
            'content_type' => ($sse['content_type'] ?? '') !== '' ? $sse['content_type'] : null,
            'content_length' => strlen($body),
            'redirect_detected' => ($sse['redirect_url'] ?? '') !== '',
            'looks_like_login' => $looksLikeLogin,
            'event_count' => count($events),
            'last_event_shape' => $lastParsed['shape'] ?? null,
            'last_event_keys' => $lastParsed['keys'] ?? [],
            'matched_paths' => $lastParsed['paths'] ?? [],
            'event_data_preview' => $lastRawData !== null
                ? mb_substr(preg_replace('/[^\x20-\x7E\r\n\t]/', '', $lastRawData) ?? '', 0, 500)
                : null,
            'curl_error' => ($sse['error'] ?? '') !== '' ? $sse['error'] : null,
        ],
    ]);

} catch (Throwable $e) {
    liveOut([
        'success' => false,
        'available' => false,
        'source' => 'IXC Diagnóstico / Concentrador',
        'read_only' => true,
        'message' => 'Falha ao consultar o tráfego ao vivo do IXC.',
    ], 502);
}
