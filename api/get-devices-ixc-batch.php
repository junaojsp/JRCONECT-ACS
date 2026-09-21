<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
if (function_exists('requireLogin')) requireLogin();

header('Content-Type: application/json; charset=utf-8');

function jrBatchOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jrBatchConfig(): array
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

function jrBatchSerial(string $value): string
{
    return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', trim($value)));
}

function jrBatchPayload(string $table, string $qtype, string $query): string
{
    return (string)json_encode([
        'qtype' => $qtype,
        'query' => $query,
        'oper' => '=',
        'page' => '1',
        'rp' => '1',
        'sortname' => $table . '.id',
        'sortorder' => 'desc',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function jrBatchMulti(
    string $baseUrl,
    string $token,
    array $jobs
): array {
    if (!$jobs) return [];

    $multi = curl_multi_init();
    $handles = [];
    $authorization = 'Basic ' . base64_encode($token);

    foreach ($jobs as $key => $job) {
        $table = (string)$job['table'];
        $url = $baseUrl . '/webservice/v1/' . rawurlencode($table);
        $ch = curl_init($url);
        if ($ch === false) continue;

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'ixcsoft: listar',
                'Authorization: ' . $authorization,
            ],
            CURLOPT_POSTFIELDS => jrBatchPayload(
                $table,
                (string)$job['qtype'],
                (string)$job['query']
            ),
        ]);

        curl_multi_add_handle($multi, $ch);
        $handles[(string)$key] = $ch;
    }

    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            $selected = curl_multi_select($multi, 1.0);
            if ($selected === -1) usleep(10000);
        }
    } while ($running && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $json = is_string($body) ? json_decode($body, true) : null;
        $records = [];

        if ($http >= 200 && $http < 300 && is_array($json)) {
            $records = $json['registros']
                ?? $json['records']
                ?? (array_is_list($json) ? $json : []);
        }

        $out[$key] = is_array($records) && !empty($records[0]) && is_array($records[0])
            ? $records[0]
            : [];

        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }

    curl_multi_close($multi);
    return $out;
}

function jrBatchPick(array $record, array $keys): mixed
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $record)) continue;
        $value = $record[$key];
        if ($value === null) continue;
        if (is_string($value) && trim($value) === '') continue;
        return $value;
    }
    return null;
}

function jrBatchNumber(mixed $value): ?float
{
    if ($value === null || $value === '') return null;
    $text = str_replace(',', '.', trim((string)$value));
    return is_numeric($text) ? (float)$text : null;
}

try {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    $body = json_decode($raw ?: '{}', true);
    if (!is_array($body)) {
        jrBatchOut(['success' => false, 'message' => 'JSON inválido.'], 400);
    }

    $serials = $body['serial_numbers'] ?? [];
    if (!is_array($serials)) {
        jrBatchOut(['success' => false, 'message' => 'serial_numbers deve ser uma lista.'], 400);
    }

    $serials = array_values(array_unique(array_filter(array_map(
        fn($value) => jrBatchSerial((string)$value),
        array_slice($serials, 0, 100)
    ))));

    if (!$serials) {
        jrBatchOut([
            'success' => true,
            'source' => 'IXC',
            'devices' => [],
        ]);
    }

    [$baseUrl, $token] = jrBatchConfig();

    $fiberJobs = [];
    foreach ($serials as $serial) {
        $fiberJobs[$serial] = [
            'table' => 'radpop_radio_cliente_fibra',
            'qtype' => 'radpop_radio_cliente_fibra.mac',
            'query' => $serial,
        ];
    }

    $fiberRows = jrBatchMulti($baseUrl, $token, $fiberJobs);

    $loginJobs = [];
    foreach ($fiberRows as $serial => $fiber) {
        if (!$fiber) continue;
        $idLogin = trim((string)($fiber['id_login'] ?? ''));
        if ($idLogin === '' || !preg_match('/^\d+$/', $idLogin)) continue;

        $loginJobs[$serial] = [
            'table' => 'radusuarios',
            'qtype' => 'radusuarios.id',
            'query' => $idLogin,
        ];
    }

    $loginRows = jrBatchMulti($baseUrl, $token, $loginJobs);

    $devices = [];
    foreach ($serials as $serial) {
        $fiber = $fiberRows[$serial] ?? [];
        $login = $loginRows[$serial] ?? [];

        if (!$fiber && !$login) {
            $devices[$serial] = [
                'found' => false,
                'source' => 'IXC',
            ];
            continue;
        }

        $devices[$serial] = [
            'found' => true,
            'source' => $login ? 'IXC Cliente Fibra + Login' : 'IXC Cliente Fibra',
            'ip' => jrBatchPick($login, ['ip', 'ip_aux']),
            'ipv6' => jrBatchPick($login, ['ipv6', 'ip_v6', 'framed_ipv6', 'framed_pd_ipv6']),
            'pppoe_username' => jrBatchPick($login, ['login', 'username', 'usuario']),
            'vlan' => jrBatchPick($fiber, ['vlan_pppoe', 'vlan', 'vlan_uplink'])
                ?? jrBatchPick($login, ['vlan']),
            'rx_power' => jrBatchNumber(jrBatchPick($fiber, ['sinal_rx'])),
            'tx_power' => jrBatchNumber(jrBatchPick($fiber, ['sinal_tx'])),
            'temperature' => jrBatchNumber(jrBatchPick($fiber, ['temperatura'])),
            'voltage' => jrBatchNumber(jrBatchPick($fiber, ['voltagem'])),
            'last_signal_update' => jrBatchPick($fiber, ['data_sinal']),
            'online' => jrBatchPick($login, ['online']),
            'last_error' => jrBatchPick($fiber, ['causa_ultima_queda', 'motivo_ultima_queda']),
            'id_login' => jrBatchPick($fiber, ['id_login']),
            'id_contrato' => jrBatchPick($fiber, ['id_contrato']),
            'id_transmissor' => jrBatchPick($fiber, ['id_transmissor']),
            'pon_id' => jrBatchPick($fiber, ['ponid']),
            'slot' => jrBatchPick($fiber, ['slotno']),
            'pon' => jrBatchPick($fiber, ['ponno']),
            'onu_number' => jrBatchPick($fiber, ['onu_numero']),
        ];
    }

    jrBatchOut([
        'success' => true,
        'source' => 'IXC',
        'source_policy' => [
            'network' => 'IXC principal / TR-069 fallback',
            'optical' => 'IXC principal / TR-069 fallback',
            'equipment' => 'TR-069 / GenieACS',
            'wifi' => 'TR-069 / GenieACS',
        ],
        'count' => count($devices),
        'devices' => $devices,
    ]);

} catch (Throwable $e) {
    jrBatchOut([
        'success' => false,
        'source' => 'IXC',
        'message' => 'Falha ao consultar dados operacionais no IXC.',
    ], 502);
}
