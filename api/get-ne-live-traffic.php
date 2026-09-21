<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/ConcentratorConfig.php';

use phpseclib3\Net\SSH2;

if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');

function neOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function neSafeUsername(string $username): ?string
{
    $username = trim($username);
    if ($username === '' || strlen($username) > 253) return null;
    return preg_match('/^[A-Za-z0-9_.@:-]+$/', $username) ? $username : null;
}

function neParseNumber(string $value): ?float
{
    $value = trim(str_replace([',', ' '], ['', ''], $value));
    if ($value === '' || $value === '-') return null;
    return is_numeric($value) ? max(0.0, (float)$value) : null;
}

function neFindField(string $output, array $labels): ?string
{
    foreach ($labels as $label) {
        $pattern = '/^\s*' . preg_quote($label, '/') . '\s*:\s*(.*?)\s*$/mi';
        if (preg_match($pattern, $output, $match)) {
            $value = trim((string)$match[1]);
            if ($value !== '') return $value;
        }
    }
    return null;
}

function neExtractUserId(string $output): ?int
{
    $patterns = [
        '/^\s*User\s+ID\s*:\s*(\d+)\s*$/mi',
        '/^\s*UserID\s*:\s*(\d+)\s*$/mi',
        '/^\s*User\s+access\s+index\s*:\s*(\d+)\s*$/mi',
        '/^\s*User\s+index\s*:\s*(\d+)\s*$/mi',
        // Tabela resumida: UserID Username Interface IP MAC ...
        '/^\s*(\d+)\s+\S+\s+.*?(?:\d{1,3}\.){3}\d{1,3}(?:\s|$)/m',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $output, $match)) {
            return (int)$match[1];
        }
    }

    return null;
}

function neExtractUserIdForIp(string $output, string $ip): ?int
{
    if ($ip === '') return null;
    $quoted = preg_quote($ip, '/');

    if (preg_match('/^\s*(\d+)\s+.*?' . $quoted . '(?:\s|$)/m', $output, $match)) {
        return (int)$match[1];
    }

    return neExtractUserId($output);
}

function neExtractUserIdForUsername(string $output, string $username): ?int
{
    if ($username === '') return null;
    $quoted = preg_quote($username, '/');

    // Aceita também username@dominio quando o RADIUS entrega apenas a parte local.
    if (preg_match('/^\s*(\d+)\s+.*?\b' . $quoted . '(?:@[^\s]+)?(?:\s|$)/mi', $output, $match)) {
        return (int)$match[1];
    }

    return neExtractUserId($output);
}

function neParseSession(string $output): array
{
    $uploadRaw = neFindField($output, [
        'User inbound data flow(Byte)',
        'User inbound data flow (Byte)',
        'User upstream data flow(Byte)',
        'User up data flow(Byte)',
    ]);
    $downloadRaw = neFindField($output, [
        'User outbound data flow(Byte)',
        'User outbound data flow (Byte)',
        'User downstream data flow(Byte)',
        'User down data flow(Byte)',
    ]);

    return [
        'user_id' => neExtractUserId($output),
        'username' => neFindField($output, ['User name', 'Username']),
        'ip' => neFindField($output, ['User IP address', 'IP address']),
        'mac' => neFindField($output, ['User MAC', 'MAC']),
        'interface' => neFindField($output, ['User access Interface', 'Access interface', 'Interface']),
        'online_time' => neFindField($output, ['Online time']),
        'access_time' => neFindField($output, ['User access time', 'Access start time']),
        'upload_bytes' => $uploadRaw !== null ? neParseNumber($uploadRaw) : null,
        'download_bytes' => $downloadRaw !== null ? neParseNumber($downloadRaw) : null,
    ];
}

function neHasCounters(array $session): bool
{
    return $session['upload_bytes'] !== null && $session['download_bytes'] !== null;
}

function nePromptRegex(): string
{
    // Huawei VRP normalmente usa <HOSTNAME> no user view
    // e [HOSTNAME] no system view.
    return '/(?:<[^<>\\r\\n]+>|\\[[^\\[\\]\\r\\n]+\\])\\s*$/';
}

function neCleanTerminalOutput(string $output): string
{
    $clean = preg_replace(
        '/\\x1B(?:[@-Z\\\\-_]|\\[[0-?]*[ -\\/]*[@-~])/',
        '',
        $output
    );

    return str_replace("\\0", '', is_string($clean) ? $clean : $output);
}

function nePrepareInteractiveShell(SSH2 $ssh): void
{
    // Alguns NE8000 aceitam autenticação SSH mas fecham CHANNEL_EXEC.
    // Abrir o shell e consumir o prompt reproduz uma sessão de terminal.
    $ssh->setWindowSize(200, 80);
    $ssh->setTimeout(4);
    $ssh->read(nePromptRegex(), SSH2::READ_REGEX);
}

function neRunReadOnly(SSH2 $ssh, string $command): string
{
    if (!preg_match(
        '/^(?:screen-length 0 temporary|display access-user (?:ip-address [0-9.]+(?: detail)?|username [A-Za-z0-9_.@:-]+(?: detail)?|user-id \\d+))$/',
        $command
    )) {
        throw new RuntimeException('Comando de leitura não autorizado pelo monitoramento.');
    }

    $ssh->setTimeout(6);
    $ssh->write($command . "\\n");

    $output = (string)$ssh->read(
        nePromptRegex(),
        SSH2::READ_REGEX
    );

    return neCleanTerminalOutput($output);
}

function neReadSession(SSH2 $ssh, string $ip, ?string $username): array
{
    $attempts = [];
    $best = [
        'user_id' => null,
        'username' => $username,
        'ip' => $ip !== '' ? $ip : null,
        'mac' => null,
        'interface' => null,
        'online_time' => null,
        'access_time' => null,
        'upload_bytes' => null,
        'download_bytes' => null,
    ];

    $merge = static function(array $base, array $extra): array {
        foreach ($extra as $key => $value) {
            if ($value !== null && $value !== '') {
                $base[$key] = $value;
            }
        }
        return $base;
    };

    // 1) Consulta detalhada diretamente pelo IPv4.
    // Em Huawei VRP, esta forma pode retornar User ID + contadores no mesmo bloco.
    if ($ip !== '') {
        $detailByIpOutput = neRunReadOnly(
            $ssh,
            'display access-user ip-address ' . $ip . ' detail'
        );
        $detailByIp = neParseSession($detailByIpOutput);
        $detailByIp['user_id'] = $detailByIp['user_id']
            ?? neExtractUserIdForIp($detailByIpOutput, $ip);
        $best = $merge($best, $detailByIp);

        $attempts[] = [
            'command' => 'ip-detail',
            'user_id_found' => $detailByIp['user_id'] !== null,
            'counters_found' => neHasCounters($detailByIp),
        ];

        if (neHasCounters($detailByIp)) {
            return [$best, $attempts];
        }

        // 2) Se a versão do VRP não detalhar por IP, usa a tabela resumida.
        if ($detailByIp['user_id'] === null) {
            $summaryOutput = neRunReadOnly(
                $ssh,
                'display access-user ip-address ' . $ip
            );
            $summary = neParseSession($summaryOutput);
            $summary['user_id'] = $summary['user_id']
                ?? neExtractUserIdForIp($summaryOutput, $ip);
            $best = $merge($best, $summary);

            $attempts[] = [
                'command' => 'ip-summary',
                'user_id_found' => $summary['user_id'] !== null,
                'counters_found' => neHasCounters($summary),
            ];
        }

        // 3) Com UserID em mãos, consulta a visão detalhada canônica.
        if (($best['user_id'] ?? null) !== null) {
            $userId = (int)$best['user_id'];
            $userDetailOutput = neRunReadOnly(
                $ssh,
                'display access-user user-id ' . $userId
            );
            $userDetail = neParseSession($userDetailOutput);
            $userDetail['user_id'] = $userDetail['user_id'] ?? $userId;
            $best = $merge($best, $userDetail);

            $attempts[] = [
                'command' => 'user-id-detail',
                'user_id_found' => true,
                'counters_found' => neHasCounters($userDetail),
            ];

            if (neHasCounters($best)) {
                return [$best, $attempts];
            }
        }
    }

    // 4) Fallback por login PPPoE, incluindo username@dominio.
    if ($username !== null) {
        $detailByUserOutput = neRunReadOnly(
            $ssh,
            'display access-user username ' . $username . ' detail'
        );
        $detailByUser = neParseSession($detailByUserOutput);
        $detailByUser['user_id'] = $detailByUser['user_id']
            ?? neExtractUserIdForUsername($detailByUserOutput, $username);
        $best = $merge($best, $detailByUser);

        $attempts[] = [
            'command' => 'username-detail',
            'user_id_found' => $detailByUser['user_id'] !== null,
            'counters_found' => neHasCounters($detailByUser),
        ];

        if (neHasCounters($best)) {
            return [$best, $attempts];
        }

        if (($best['user_id'] ?? null) === null) {
            $summaryOutput = neRunReadOnly(
                $ssh,
                'display access-user username ' . $username
            );
            $summary = neParseSession($summaryOutput);
            $summary['user_id'] = $summary['user_id']
                ?? neExtractUserIdForUsername($summaryOutput, $username);
            $best = $merge($best, $summary);

            $attempts[] = [
                'command' => 'username-summary',
                'user_id_found' => $summary['user_id'] !== null,
                'counters_found' => neHasCounters($summary),
            ];
        }

        if (($best['user_id'] ?? null) !== null) {
            $userId = (int)$best['user_id'];
            $userDetailOutput = neRunReadOnly(
                $ssh,
                'display access-user user-id ' . $userId
            );
            $userDetail = neParseSession($userDetailOutput);
            $userDetail['user_id'] = $userDetail['user_id'] ?? $userId;
            $best = $merge($best, $userDetail);

            $attempts[] = [
                'command' => 'username-user-id-detail',
                'user_id_found' => true,
                'counters_found' => neHasCounters($userDetail),
            ];
        }
    }

    return [$best, $attempts];
}

function neCacheFile(int $concentratorId, string $key): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'jrconect_ne_live_' . $concentratorId . '_' . sha1($key) . '.json';
}

function neCalculateRate(
    int $concentratorId,
    string $sessionKey,
    float $downloadBytes,
    float $uploadBytes
): array {
    $now = microtime(true);
    $file = neCacheFile($concentratorId, $sessionKey);
    $previous = null;

    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) $previous = $decoded;
    }

    @file_put_contents($file, json_encode([
        'at' => $now,
        'download' => $downloadBytes,
        'upload' => $uploadBytes,
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);

    if (!$previous) {
        return [
            'available' => false,
            'reason' => 'collecting_second_sample',
            'interval_seconds' => null,
        ];
    }

    $dt = $now - (float)($previous['at'] ?? 0);
    $prevDown = (float)($previous['download'] ?? $downloadBytes);
    $prevUp = (float)($previous['upload'] ?? $uploadBytes);

    if ($dt < 0.7 || $dt > 30 || $downloadBytes < $prevDown || $uploadBytes < $prevUp) {
        return [
            'available' => false,
            'reason' => 'counter_window_reset',
            'interval_seconds' => round($dt, 3),
        ];
    }

    return [
        'available' => true,
        'reason' => null,
        'interval_seconds' => round($dt, 3),
        'download_mbps' => (($downloadBytes - $prevDown) * 8) / $dt / 1000000,
        'upload_mbps' => (($uploadBytes - $prevUp) * 8) / $dt / 1000000,
    ];
}

try {
    $ip = trim((string)($_GET['ip'] ?? ''));
    $usernameRaw = trim((string)($_GET['username'] ?? ''));
    $nasIp = trim((string)($_GET['nas_ip'] ?? ''));
    $sessionId = trim((string)($_GET['session_id'] ?? ''));

    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        neOut([
            'success' => false,
            'available' => false,
            'reason' => 'invalid_ip',
            'message' => 'IP PPPoE inválido.',
        ], 400);
    }

    $username = $usernameRaw !== '' ? neSafeUsername($usernameRaw) : null;
    if ($ip === '' && $username === null) {
        neOut([
            'success' => false,
            'available' => false,
            'reason' => 'session_identity_missing',
            'message' => 'IP ou login PPPoE não informado.',
        ], 400);
    }

    $conn = getDBConnection();
    $concentrator = getConcentratorForNas($conn, $nasIp !== '' ? $nasIp : null, true);

    if (!$concentrator) {
        neOut([
            'success' => true,
            'available' => false,
            'reason' => 'concentrator_not_configured',
            'message' => 'Nenhum concentrador ativo foi encontrado para esta sessão.',
        ]);
    }

    if (!class_exists(SSH2::class)) {
        neOut([
            'success' => true,
            'available' => false,
            'reason' => 'ssh_dependency_missing',
            'message' => 'Biblioteca SSH indisponível no servidor.',
        ]);
    }

    $ssh = new SSH2(
        (string)$concentrator['host'],
        (int)$concentrator['port'],
        6
    );
    $ssh->setTimeout(6);

    if (!$ssh->login(
        (string)$concentrator['username'],
        (string)$concentrator['password']
    )) {
        neOut([
            'success' => true,
            'available' => false,
            'reason' => 'ssh_authentication_failed',
            'message' => 'O concentrador recusou a autenticação SSH.',
            'concentrator' => [
                'id' => $concentrator['id'],
                'name' => $concentrator['name'],
                'model' => $concentrator['model'],
            ],
        ]);
    }

    $fingerprint = concentratorFingerprint($ssh);
    $expected = trim((string)($concentrator['server_fingerprint'] ?? ''));
    if ($expected !== '' && $fingerprint !== null && !hash_equals($expected, $fingerprint)) {
        neOut([
            'success' => true,
            'available' => false,
            'reason' => 'host_key_changed',
            'message' => 'A chave SSH do concentrador não corresponde à chave salva.',
        ]);
    }

    // Abre CLI interativa antes dos comandos de leitura.
    // Isso evita "Connection closed by server" em NE8000 que rejeitam exec().
    nePrepareInteractiveShell($ssh);

    // Tenta evitar paginação. Falha neste comando não impede a leitura seguinte.
    try {
        neRunReadOnly($ssh, 'screen-length 0 temporary');
    } catch (Throwable) {
    }

    [$session, $attempts] = neReadSession($ssh, $ip, $username);

    if (!neHasCounters($session)) {
        neOut([
            'success' => true,
            'available' => false,
            'reason' => 'traffic_counters_not_found',
            'message' => ($session['user_id'] ?? null) !== null
                ? 'Usuário localizado no NE8000, mas os contadores de tráfego não apareceram no display access-user user-id.'
                : 'O NE8000 respondeu, mas o UserID da sessão PPPoE não foi localizado pelo IP/login informado.',
            'source' => 'Huawei NE8000 / SSH',
            'concentrator' => [
                'id' => $concentrator['id'],
                'name' => $concentrator['name'],
                'model' => $concentrator['model'],
                'nas_ip' => $concentrator['nas_ip'] ?? $concentrator['host'],
            ],
            'session' => [
                'user_id' => $session['user_id'],
                'username' => $session['username'] ?: $username,
                'ip' => $session['ip'] ?: $ip,
                'mac' => $session['mac'],
                'interface' => $session['interface'],
            ],
            'diagnostic' => [
                'attempts' => $attempts,
                'lookup_ip' => $ip !== '' ? $ip : null,
                'lookup_username' => $username,
            ],
        ]);
    }

    $downloadBytes = (float)$session['download_bytes'];
    $uploadBytes = (float)$session['upload_bytes'];
    $key = implode('|', [
        $sessionId,
        (string)($session['user_id'] ?? ''),
        (string)($session['username'] ?? $username ?? ''),
        (string)($session['ip'] ?? $ip),
    ]);

    $rate = neCalculateRate(
        (int)$concentrator['id'],
        $key,
        $downloadBytes,
        $uploadBytes
    );

    neOut([
        'success' => true,
        'available' => !empty($rate['available']),
        'reason' => $rate['reason'] ?? null,
        'message' => !empty($rate['available'])
            ? null
            : (($rate['reason'] ?? '') === 'collecting_second_sample'
                ? 'Primeira leitura do NE8000 recebida; aguardando a próxima amostra.'
                : 'A janela de medição do NE8000 foi reiniciada; aguardando nova amostra.'),
        'source' => 'Huawei NE8000 / SSH',
        'transport' => 'ssh',
        'concentrator' => [
            'id' => $concentrator['id'],
            'name' => $concentrator['name'],
            'model' => $concentrator['model'],
            'vendor' => strtoupper((string)$concentrator['vendor']),
            'nas_ip' => $concentrator['nas_ip'] ?? $concentrator['host'],
        ],
        'live' => !empty($rate['available']) ? [
            'download_mbps' => round((float)$rate['download_mbps'], 6),
            'upload_mbps' => round((float)$rate['upload_mbps'], 6),
            'sample_time' => date(DATE_ATOM),
            'interval_seconds' => $rate['interval_seconds'],
            'transport' => 'ne-ssh',
        ] : null,
        'counters' => [
            'download_bytes' => $downloadBytes,
            'upload_bytes' => $uploadBytes,
        ],
        'session' => [
            'user_id' => $session['user_id'],
            'username' => $session['username'] ?: $username,
            'ip' => $session['ip'] ?: $ip,
            'mac' => $session['mac'],
            'interface' => $session['interface'],
            'online_time' => $session['online_time'],
            'access_time' => $session['access_time'],
        ],
        'diagnostic' => [
            'attempts' => $attempts,
        ],
    ]);

} catch (Throwable $e) {
    neOut([
        'success' => true,
        'available' => false,
        'reason' => 'ne_request_failed',
        'message' => 'Falha ao consultar o tráfego diretamente no concentrador.',
        'diagnostic' => [
            'detail' => mb_substr($e->getMessage(), 0, 220),
            'type' => (new ReflectionClass($e))->getShortName(),
        ],
    ]);
}
