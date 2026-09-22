<?php

declare(strict_types=1);

/**
 * JR CONECT ACS - IXC ACS compatibility probe
 *
 * Endpoint expected by IXC:
 *   GET /api/v1/token
 *
 * SECURITY NOTES
 * - Never logs or returns the incoming Bearer token.
 * - Restricts callers by source IP.
 * - Parses JWT structure only; it DOES NOT validate the JWT signature yet.
 * - Rejects JWTs using alg=none.
 * - Intended as a compatibility discovery endpoint until the IXC contract
 *   and JWT verification key/algorithm are confirmed.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-JR-ACS-Compat: ixc-token-probe-v1');

function jrIxcJson(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function jrIxcBase64UrlDecode(string $value): string|false
{
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;

    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode($value, true);
}

function jrIxcAllowedIps(): array
{
    $raw = trim((string)(getenv('IXC_ACS_ALLOWED_IPS') ?: '138.204.112.14'));

    if ($raw === '') {
        return [];
    }

    $ips = array_map(
        static fn(string $value): string => trim($value),
        explode(',', $raw)
    );

    return array_values(array_filter(
        $ips,
        static fn(string $value): bool => $value !== ''
    ));
}

function jrIxcAuthorizationHeader(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0 && is_string($value)) {
                return trim($value);
            }
        }
    }

    return '';
}

function jrIxcSafeLog(array $data): void
{
    $logFile = dirname(__DIR__, 2) . '/logs/ixc-acs-compat.log';

    $line = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (!is_string($line)) {
        return;
    }

    @error_log($line . PHP_EOL, 3, $logFile);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    jrIxcJson([
        'success' => false,
        'error' => 'method_not_allowed',
    ], 405);
}

$remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$allowedIps = jrIxcAllowedIps();

if ($remoteIp === '' || !in_array($remoteIp, $allowedIps, true)) {
    jrIxcSafeLog([
        'at' => gmdate('c'),
        'event' => 'request_rejected',
        'reason' => 'source_ip_not_allowed',
        'remote_ip' => $remoteIp !== '' ? $remoteIp : null,
    ]);

    jrIxcJson([
        'success' => false,
        'error' => 'forbidden',
    ], 403);
}

$authorization = jrIxcAuthorizationHeader();

if (!preg_match('/^Bearer\\s+([^\\s]+)$/i', $authorization, $match)) {
    jrIxcSafeLog([
        'at' => gmdate('c'),
        'event' => 'request_rejected',
        'reason' => 'bearer_missing_or_invalid',
        'remote_ip' => $remoteIp,
    ]);

    jrIxcJson([
        'success' => false,
        'error' => 'bearer_required',
    ], 401);
}

$jwt = $match[1];
$parts = explode('.', $jwt);

if (count($parts) !== 3) {
    jrIxcJson([
        'success' => false,
        'error' => 'invalid_jwt_structure',
    ], 401);
}

$headerRaw = jrIxcBase64UrlDecode($parts[0]);
$payloadRaw = jrIxcBase64UrlDecode($parts[1]);

if ($headerRaw === false || $payloadRaw === false) {
    jrIxcJson([
        'success' => false,
        'error' => 'invalid_jwt_encoding',
    ], 401);
}

$jwtHeader = json_decode($headerRaw, true);
$jwtPayload = json_decode($payloadRaw, true);

if (!is_array($jwtHeader) || !is_array($jwtPayload)) {
    jrIxcJson([
        'success' => false,
        'error' => 'invalid_jwt_json',
    ], 401);
}

$algorithm = strtoupper(trim((string)($jwtHeader['alg'] ?? '')));

if ($algorithm === '' || $algorithm === 'NONE') {
    jrIxcSafeLog([
        'at' => gmdate('c'),
        'event' => 'request_rejected',
        'reason' => 'unsafe_jwt_algorithm',
        'remote_ip' => $remoteIp,
    ]);

    jrIxcJson([
        'success' => false,
        'error' => 'unsafe_jwt_algorithm',
    ], 401);
}

$now = time();
$expired = isset($jwtPayload['exp'])
    && is_numeric($jwtPayload['exp'])
    && (int)$jwtPayload['exp'] < $now;

$notYetValid = isset($jwtPayload['nbf'])
    && is_numeric($jwtPayload['nbf'])
    && (int)$jwtPayload['nbf'] > ($now + 60);

$claimNames = array_values(array_map(
    static fn(mixed $key): string => (string)$key,
    array_keys($jwtPayload)
));
sort($claimNames, SORT_STRING);

$headerNames = array_values(array_map(
    static fn(mixed $key): string => (string)$key,
    array_keys($jwtHeader)
));
sort($headerNames, SORT_STRING);

jrIxcSafeLog([
    'at' => gmdate('c'),
    'event' => 'jwt_probe',
    'remote_ip' => $remoteIp,
    'algorithm' => $algorithm,
    'header_keys' => $headerNames,
    'claim_keys' => $claimNames,
    'has_kid' => isset($jwtHeader['kid']),
    'has_iss' => isset($jwtPayload['iss']),
    'has_aud' => isset($jwtPayload['aud']),
    'has_sub' => isset($jwtPayload['sub']),
    'has_exp' => isset($jwtPayload['exp']),
    'has_nbf' => isset($jwtPayload['nbf']),
    'expired' => $expired,
    'not_yet_valid' => $notYetValid,
    'signature_verified' => false,
]);

if ($expired || $notYetValid) {
    jrIxcJson([
        'success' => false,
        'error' => $expired ? 'jwt_expired' : 'jwt_not_yet_valid',
    ], 401);
}

/*
 * We intentionally do not claim that the caller is authenticated yet.
 * Signature verification will be enabled after the IXC signing key and
 * expected claims are identified.
 */
jrIxcJson([
    'success' => true,
    'mode' => 'compatibility_probe',
    'token_type' => 'Bearer',
    'jwt' => [
        'algorithm' => $algorithm,
        'header_keys' => $headerNames,
        'claim_keys' => $claimNames,
        'signature_verified' => false,
    ],
    'next_step' => 'configure_jwt_signature_verification',
], 200);
