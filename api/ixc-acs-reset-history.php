<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

function jrResetOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jrResetConfig(): array
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

function jrResetRequest(
    string $baseUrl,
    string $token,
    string $table,
    array $payload
): array {
    $ch = curl_init($baseUrl . '/webservice/v1/' . rawurlencode($table));
    if ($ch === false) {
        return ['http' => 0, 'json' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
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

    return [
        'http' => $http,
        'json' => is_string($body) ? json_decode($body, true) : null,
    ];
}

function jrResetRecords(array $response): array
{
    if (($response['http'] ?? 0) < 200 || ($response['http'] ?? 0) >= 300) {
        return [];
    }

    $json = $response['json'] ?? null;
    if (!is_array($json)) return [];

    $records = $json['registros']
        ?? $json['records']
        ?? (array_is_list($json) ? $json : []);

    return is_array($records)
        ? array_values(array_filter($records, 'is_array'))
        : [];
}

function jrResetField(array $keys, array $candidates): ?string
{
    $lower = [];
    foreach ($keys as $key) {
        $lower[strtolower((string)$key)] = (string)$key;
    }

    foreach ($candidates as $candidate) {
        $c = strtolower($candidate);
        if (isset($lower[$c])) return $lower[$c];
    }

    return null;
}

function jrResetRouteCacheFile(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'jrconect_ixc_acs_history_route.json';
}

function jrResetLoadRouteCache(): ?array
{
    $file = jrResetRouteCacheFile();
    if (!is_file($file)) return null;

    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) return null;

    $checkedAt = (int)($data['checked_at'] ?? 0);
    $ttl = !empty($data['route']) ? 86400 : 21600;

    if ($checkedAt <= 0 || (time() - $checkedAt) > $ttl) return null;
    return $data;
}

function jrResetSaveRouteCache(array $data): void
{
    $data['checked_at'] = time();
    @file_put_contents(
        jrResetRouteCacheFile(),
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function jrResetDiscoverRoute(string $baseUrl, string $token): array
{
    // Lista curta e fechada: somente consultas de leitura (listar).
    $candidates = [
        'radacs_historico_operacoes',
        'radacs_historico_operacao',
        'radacs_operacoes',
        'radacs_operacao',
        'radacs_log_operacoes',
        'radacs_logs_operacoes',
        'acs_historico_operacoes',
        'acs_historico_operacao',
        'acs_operacoes',
        'acs_operacao',
        'acs_device_operations',
        'radacs_device_operations',
        'acs_operations',
    ];

    $attempts = [];
    foreach ($candidates as $table) {
        $response = jrResetRequest(
            $baseUrl,
            $token,
            $table,
            [
                'qtype' => $table . '.id',
                'query' => '0',
                'oper' => '>',
                'page' => '1',
                'rp' => '5',
                'sortname' => $table . '.id',
                'sortorder' => 'desc',
            ]
        );

        $records = jrResetRecords($response);
        $keys = !empty($records[0]) ? array_keys($records[0]) : [];

        $attempts[] = [
            'route' => $table,
            'http' => (int)($response['http'] ?? 0),
            'record_count' => count($records),
            'field_names' => $keys,
        ];

        if (!$records) continue;

        $dateField = jrResetField($keys, [
            'data_hora',
            'datahora',
            'created_at',
            'create_date',
            'data',
            'date',
            'datetime',
            'timestamp',
            'last_update',
        ]);
        $operationField = jrResetField($keys, [
            'operacao',
            'operation',
            'acao',
            'action',
            'tipo_operacao',
            'type',
        ]);
        $messageField = jrResetField($keys, [
            'mensagem',
            'message',
            'descricao',
            'description',
            'obs',
            'observacao',
        ]);
        $statusField = jrResetField($keys, ['status', 'situacao', 'result', 'resultado']);

        if ($dateField && ($operationField || $messageField)) {
            return [
                'route' => $table,
                'date_field' => $dateField,
                'operation_field' => $operationField,
                'message_field' => $messageField,
                'status_field' => $statusField,
                'field_names' => $keys,
                'attempts' => $attempts,
            ];
        }
    }

    return [
        'route' => null,
        'attempts' => $attempts,
    ];
}

function jrResetParseDate(mixed $value): ?DateTimeImmutable
{
    if ($value === null || $value === '') return null;

    try {
        return new DateTimeImmutable((string)$value, new DateTimeZone('America/Sao_Paulo'));
    } catch (Throwable) {
        return null;
    }
}

function jrResetIsResetOperation(array $record, ?string $operationField, ?string $messageField): array
{
    $operation = $operationField ? (string)($record[$operationField] ?? '') : '';
    $message = $messageField ? (string)($record[$messageField] ?? '') : '';
    $text = mb_strtolower(trim($operation . ' ' . $message), 'UTF-8');

    $isReboot = (bool)preg_match(
        '/reinici|reinicializ|reboot|restart/u',
        $text
    );

    $isReset = (bool)preg_match(
        '/(^|[^a-z])(reset|factory reset|restaurar.*f[aá]brica|padr[aã]o de f[aá]brica)([^a-z]|$)/u',
        $text
    );

    return [
        'match' => $isReboot || $isReset,
        'kind' => $isReset ? 'reset' : ($isReboot ? 'reboot' : null),
    ];
}

try {
    [$baseUrl, $token] = jrResetConfig();

    $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
    $routeInfo = $forceRefresh ? null : jrResetLoadRouteCache();
    if ($routeInfo === null) {
        $routeInfo = jrResetDiscoverRoute($baseUrl, $token);
        jrResetSaveRouteCache($routeInfo);
    }

    if (empty($routeInfo['route'])) {
        $attempts = $routeInfo['attempts'] ?? [];
        $permissionDenied = false;
        foreach ($attempts as $attempt) {
            if (in_array((int)($attempt['http'] ?? 0), [401, 403], true)) {
                $permissionDenied = true;
                break;
            }
        }

        jrResetOut([
            'success' => true,
            'available' => false,
            'source' => 'IXC ACS',
            'read_only' => true,
            'reason' => $permissionDenied
                ? 'permission'
                : 'route_not_identified',
            'message' => $permissionDenied
                ? 'A API IXC respondeu sem permissão para o histórico ACS.'
                : 'A rota do Histórico de Operações do IXC ACS ainda não foi identificada.',
            'diagnostic' => [
                'attempts' => $attempts,
            ],
        ]);
    }

    $table = (string)$routeInfo['route'];
    $dateField = (string)$routeInfo['date_field'];
    $operationField = $routeInfo['operation_field'] ?? null;
    $messageField = $routeInfo['message_field'] ?? null;
    $statusField = $routeInfo['status_field'] ?? null;

    $tz = new DateTimeZone('America/Sao_Paulo');
    $today = new DateTimeImmutable('today', $tz);
    $start = $today->modify('-6 days');

    $response = jrResetRequest(
        $baseUrl,
        $token,
        $table,
        [
            'qtype' => $table . '.' . $dateField,
            'query' => $start->format('Y-m-d 00:00:00'),
            'oper' => '>=',
            'page' => '1',
            'rp' => '2000',
            'sortname' => $table . '.' . $dateField,
            'sortorder' => 'desc',
        ]
    );

    $records = jrResetRecords($response);

    // Alguns formulários não aceitam filtro direto por data no webservice.
    // Nesse caso, busca-se apenas um lote recente por ID e o filtro é local.
    if (!$records && ($response['http'] ?? 0) >= 200 && ($response['http'] ?? 0) < 300) {
        $response = jrResetRequest(
            $baseUrl,
            $token,
            $table,
            [
                'qtype' => $table . '.id',
                'query' => '0',
                'oper' => '>',
                'page' => '1',
                'rp' => '2000',
                'sortname' => $table . '.id',
                'sortorder' => 'desc',
            ]
        );
        $records = jrResetRecords($response);
    }

    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $key = $start->modify('+' . $i . ' days')->format('Y-m-d');
        $days[$key] = [
            'date' => $key,
            'count' => 0,
            'reboots' => 0,
            'resets' => 0,
        ];
    }

    $events = [];
    foreach ($records as $record) {
        $date = jrResetParseDate($record[$dateField] ?? null);
        if (!$date) continue;

        $dayKey = $date->setTimezone($tz)->format('Y-m-d');
        if (!isset($days[$dayKey])) continue;

        $match = jrResetIsResetOperation($record, $operationField, $messageField);
        if (!$match['match']) continue;

        $days[$dayKey]['count']++;
        if ($match['kind'] === 'reset') $days[$dayKey]['resets']++;
        if ($match['kind'] === 'reboot') $days[$dayKey]['reboots']++;

        if (count($events) < 25) {
            $events[] = [
                'date_time' => $date->setTimezone($tz)->format(DateTimeInterface::ATOM),
                'kind' => $match['kind'],
                'status' => $statusField ? ($record[$statusField] ?? null) : null,
            ];
        }
    }

    $todayKey = $today->format('Y-m-d');
    $total7 = array_sum(array_column($days, 'count'));

    jrResetOut([
        'success' => true,
        'available' => true,
        'source' => 'IXC ACS - Histórico de Operações',
        'read_only' => true,
        'route' => $table,
        'today' => (int)($days[$todayKey]['count'] ?? 0),
        'last_7_days' => array_values($days),
        'total_7_days' => $total7,
        'average_7_days' => round($total7 / 7, 1),
        'recent_events' => $events,
        'operation_types' => [
            'reboot' => 'Reinício',
            'reset' => 'Reset / padrão de fábrica',
        ],
    ]);

} catch (Throwable $e) {
    jrResetOut([
        'success' => false,
        'available' => false,
        'source' => 'IXC ACS',
        'read_only' => true,
        'message' => 'Falha ao consultar o histórico ACS no IXC.',
    ], 502);
}
