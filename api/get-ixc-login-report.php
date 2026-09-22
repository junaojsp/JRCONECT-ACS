<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
if (function_exists('requireLogin')) requireLogin();

header('Content-Type: application/json; charset=utf-8');

function reportOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function reportConfig(): array
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

function reportList(
    string $baseUrl,
    string $token,
    string $table,
    array $payload
): array {
    $url = $baseUrl . '/webservice/v1/' . rawurlencode($table);
    $ch = curl_init($url);
    if ($ch === false) return [];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
        ],
        CURLOPT_POSTFIELDS => json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $http < 200 || $http >= 300) return [];

    $json = json_decode($body, true);
    if (!is_array($json)) return [];

    $records = $json['registros']
        ?? $json['records']
        ?? (array_is_list($json) ? $json : []);

    return is_array($records)
        ? array_values(array_filter($records, 'is_array'))
        : [];
}

function reportIxcConsumptionApi(
    string $baseUrl,
    string $token,
    int $loginId,
    string $since,
    string $until
): array {
    $rows = reportList(
        $baseUrl,
        $token,
        'radusuarios_consumo',
        [
            'qtype' => 'radusuarios_consumo.id_login',
            'query' => (string)$loginId,
            'oper' => '=',
            'page' => '1',
            'rp' => '1000',
            'sortname' => 'radusuarios_consumo.data',
            'sortorder' => 'asc',
        ]
    );

    if (!$rows) {
        return [
            'available' => false,
            'source' => 'Webservice radusuarios_consumo',
            'reason' => 'no_rows',
            'daily' => [],
        ];
    }

    $from = strtotime($since . ' 00:00:00');
    $to = strtotime($until . ' 23:59:59');
    $daily = [];

    foreach ($rows as $row) {
        $date = trim((string)($row['data'] ?? ''));
        if ($date === '') continue;

        $ts = strtotime($date . ' 00:00:00');
        if ($ts === false || $ts < $from || $ts > $to) continue;

        $daily[$date] ??= [
            'date' => $date,
            'download_bytes' => 0,
            'upload_bytes' => 0,
            'sessions' => null,
        ];

        $daily[$date]['download_bytes'] += reportInt($row['consumo'] ?? 0);
        $daily[$date]['upload_bytes'] += reportInt($row['consumo_upload'] ?? 0);
    }

    ksort($daily);

    return [
        'available' => !empty($daily),
        'source' => 'Webservice radusuarios_consumo',
        'reason' => !empty($daily) ? null : 'no_rows_in_period',
        'daily' => array_values($daily),
    ];
}


function reportIxcConsumption(
    string $baseUrl,
    string $token,
    int $loginId,
    string $since,
    string $until
): array {
    $url = rtrim($baseUrl, '/')
        . '/aplicativo/radusuarios/rel_22021.php?consumo='
        . rawurlencode((string)$loginId)
        . '&since=' . rawurlencode($since)
        . '&until=' . rawurlencode($until);

    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'available' => false,
            'http_code' => 0,
            'reason' => 'curl_init_failed',
            'daily' => [],
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json,text/plain;q=0.9,*/*;q=0.8',
            'Cache-Control: no-cache',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
            'X-Requested-With: XMLHttpRequest',
        ],
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body)) {
        return [
            'available' => false,
            'http_code' => $http,
            'reason' => $error !== '' ? 'curl_error' : 'empty_response',
            'daily' => [],
        ];
    }

    $decoded = json_decode(trim($body), true);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return [
            'available' => false,
            'http_code' => $http,
            'content_type' => $contentType !== '' ? $contentType : null,
            'redirect_detected' => $redirect !== '',
            'reason' => $redirect !== '' || in_array($http, [401, 403], true)
                ? 'web_session_required'
                : 'invalid_json_shape',
            'daily' => [],
        ];
    }

    $daily = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) continue;

        $date = trim((string)($row['data'] ?? ''));
        if ($date === '') continue;

        $daily[] = [
            'date' => $date,
            'download_bytes' => reportInt($row['consumo'] ?? 0),
            'upload_bytes' => reportInt($row['consumo_upload'] ?? 0),
            'sessions' => null,
        ];
    }

    return [
        'available' => !empty($daily),
        'http_code' => $http,
        'content_type' => $contentType !== '' ? $contentType : null,
        'redirect_detected' => $redirect !== '',
        'reason' => !empty($daily) ? null : 'no_rows',
        'daily' => $daily,
    ];
}


function reportOne(
    string $baseUrl,
    string $token,
    string $table,
    string $qtype,
    string $query
): array {
    $rows = reportList($baseUrl, $token, $table, [
        'qtype' => $qtype,
        'query' => $query,
        'oper' => '=',
        'page' => '1',
        'rp' => '1',
        'sortname' => $table . '.id',
        'sortorder' => 'desc',
    ]);

    return $rows[0] ?? [];
}

function reportPick(array $row, array $keys): mixed
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) continue;
        $value = $row[$key];
        if ($value === null) continue;
        if (is_string($value) && trim($value) === '') continue;
        return $value;
    }
    return null;
}

function reportInt(mixed $value): int
{
    return is_numeric($value) ? max(0, (int)$value) : 0;
}

function reportFloat(mixed $value): float
{
    return is_numeric($value) ? max(0.0, (float)$value) : 0.0;
}

function reportTime(mixed $value): ?DateTimeImmutable
{
    if ($value === null || trim((string)$value) === '') return null;
    try {
        return new DateTimeImmutable((string)$value, new DateTimeZone('America/Sao_Paulo'));
    } catch (Throwable) {
        return null;
    }
}

function reportDuration(?DateTimeImmutable $start, ?DateTimeImmutable $stop): int
{
    if (!$start) return 0;
    $end = $stop ?: new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    return max(0, $end->getTimestamp() - $start->getTimestamp());
}

function reportCauseKind(string $cause): string
{
    $text = strtolower(trim($cause));

    if ($text === '') return 'unknown';
    if (str_contains($text, 'user') || str_contains($text, 'request')) return 'user';
    if (str_contains($text, 'admin')) return 'admin';
    if (str_contains($text, 'nas-reboot') || str_contains($text, 'reboot')) return 'nas_reboot';
    if (
        str_contains($text, 'lost') ||
        str_contains($text, 'carrier') ||
        str_contains($text, 'service') ||
        str_contains($text, 'timeout') ||
        str_contains($text, 'session-timeout')
    ) return 'lost';
    if (str_contains($text, 'nas') || str_contains($text, 'coa')) return 'nas';

    return 'other';
}

function reportConcentrator(
    string $baseUrl,
    string $token,
    ?string $id,
    ?string $nasIp
): array {
    $candidates = [
        ['table' => 'radnas', 'id_field' => 'radnas.id'],
        ['table' => 'radpop', 'id_field' => 'radpop.id'],
        ['table' => 'radconcentradores', 'id_field' => 'radconcentradores.id'],
    ];

    if ($id !== null && $id !== '') {
        foreach ($candidates as $candidate) {
            $row = reportOne(
                $baseUrl,
                $token,
                $candidate['table'],
                $candidate['id_field'],
                $id
            );
            if (!$row) continue;

            return [
                'id' => $id,
                'name' => reportPick($row, ['shortname', 'nome', 'name', 'description', 'descricao']),
                'ip' => reportPick($row, ['nasname', 'ip', 'endereco', 'host']) ?? $nasIp,
                'type' => reportPick($row, ['type', 'tipo', 'fabricante']),
            ];
        }
    }

    return [
        'id' => $id,
        'name' => null,
        'ip' => $nasIp,
        'type' => null,
    ];
}

try {
    [$baseUrl, $token] = reportConfig();

    $loginId = trim((string)($_GET['login_id'] ?? ''));
    $loginName = trim((string)($_GET['login'] ?? ''));

    $login = [];
    if ($loginId !== '' && preg_match('/^\d+$/', $loginId)) {
        $login = reportOne($baseUrl, $token, 'radusuarios', 'radusuarios.id', $loginId);
    } elseif ($loginName !== '') {
        $login = reportOne($baseUrl, $token, 'radusuarios', 'radusuarios.login', $loginName);
    }

    if (!$login) {
        reportOut([
            'success' => false,
            'message' => 'Login IXC não encontrado.',
        ], 404);
    }

    $loginId = (string)($login['id'] ?? $loginId);
    $loginName = (string)($login['login'] ?? $loginName);

    $sessions = reportList($baseUrl, $token, 'radacct', [
        'qtype' => 'radacct.username',
        'query' => $loginName,
        'oper' => '=',
        'page' => '1',
        'rp' => '2000',
        'sortname' => 'radacct.radacctid',
        'sortorder' => 'desc',
    ]);

    $tz = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTimeImmutable('now', $tz);
    $today = new DateTimeImmutable('today', $tz);
    $start30 = $today->modify('-30 days');
    $start7 = $today->modify('-6 days');

    $active = null;
    foreach ($sessions as $session) {
        if (trim((string)($session['acctstoptime'] ?? '')) === '') {
            $active = $session;
            break;
        }
    }
    $latest = $active ?: ($sessions[0] ?? []);

    $consumption = [];
    for ($i = 0; $i < 30; $i++) {
        $date = $start30->modify('+' . $i . ' days')->format('Y-m-d');
        $consumption[$date] = [
            'date' => $date,
            'download_bytes' => 0,
            'upload_bytes' => 0,
            'sessions' => 0,
        ];
    }

    $events = [];
    $dailyEvents = [];
    for ($i = 0; $i < 7; $i++) {
        $date = $start7->modify('+' . $i . ' days')->format('Y-m-d');
        $dailyEvents[$date] = [
            'date' => $date,
            'user' => 0,
            'admin' => 0,
            'nas' => 0,
            'nas_reboot' => 0,
            'lost' => 0,
            'other' => 0,
            'total' => 0,
        ];
    }

    foreach ($sessions as $session) {
        $start = reportTime($session['acctstarttime'] ?? null);
        $stop = reportTime($session['acctstoptime'] ?? null);
        // Uma sessão ainda aberta não é evento de queda. O gráfico deve
        // registrar apenas encerramentos informados pelo IXC/RADIUS.
        $eventTime = $stop;

        if ($start) {
            $day = $start->setTimezone($tz)->format('Y-m-d');
            if (isset($consumption[$day])) {
                $consumption[$day]['download_bytes'] += reportInt($session['acctoutputoctets'] ?? 0);
                $consumption[$day]['upload_bytes'] += reportInt($session['acctinputoctets'] ?? 0);
                $consumption[$day]['sessions']++;
            }
        }

        if ($eventTime) {
            $day = $eventTime->setTimezone($tz)->format('Y-m-d');
            if (isset($dailyEvents[$day])) {
                $cause = (string)($session['acctterminatecause'] ?? '');
                $kind = reportCauseKind($cause);
                if (isset($dailyEvents[$day][$kind])) $dailyEvents[$day][$kind]++;
                $dailyEvents[$day]['total']++;

                if (count($events) < 100) {
                    $events[] = [
                        'date_time' => $eventTime->setTimezone($tz)->format(DateTimeInterface::ATOM),
                        'kind' => $kind,
                        'cause' => $cause !== '' ? $cause : null,
                        'started_at' => $start?->setTimezone($tz)->format(DateTimeInterface::ATOM),
                        'stopped_at' => $stop?->setTimezone($tz)->format(DateTimeInterface::ATOM),
                    ];
                }
            }
        }
    }

    $start = reportTime($latest['acctstarttime'] ?? null);
    $stop = reportTime($latest['acctstoptime'] ?? null);

    $nasIp = (string)($latest['nasipaddress'] ?? '');
    $concentrator = reportConcentrator(
        $baseUrl,
        $token,
        isset($login['id_concentrador']) ? (string)$login['id_concentrador'] : null,
        $nasIp !== '' ? $nasIp : null
    );

    $ipv6 = reportPick($login, [
        'framed_pd_ipv6',
        'pd_ipv6',
        'ipv6',
        'ip_v6',
    ]);

    $interface =
        reportPick($login, ['conexao', 'interface'])
        ?? reportPick($latest, ['nasportid']);

    $authType =
        reportPick($login, ['autenticacao'])
        ?? (stripos((string)$interface, 'pppoe') !== false ? 'PPPoE' : null);

    $technology =
        reportPick($login, ['tipo_conexao', 'tipo_conexao_mapa'])
        ?? 'Fibra';

    $ixcConsumptionApi = reportIxcConsumptionApi(
        $baseUrl,
        $token,
        (int)$loginId,
        $start30->format('Y-m-d'),
        $today->format('Y-m-d')
    );

    $ixcConsumption = [
        'available' => false,
        'reason' => 'not_attempted',
        'daily' => [],
    ];

    $consumptionSource = 'RADIUS fallback';

    if (!empty($ixcConsumptionApi['available']) && !empty($ixcConsumptionApi['daily'])) {
        $consumption = [];
        foreach ($ixcConsumptionApi['daily'] as $row) {
            $consumption[$row['date']] = $row;
        }
        $consumptionSource = 'IXC Webservice radusuarios_consumo';
    } else {
        // Fallback secundário: endpoint interno usado pela tela Diagnóstico IXC.
        // Pode exigir sessão web do IXC.
        $ixcConsumption = reportIxcConsumption(
            $baseUrl,
            $token,
            (int)$loginId,
            $start30->format('Y-m-d'),
            $today->format('Y-m-d')
        );

        if (!empty($ixcConsumption['available']) && !empty($ixcConsumption['daily'])) {
            $consumption = [];
            foreach ($ixcConsumption['daily'] as $row) {
                $consumption[$row['date']] = $row;
            }
            $consumptionSource = 'IXC rel_22021.php?consumo';
        }
    }

    $totalDownload = array_sum(array_column($consumption, 'download_bytes'));
    $totalUpload = array_sum(array_column($consumption, 'upload_bytes'));

    reportOut([
        'success' => true,
        'source' => 'IXC Login + RADIUS',
        'login' => [
            'id' => $loginId !== '' ? (int)$loginId : null,
            'username' => $loginName,
            'online' => $active !== null || in_array(strtolower((string)($login['online'] ?? '')), ['s', 'sim', '1', 'true', 'online'], true),
            'connected_seconds' => reportDuration($start, $stop),
            'ipv4' => reportPick($latest, ['framedipaddress']) ?? reportPick($login, ['ip', 'ip_aux']),
            'ipv6' => $ipv6,
            'auth_type' => $authType,
            'technology' => $technology,
            'interface' => $interface,
            'mac' => reportPick($latest, ['callingstationid']) ?? reportPick($login, ['mac']),
            'download_current_raw' => reportPick($login, ['download_atual']),
            'upload_current_raw' => reportPick($login, ['upload_atual']),
            'disconnect_count_today' => reportPick($login, ['count_desconexao']),
            'last_disconnect_cause' => reportPick($login, ['motivo_desconexao']),
        ],
        'concentrator' => $concentrator,
        'current_session' => $latest ? [
            'id' => $latest['radacctid'] ?? null,
            'started_at' => $start?->setTimezone($tz)->format(DateTimeInterface::ATOM),
            'stopped_at' => $stop?->setTimezone($tz)->format(DateTimeInterface::ATOM),
            'seconds' => reportDuration($start, $stop),
            'download_bytes' => reportInt($latest['acctoutputoctets'] ?? 0),
            'upload_bytes' => reportInt($latest['acctinputoctets'] ?? 0),
        ] : null,
        'last_7_days' => [
            'daily' => array_values($dailyEvents),
            'events' => $events,
        ],
        'last_30_days' => [
            'source' => $consumptionSource,
            'daily' => array_values($consumption),
            'download_bytes' => $totalDownload,
            'upload_bytes' => $totalUpload,
            'total_bytes' => $totalDownload + $totalUpload,
            'ixc_webservice' => [
                'available' => (bool)($ixcConsumptionApi['available'] ?? false),
                'reason' => $ixcConsumptionApi['reason'] ?? null,
            ],
            'ixc_endpoint' => [
                'available' => (bool)($ixcConsumption['available'] ?? false),
                'http_code' => $ixcConsumption['http_code'] ?? null,
                'reason' => $ixcConsumption['reason'] ?? null,
            ],
        ],
        'legend' => [
            'user' => 'Requisitado pelo usuário',
            'admin' => 'Requisitado pelo Administrador',
            'nas' => 'Requisitado pelo Concentrador',
            'nas_reboot' => 'Reboot de concentrador',
            'lost' => 'Perda de Conexão',
            'other' => 'Outro',
        ],
    ]);

} catch (Throwable $e) {
    reportOut([
        'success' => false,
        'source' => 'IXC Login + RADIUS',
        'message' => 'Falha ao montar o diagnóstico do login IXC.',
    ], 502);
}
