<?php

declare(strict_types=1);

/*
 * Verifica se a credencial de integração IXC pode abrir o stream oficial
 * de tráfego em tempo real. Não expõe tokens, cookies ou cabeçalhos sensíveis.
 */
require_once __DIR__ . '/../config/config.php';
if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');

function trafficProbeOut(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $loginId = trim((string)($_GET['login_id'] ?? ''));
    if (!preg_match('/^\d+$/', $loginId)) {
        trafficProbeOut(['success' => false, 'message' => 'login_id numérico não informado.'], 400);
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extensão PHP cURL não está disponível.');
    }

    /* Reaproveita a configuração IXC já existente, sem duplicar segredos. */
    $opticalSource = (string)file_get_contents(__DIR__ . '/get-onu-optical.php');
    if (!preg_match('/\$ixcToken\s*=\s*\'([^\']+)\'/', $opticalSource, $tokenMatch)) {
        throw new RuntimeException('Token IXC não localizado na configuração existente.');
    }
    if (!preg_match('/\$ixcBaseUrl\s*=\s*\'([^\']+)\'/', $opticalSource, $urlMatch)) {
        throw new RuntimeException('URL IXC não localizada na configuração existente.');
    }

    $url = rtrim($urlMatch[1], '/') . '/aplicativo/radusuarios/rel_22021.php?trafego=' . rawurlencode($loginId);
    $headers = [];
    $preview = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Accept: text/event-stream',
            'Cache-Control: no-cache',
            'Authorization: Basic ' . base64_encode($tokenMatch[1]),
        ],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$headers): int {
            $headers[] = trim($header);
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$preview): int {
            if (strlen($preview) < 4096) $preview .= substr($chunk, 0, 4096 - strlen($preview));
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $isStream = $httpStatus === 200 && stripos($contentType, 'text/event-stream') !== false;
    trafficProbeOut([
        'success' => $isStream,
        'source' => 'IXC/Central do Assinante',
        'login_id' => (int)$loginId,
        'http_status' => $httpStatus,
        'content_type' => $contentType ?: null,
        'authorized_stream' => $isStream,
        'events_preview' => $isStream ? trim($preview) : null,
        'message' => $isStream
            ? 'Stream autorizado pela credencial IXC.'
            : 'A credencial atual não foi aceita para o stream da Central do Assinante.',
        'diagnostic' => $isStream ? null : ($curlError !== '' ? 'A conexão não respondeu dentro do tempo de teste.' : null),
    ]);
} catch (Throwable $e) {
    trafficProbeOut([
        'success' => false,
        'source' => 'IXC/Central do Assinante',
        'message' => $e->getMessage(),
    ], 502);
}
