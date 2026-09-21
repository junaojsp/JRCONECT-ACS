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

    // Rota observada no diagnóstico do próprio IXC.
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
