<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/ConcentratorConfig.php';

use phpseclib3\Net\SSH2;

if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');

function diagOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function diagSafeUsername(string $username): ?string
{
    $username = trim($username);
    if ($username === '' || strlen($username) > 253) return null;
    return preg_match('/^[A-Za-z0-9_.@:-]+$/', $username) ? $username : null;
}

function diagPromptRegex(): string
{
    return '/(?:<[^<>\r\n]+>|\[[^\[\]\r\n]+\])\s*$/';
}

function diagClean(string $output): string
{
    $clean = preg_replace(
        '/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/',
        '',
        $output
    );
    $clean = str_replace("\0", '', is_string($clean) ? $clean : $output);
    $lines = preg_split('/\r\n|\r|\n/', $clean) ?: [];
    $result = [];

    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') continue;
        if (preg_match('/^(?:<[^<>]+>|\[[^\[\]]+\])$/', trim($line))) continue;
        $result[] = $line;
        if (count($result) >= 60) break;
    }

    return mb_substr(implode("\n", $result), 0, 6000);
}

function diagRun(SSH2 $ssh, string $command): array
{
    $ssh->setTimeout(5);
    $ssh->write($command . "\n");
    $raw = (string)$ssh->read(diagPromptRegex(), SSH2::READ_REGEX);

    return [
        'command' => $command,
        'output' => diagClean($raw),
    ];
}

try {
    $ip = trim((string)($_GET['ip'] ?? ''));
    $usernameRaw = trim((string)($_GET['username'] ?? ''));
    $nasIp = trim((string)($_GET['nas_ip'] ?? ''));

    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        diagOut(['success' => false, 'message' => 'IPv4 inválido.'], 400);
    }

    $username = $usernameRaw !== '' ? diagSafeUsername($usernameRaw) : null;
    if ($usernameRaw !== '' && $username === null) {
        diagOut(['success' => false, 'message' => 'Login PPPoE inválido para diagnóstico.'], 400);
    }

    $conn = getDBConnection();
    $concentrator = getConcentratorForNas($conn, $nasIp !== '' ? $nasIp : null, true);

    if (!$concentrator) {
        diagOut([
            'success' => false,
            'message' => 'Nenhum concentrador ativo foi encontrado.',
        ], 404);
    }

    if (!class_exists(SSH2::class)) {
        diagOut([
            'success' => false,
            'message' => 'Biblioteca SSH indisponível.',
        ], 500);
    }

    $ssh = new SSH2(
        (string)$concentrator['host'],
        (int)$concentrator['port'],
        7
    );
    $ssh->setTimeout(7);

    if (!$ssh->login(
        (string)$concentrator['username'],
        (string)$concentrator['password']
    )) {
        diagOut([
            'success' => false,
            'message' => 'O concentrador recusou a autenticação SSH.',
        ], 502);
    }

    $expected = trim((string)($concentrator['server_fingerprint'] ?? ''));
    $fingerprint = concentratorFingerprint($ssh);
    if ($expected !== '' && $fingerprint !== null && !hash_equals($expected, $fingerprint)) {
        diagOut([
            'success' => false,
            'message' => 'A chave SSH do concentrador não corresponde à chave salva.',
        ], 409);
    }

    $ssh->setWindowSize(220, 100);
    $ssh->setTimeout(5);
    $ssh->read(diagPromptRegex(), SSH2::READ_REGEX);

    try {
        diagRun($ssh, 'screen-length 0 temporary');
    } catch (Throwable) {
    }

    $commands = [
        'display access-user ?',
        'display aaa access-user ?',
    ];

    if ($ip !== '') {
        $commands[] = 'display access-user ip-address ' . $ip . ' ?';
        $commands[] = 'display aaa access-user ip-address ' . $ip . ' ?';
        $commands[] = 'display access-user ip-address ' . $ip;
        $commands[] = 'display aaa access-user ip-address ' . $ip;
    }

    if ($username !== null) {
        $commands[] = 'display access-user username ' . $username . ' ?';
        $commands[] = 'display aaa access-user username ' . $username . ' ?';
        $commands[] = 'display access-user username ' . $username;
        $commands[] = 'display aaa access-user username ' . $username;
    }

    $results = [];
    foreach ($commands as $command) {
        try {
            $results[] = diagRun($ssh, $command);
        } catch (Throwable $e) {
            $results[] = [
                'command' => $command,
                'output' => '',
                'error' => mb_substr($e->getMessage(), 0, 220),
            ];
            break;
        }
    }

    diagOut([
        'success' => true,
        'source' => 'Huawei NE8000 / SSH',
        'concentrator' => [
            'id' => $concentrator['id'],
            'name' => $concentrator['name'],
            'model' => $concentrator['model'],
            'nas_ip' => $concentrator['nas_ip'] ?? $concentrator['host'],
        ],
        'lookup' => [
            'ip' => $ip !== '' ? $ip : null,
            'username' => $username,
        ],
        'results' => $results,
    ]);

} catch (Throwable $e) {
    diagOut([
        'success' => false,
        'message' => 'Falha no diagnóstico CLI do concentrador.',
        'detail' => mb_substr($e->getMessage(), 0, 220),
    ], 502);
}
