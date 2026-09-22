<?php
declare(strict_types=1);

/**
 * Security helpers for JR CONECT ACS.
 * TOTP: RFC 6238 / SHA-1 / 30 second period / 6 digits.
 */

function securityColumnsReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;

    $required = [
        'two_factor_secret',
        'two_factor_enabled',
        'two_factor_recovery',
        'two_factor_confirmed_at',
    ];

    $result = $conn->query("SHOW COLUMNS FROM users");
    if (!$result) return $ready = false;

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[(string)$row['Field']] = true;
    }

    foreach ($required as $column) {
        if (!isset($columns[$column])) return $ready = false;
    }

    return $ready = true;
}

function securityRequireRole(array $roles): array
{
    requireLogin();

    $id = $_SESSION['user_id'] ?? null;
    if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id < 1) {
        http_response_code(401);
        exit('Sessão inválida.');
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare('SELECT id, username, name, role, active FROM users WHERE id = ? LIMIT 1');
    $uid = (int)$id;
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int)($user['active'] ?? 0) !== 1) {
        session_unset();
        session_destroy();
        http_response_code(403);
        exit('Usuário inativo ou não autorizado.');
    }

    $role = strtolower(trim((string)($user['role'] ?? '')));
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        exit('Acesso restrito.');
    }

    return $user;
}

function securityCsrfToken(): string
{
    if (empty($_SESSION['security_csrf']) || !is_string($_SESSION['security_csrf'])) {
        $_SESSION['security_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['security_csrf'];
}

function securityVerifyCsrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['security_csrf'])
        && is_string($_SESSION['security_csrf'])
        && hash_equals($_SESSION['security_csrf'], $token);
}

function securityLoginComplete(mysqli $conn, array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user_id']  = (int)$user['id'];
    $_SESSION['name']     = (string)($user['name'] ?: $user['username']);
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['role']     = (string)$user['role'];
    $_SESSION['authenticated_at'] = time();

    unset(
        $_SESSION['pending_2fa_user_id'],
        $_SESSION['pending_2fa_started_at'],
        $_SESSION['pending_2fa_setup']
    );

    $stmt = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $id = (int)$user['id'];
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

function securityTwoFactorRequired(string $role): bool
{
    return in_array(strtolower(trim($role)), ['admin', 'noc'], true);
}

function securityBase32Encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function securityBase32Decode(string $value): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $value = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $value) ?? '');
    $bits = '';

    foreach (str_split($value) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) !== 8) break;
        $out .= chr(bindec($byte));
    }
    return $out;
}

function securityGenerateTotpSecret(): string
{
    return securityBase32Encode(random_bytes(20));
}

function securityTotpCode(string $base32Secret, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $counter = intdiv($timestamp, 30);
    $counterBytes = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $counterBytes, securityBase32Decode($base32Secret), true);
    $offset = ord($hash[19]) & 0x0F;
    $binary = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    );
    return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

function securityVerifyTotp(string $base32Secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) return false;

    $now = time();
    for ($offset = -$window; $offset <= $window; $offset++) {
        if (hash_equals(securityTotpCode($base32Secret, $now + ($offset * 30)), $code)) {
            return true;
        }
    }
    return false;
}

function securityKeyMaterial(): string
{
    if (defined('GACS_SECURITY_KEY') && is_string(GACS_SECURITY_KEY) && trim(GACS_SECURITY_KEY) !== '') {
        return hash('sha256', GACS_SECURITY_KEY, true);
    }

    $env = getenv('GACS_SECURITY_KEY');
    if (is_string($env) && trim($env) !== '') {
        return hash('sha256', $env, true);
    }

    $fallback = (defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('APP_NAME') ? APP_NAME : 'GACS');
    return hash('sha256', $fallback, true);
}

function securityEncryptSecret(string $plain): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL não disponível para proteger o segredo 2FA.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        securityKeyMaterial(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if (!is_string($cipher)) throw new RuntimeException('Falha ao criptografar o segredo 2FA.');
    return base64_encode($iv . $tag . $cipher);
}

function securityDecryptSecret(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    if (!is_string($raw) || strlen($raw) < 29) {
        throw new RuntimeException('Segredo 2FA inválido.');
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        securityKeyMaterial(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if (!is_string($plain) || $plain === '') {
        throw new RuntimeException('Não foi possível descriptografar o segredo 2FA.');
    }

    return $plain;
}

function securityGenerateRecoveryCodes(int $count = 8): array
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(4)));
    }
    return $codes;
}

function securityHashRecoveryCodes(array $codes): string
{
    $hashes = [];
    foreach ($codes as $code) {
        $normalized = strtoupper(preg_replace('/[^A-F0-9]/i', '', (string)$code) ?? '');
        $hashes[] = password_hash($normalized, PASSWORD_DEFAULT);
    }
    return json_encode($hashes, JSON_UNESCAPED_SLASHES);
}

function securityConsumeRecoveryCode(mysqli $conn, int $userId, string $provided, ?string $json): bool
{
    $provided = strtoupper(preg_replace('/[^A-F0-9]/i', '', $provided) ?? '');
    if ($provided === '' || !is_string($json) || $json === '') return false;

    $hashes = json_decode($json, true);
    if (!is_array($hashes)) return false;

    foreach ($hashes as $index => $hash) {
        if (is_string($hash) && password_verify($provided, $hash)) {
            unset($hashes[$index]);
            $updated = json_encode(array_values($hashes), JSON_UNESCAPED_SLASHES);
            $stmt = $conn->prepare('UPDATE users SET two_factor_recovery = ? WHERE id = ?');
            $stmt->bind_param('si', $updated, $userId);
            $stmt->execute();
            $stmt->close();
            return true;
        }
    }

    return false;
}

function securityOtpAuthUri(string $issuer, string $username, string $secret): string
{
    $label = rawurlencode($issuer . ':' . $username);
    return 'otpauth://totp/' . $label
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}
