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

function jrIxcJoseEcdsaToDer(string $signature): string|false
{
    $length = strlen($signature);

    if ($length !== 64) {
        return false;
    }

    $r = substr($signature, 0, 32);
    $s = substr($signature, 32, 32);

    $encodeInteger = static function (string $value): string {
        $value = ltrim($value, "\x00");

        if ($value === '') {
            $value = "\x00";
        }

        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return "\x02" . chr(strlen($value)) . $value;
    };

    $sequence = $encodeInteger($r) . $encodeInteger($s);

    return "\x30" . chr(strlen($sequence)) . $sequence;
}

function jrIxcClientIdFile(): string
{
    $configured = trim((string)(getenv('IXC_ACS_CLIENT_ID_FILE') ?: ''));

    return $configured !== ''
        ? $configured
        : '/etc/jrconect-acs/ixc_client_id';
}

function jrIxcExpectedClientId(): ?string
{
    $file = jrIxcClientIdFile();

    if (!is_file($file) || !is_readable($file)) {
        return null;
    }

    $value = trim((string)file_get_contents($file));

    return $value !== '' ? $value : null;
}

function jrIxcPublicKeyFile(): string
{
    $configured = trim((string)(getenv('IXC_ACS_PUBLIC_KEY_FILE') ?: ''));

    return $configured !== ''
        ? $configured
        : '/etc/jrconect-acs/ixc_acs_public.pem';
}

function jrIxcVerifyEs256(
    string $signingInput,
    string $signatureRaw
): bool {
    if (!function_exists('openssl_verify')) {
        throw new RuntimeException('Extensão OpenSSL do PHP indisponível.');
    }

    $keyFile = jrIxcPublicKeyFile();

    if (!is_file($keyFile) || !is_readable($keyFile)) {
        throw new RuntimeException('Chave pública IXC não configurada ou não legível.');
    }

    $pem = file_get_contents($keyFile);

    if (!is_string($pem) || trim($pem) === '') {
        throw new RuntimeException('Chave pública IXC vazia ou inválida.');
    }

    $publicKey = openssl_pkey_get_public($pem);

    if ($publicKey === false) {
        throw new RuntimeException('Não foi possível carregar a chave pública IXC.');
    }

    $derSignature = jrIxcJoseEcdsaToDer($signatureRaw);

    if ($derSignature === false) {
        return false;
    }

    return openssl_verify(
        $signingInput,
        $derSignature,
        $publicKey,
        OPENSSL_ALGO_SHA256
    ) === 1;
}

function jrIxcDerEcdsaToJose(string $der, int $partLength = 32): string|false
{
    $offset = 0;
    $length = strlen($der);

    if ($length < 8 || ord($der[$offset++]) !== 0x30) {
        return false;
    }

    $seqLength = ord($der[$offset++]);
    if (($seqLength & 0x80) !== 0) {
        $bytes = $seqLength & 0x7f;
        if ($bytes < 1 || $bytes > 2 || $offset + $bytes > $length) {
            return false;
        }
        $seqLength = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $seqLength = ($seqLength << 8) | ord($der[$offset++]);
        }
    }

    if ($offset >= $length || ord($der[$offset++]) !== 0x02) {
        return false;
    }

    $rLength = ord($der[$offset++]);
    if ($offset + $rLength > $length) {
        return false;
    }
    $r = substr($der, $offset, $rLength);
    $offset += $rLength;

    if ($offset >= $length || ord($der[$offset++]) !== 0x02) {
        return false;
    }

    $sLength = ord($der[$offset++]);
    if ($offset + $sLength > $length) {
        return false;
    }
    $s = substr($der, $offset, $sLength);

    $normalize = static function (string $value) use ($partLength): string|false {
        $value = ltrim($value, "\x00");
        if (strlen($value) > $partLength) {
            return false;
        }
        return str_pad($value, $partLength, "\x00", STR_PAD_LEFT);
    };

    $r = $normalize($r);
    $s = $normalize($s);

    if ($r === false || $s === false) {
        return false;
    }

    return $r . $s;
}

function jrIxcBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function jrIxcAccessPrivateKeyFile(): string
{
    $configured = trim((string)(getenv('IXC_ACS_ACCESS_PRIVATE_KEY_FILE') ?: ''));

    return $configured !== ''
        ? $configured
        : '/etc/jrconect-acs/access_token_private.pem';
}

function jrIxcCreateAccessToken(string $clientId, int $expiresIn): string
{
    if (!function_exists('openssl_sign')) {
        throw new RuntimeException('Extensão OpenSSL do PHP indisponível.');
    }

    $keyFile = jrIxcAccessPrivateKeyFile();

    if (!is_file($keyFile) || !is_readable($keyFile)) {
        throw new RuntimeException('Chave privada de Access Token não configurada.');
    }

    $pem = file_get_contents($keyFile);
    if (!is_string($pem) || trim($pem) === '') {
        throw new RuntimeException('Chave privada de Access Token vazia.');
    }

    $privateKey = openssl_pkey_get_private($pem);
    if ($privateKey === false) {
        throw new RuntimeException('Não foi possível carregar a chave privada de Access Token.');
    }

    $now = time();

    $headerJson = json_encode([
        'alg' => 'ES256',
        'typ' => 'JWT',
    ], JSON_UNESCAPED_SLASHES);

    // Match IXC ACS JWT contract: issuer is the Client API ID and the
    // payload contains only iss/exp/iat.
    $payloadJson = json_encode([
        'iss' => $clientId,
        'exp' => $now + $expiresIn,
        'iat' => $now,
    ], JSON_UNESCAPED_SLASHES);

    if (!is_string($headerJson) || !is_string($payloadJson)) {
        throw new RuntimeException('Falha ao montar JWT de acesso.');
    }

    $header = jrIxcBase64UrlEncode($headerJson);
    $payload = jrIxcBase64UrlEncode($payloadJson);
    $signingInput = $header . '.' . $payload;

    $derSignature = '';
    if (!openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Falha ao assinar JWT de acesso.');
    }

    $joseSignature = jrIxcDerEcdsaToJose($derSignature, 32);
    if ($joseSignature === false) {
        throw new RuntimeException('Falha ao converter assinatura ECDSA para JOSE.');
    }

    return $signingInput . '.' . jrIxcBase64UrlEncode($joseSignature);
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
$signatureRaw = jrIxcBase64UrlDecode($parts[2]);

if ($headerRaw === false || $payloadRaw === false || $signatureRaw === false) {
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

if ($algorithm !== 'ES256') {
    jrIxcSafeLog([
        'at' => gmdate('c'),
        'event' => 'request_rejected',
        'reason' => 'unsupported_jwt_algorithm',
        'remote_ip' => $remoteIp,
        'algorithm' => $algorithm,
    ]);

    jrIxcJson([
        'success' => false,
        'error' => 'unsupported_jwt_algorithm',
    ], 401);
}

try {
    $signatureVerified = jrIxcVerifyEs256(
        $parts[0] . '.' . $parts[1],
        $signatureRaw
    );
} catch (Throwable $e) {
    jrIxcSafeLog([
        'at' => gmdate('c'),
        'event' => 'verification_error',
        'remote_ip' => $remoteIp,
        'message' => $e->getMessage(),
    ]);

    jrIxcJson([
        'success' => false,
        'error' => 'jwt_verification_unavailable',
    ], 503);
}

if (!$signatureVerified) {
    jrIxcSafeLog([
        'at' => gmdate('c'),
        'event' => 'request_rejected',
        'reason' => 'invalid_jwt_signature',
        'remote_ip' => $remoteIp,
        'algorithm' => $algorithm,
    ]);

    jrIxcJson([
        'success' => false,
        'error' => 'invalid_jwt_signature',
    ], 401);
}

$now = time();
$expired = isset($jwtPayload['exp'])
    && is_numeric($jwtPayload['exp'])
    && (int)$jwtPayload['exp'] < $now;

$notYetValid = isset($jwtPayload['nbf'])
    && is_numeric($jwtPayload['nbf'])
    && (int)$jwtPayload['nbf'] > ($now + 60);

$expectedClientId = jrIxcExpectedClientId();
$issuer = isset($jwtPayload['iss']) && is_scalar($jwtPayload['iss'])
    ? trim((string)$jwtPayload['iss'])
    : '';

$issuerMatchesClientId = $expectedClientId !== null
    && $issuer !== ''
    && hash_equals($expectedClientId, $issuer);

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
    'client_id_configured' => $expectedClientId !== null,
    'iss_matches_client_id' => $issuerMatchesClientId,
    'has_aud' => isset($jwtPayload['aud']),
    'has_sub' => isset($jwtPayload['sub']),
    'has_exp' => isset($jwtPayload['exp']),
    'has_nbf' => isset($jwtPayload['nbf']),
    'expired' => $expired,
    'not_yet_valid' => $notYetValid,
    'signature_verified' => true,
]);

if ($expired || $notYetValid) {
    jrIxcJson([
        'success' => false,
        'error' => $expired ? 'jwt_expired' : 'jwt_not_yet_valid',
    ], 401);
}

if (!$issuerMatchesClientId) {
    jrIxcSafeLog([
        'at' => gmdate('c'),
        'event' => 'request_rejected',
        'reason' => 'issuer_client_id_mismatch',
        'remote_ip' => $remoteIp,
        'signature_verified' => true,
    ]);

    jrIxcJson([
        'success' => false,
        'error' => 'client_not_authorized',
    ], 401);
}

/*
 * IXC ACS legacy authentication flow:
 * after validating the client-signed ES256 authentication JWT, issue a
 * short-lived ES256 JWT Access Token. IXC Provedor is expected to store and
 * resend this token on subsequent API calls.
 */
$expiresIn = 300;

try {
    $acsToken = jrIxcCreateAccessToken($expectedClientId ?? '', $expiresIn);
} catch (Throwable $e) {
    jrIxcSafeLog([
        'at' => gmdate('c'),
        'event' => 'access_token_error',
        'remote_ip' => $remoteIp,
        'message' => $e->getMessage(),
    ]);

    jrIxcJson([
        'success' => false,
        'error' => 'access_token_generation_failed',
    ], 503);
}

$tokenFingerprint = hash('sha256', $acsToken);

jrIxcSafeLog([
    'at' => gmdate('c'),
    'event' => 'acs_access_jwt_issued',
    'remote_ip' => $remoteIp,
    'signature_verified' => true,
    'issuer_client_match' => true,
    'token_format' => 'jwt_es256',
    'token_sha256_prefix' => substr($tokenFingerprint, 0, 12),
    'expires_in' => $expiresIn,
]);

jrIxcJson([
    'access_token' => $acsToken,
    'token' => $acsToken,
    'token_type' => 'Bearer',
    'expires_in' => $expiresIn,
], 200);
