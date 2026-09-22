<?php
declare(strict_types=1);

function jrSimsConfig(): array
{
    $token = trim((string)(getenv('SIMS_API_TOKEN') ?: ''));
    $allowedIps = [];

    $files = [
        '/etc/jrconect-acs/sims.php',
        __DIR__ . '/../config/sims.php',
    ];

    foreach ($files as $file) {
        if (is_file($file) && is_readable($file)) {
            $cfg = require $file;
            if (is_array($cfg)) {
                if ($token === '') {
                    $token = trim((string)($cfg['token'] ?? ''));
                }
                if (!$allowedIps && isset($cfg['allowed_ips']) && is_array($cfg['allowed_ips'])) {
                    $allowedIps = array_values(array_filter(array_map('strval', $cfg['allowed_ips'])));
                }
            }
        }
    }

    if ($token === '') {
        throw new RuntimeException('SIMS_API_TOKEN não configurado.');
    }

    return [
        'token' => $token,
        'allowed_ips' => $allowedIps,
    ];
}

function jrSimsClientIp(): string
{
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function jrSimsBearerToken(): string
{
    $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return '';
    }
    return trim($m[1]);
}

function jrSimsAuthorize(): void
{
    $cfg = jrSimsConfig();
    $ip = jrSimsClientIp();

    if ($cfg['allowed_ips'] && !in_array($ip, $cfg['allowed_ips'], true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'IP não autorizado.']);
        exit;
    }

    $given = jrSimsBearerToken();
    if ($given === '' || !hash_equals($cfg['token'], $given)) {
        http_response_code(401);
        header('WWW-Authenticate: Bearer');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Token inválido.']);
        exit;
    }
}


function jrSimsAudit(string $event, array $data = []): void
{
    unset($data['password'], $data['wifi_password'], $data['token'], $data['authorization']);

    $record = [
        'at' => gmdate('c'),
        'event' => $event,
        'remote_ip' => jrSimsClientIp(),
        'data' => $data,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) return;

    $logFile = dirname(__DIR__) . '/logs/sims-api.log';
    $ok = @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($ok === false) {
        error_log('[SIMS_API] ' . $line);
    }
}
