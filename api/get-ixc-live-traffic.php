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

    // Fonte primária: campos de tráfego do próprio cadastro de Login IXC.
    // O endpoint é consultado a cada poucos segundos e a taxa é calculada
    // pela diferença dos contadores consecutivos.
    $loginRecord = liveIxcListOne(
        $baseUrl,
        $token,
        'radusuarios',
        'radusuarios.id',
        $loginId
    );

    if ($loginRecord) {
        $counterRate = liveRateFromLoginCounters(
            (int)$loginId,
            $loginRecord['download_atual'] ?? null,
            $loginRecord['upload_atual'] ?? null
        );

        if (!empty($counterRate['available'])) {
            liveOut([
                'success' => true,
                'available' => true,
                'source' => 'IXC Login / Concentrador',
                'read_only' => true,
                'login_id' => (int)$loginId,
                'live' => [
                    'download_mbps' => round((float)$counterRate['download_mbps'], 3),
                    'upload_mbps' => round((float)$counterRate['upload_mbps'], 3),
                    'sample_time' => date(DATE_ATOM),
                    'interval_seconds' => $counterRate['interval_seconds'] ?? null,
                ],
                'counters' => [
                    'download_bytes' => $counterRate['download_bytes'] ?? null,
                    'upload_bytes' => $counterRate['upload_bytes'] ?? null,
                ],
                'network' => [
                    'online' => $loginRecord['online'] ?? null,
                    'ipv4' => $loginRecord['ip'] ?? $loginRecord['ip_aux'] ?? null,
                    'ipv6' => $loginRecord['framed_pd_ipv6'] ?? $loginRecord['pd_ipv6'] ?? null,
                    'concentrator_id' => $loginRecord['id_concentrador'] ?? null,
                    'interface' => $loginRecord['conexao'] ?? $loginRecord['interface'] ?? null,
                    'auth_type' => $loginRecord['autenticacao'] ?? null,
                    'technology' => $loginRecord['tipo_conexao'] ?? $loginRecord['tipo_conexao_mapa'] ?? null,
                ],
            ]);
        }

        if (in_array(
            $counterRate['reason'] ?? null,
            ['collecting_second_sample', 'counter_window_invalid'],
            true
        )) {
            liveOut([
                'success' => true,
                'available' => false,
                'source' => 'IXC Login / Concentrador',
                'read_only' => true,
                'login_id' => (int)$loginId,
                'reason' => $counterRate['reason'],
                'message' => $counterRate['reason'] === 'collecting_second_sample'
                    ? 'Primeira amostra IXC recebida; aguardando a segunda.'
                    : 'Janela de medição reiniciada; aguardando nova amostra.',
                'counters' => [
                    'download_bytes' => $counterRate['download_bytes'] ?? null,
                    'upload_bytes' => $counterRate['upload_bytes'] ?? null,
                ],
            ]);
        }
    }

    // Fallback: rota observada no diagnóstico do próprio IXC.
    // Somente leitura; nenhum comando é enviado ao concentrador.
    $url = $baseUrl . '/aplicativo/radusuarios/rel_22021.php?trafego=' . rawurlencode($loginId);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Não foi possível iniciar a consulta ao diagnóstico IXC.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json,text/html;q=0.9,*/*;q=0.8',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
            'X-Requested-With: XMLHttpRequest',
        ],
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $redirectUrl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body)) {
        throw new RuntimeException($error !== '' ? $error : 'Resposta vazia do IXC.');
    }

    $trimmed = ltrim($body);
    $json = null;
    if (
        str_contains(strtolower($contentType), 'application/json') ||
        str_starts_with($trimmed, '{') ||
        str_starts_with($trimmed, '[')
    ) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) $json = $decoded;
    }

    $download = null;
    $upload = null;
    $paths = ['download' => null, 'upload' => null];
    $jsonKeys = [];

    if (is_array($json)) {
        $rates = liveExtractJsonRates($json);
        $download = $rates['download_mbps'];
        $upload = $rates['upload_mbps'];
        $paths = [
            'download' => $rates['download_path'],
            'upload' => $rates['upload_path'],
        ];
        $jsonKeys = $rates['keys'];
    } else {
        $download = liveExtractHtmlRate($body, 'download')
            ?? liveExtractHtmlRate($body, 'rx');
        $upload = liveExtractHtmlRate($body, 'upload')
            ?? liveExtractHtmlRate($body, 'tx');
    }

    $looksLikeLogin = (bool)preg_match(
        '/(?:login\.php|name=["\'](?:login|usuario|user)["\']|senha|password)/i',
        $body
    );

    $available =
        $http >= 200 &&
        $http < 300 &&
        $download !== null &&
        $upload !== null;

    liveOut([
        'success' => true,
        'available' => $available,
        'source' => 'IXC Diagnóstico / Concentrador',
        'read_only' => true,
        'login_id' => (int)$loginId,
        'live' => $available ? [
            'download_mbps' => round((float)$download, 3),
            'upload_mbps' => round((float)$upload, 3),
            'sample_time' => date(DATE_ATOM),
        ] : null,
        'reason' => $available
            ? null
            : ($looksLikeLogin || $http === 401 || $http === 403 || $redirectUrl !== ''
                ? 'web_session_required'
                : 'live_format_not_identified'),
        'diagnostic' => [
            'login_counter_reason' => $counterRate['reason'] ?? null,
            'login_counter_download' => $counterRate['download_bytes'] ?? null,
            'login_counter_upload' => $counterRate['upload_bytes'] ?? null,
            'http_code' => $http,
            'content_type' => $contentType !== '' ? $contentType : null,
            'content_length' => strlen($body),
            'page_title' => liveSafeTitle($body),
            'redirect_detected' => $redirectUrl !== '',
            'looks_like_login' => $looksLikeLogin,
            'json_keys' => $jsonKeys,
            'matched_paths' => $paths,
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
