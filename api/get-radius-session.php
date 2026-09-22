<?php

declare(strict_types=1);

/* Sessão PPPoE/Radius do IXC. Somente leitura. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/IXCConfig.php';
if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');

function radiusOut(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function unwrap(mixed $value): mixed {
    return is_array($value) && array_key_exists('_value', $value) ? $value['_value'] : $value;
}
function findByKey(mixed $data, string $key): mixed {
    if (!is_array($data)) return null;
    foreach ($data as $name => $value) {
        if (strcasecmp((string)$name, $key) === 0) {
            $candidate = unwrap($value);
            if (is_scalar($candidate) && (string)$candidate !== '') return $candidate;
        }
        $found = findByKey($value, $key);
        if ($found !== null) return $found;
    }
    return null;
}
function normalizeMac(string $mac): string {
    $hex = strtoupper(preg_replace('/[^A-F0-9]/i', '', $mac));
    return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : '';
}
function getOnuMac(array $device): string {
    foreach (['InternetGatewayDevice', 'Device'] as $root) {
        $interfaces = $device[$root]['LANDevice']['1']['LANEthernetInterfaceConfig'] ?? null;
        if (!is_array($interfaces)) continue;
        foreach ($interfaces as $interface) {
            $mac = normalizeMac((string)(unwrap($interface['MACAddress'] ?? null) ?? ''));
            if ($mac !== '') return $mac;
        }
    }
    return '';
}
function numberOrNull(mixed $value): ?int {
    return is_numeric($value) ? (int)$value : null;
}

function radiusIxcListOne(
    string $baseUrl,
    string $token,
    string $table,
    string $qtype,
    string $query
): array {
    $url = rtrim($baseUrl, '/') . '/webservice/v1/' . rawurlencode($table);
    $payload = json_encode([
        'qtype' => $qtype,
        'query' => $query,
        'oper' => '=',
        'page' => '1',
        'rp' => '1',
        'sortname' => $table . '.id',
        'sortorder' => 'desc',
    ]);

    $ch = curl_init($url);
    if ($ch === false) return [];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $status < 200 || $status >= 300) return [];
    $json = json_decode($body, true);
    if (!is_array($json)) return [];
    $records = $json['registros'] ?? $json['records'] ?? (array_is_list($json) ? $json : []);
    return is_array($records) && !empty($records[0]) && is_array($records[0])
        ? $records[0]
        : [];
}

try {
    $deviceId = trim((string)($_GET['device_id'] ?? ''));
    if ($deviceId === '') radiusOut(['success' => false, 'message' => 'device_id não informado.'], 400);

    $ixcConfig = getIxcConfig();
    $token = $ixcConfig['token'];
    $ixcBaseUrl = $ixcConfig['base_url'];
    $ixcUrl = $ixcBaseUrl . '/webservice/v1/radacct';

    $query = rawurlencode((string)json_encode(['_id' => $deviceId]));
    $raw = file_get_contents('http://127.0.0.1:7557/devices/?query=' . $query);
    $devices = json_decode($raw ?: '', true);
    if (!is_array($devices) || empty($devices[0])) throw new RuntimeException('Equipamento não encontrado no GenieACS.');
    $device = $devices[0];
    $mac = getOnuMac($device);
    $ip = (string)(findByKey($device, 'ExternalIPAddress') ?? '');
    if ($mac === '' && $ip === '') throw new RuntimeException('MAC e IP WAN não encontrados para consultar o RADIUS.');

    $filter = $mac !== ''
        ? ['qtype' => 'radacct.callingstationid', 'query' => $mac, 'oper' => '=']
        : ['qtype' => 'radacct.framedipaddress', 'query' => $ip, 'oper' => '='];
    $lookup = $mac !== '' ? 'MAC' : 'IP';
    $payload = json_encode($filter + ['page' => '1', 'rp' => '20', 'sortname' => 'radacct.radacctid', 'sortorder' => 'desc']);
    $ch = curl_init($ixcUrl);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'ixcsoft: listar', 'Authorization: Basic ' . base64_encode($token)], CURLOPT_POSTFIELDS => $payload]);
    $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
    if ($body === false) throw new RuntimeException('Falha na consulta IXC: ' . $error);
    if ($status < 200 || $status >= 300) throw new RuntimeException('IXC retornou HTTP ' . $status . '.');
    $response = json_decode($body, true);
    $records = is_array($response['registros'] ?? null) ? $response['registros'] : (is_array($response) ? $response : []);
    /* Alguns BRAS não gravam o MAC da ONU no RADIUS. Nessa situação,
       o IP PPPoE atual é a referência segura para a sessão ativa. */
    $hasRadiusRecord = false;
    foreach ($records as $candidate) {
        if (is_array($candidate) && !empty($candidate['radacctid'])) { $hasRadiusRecord = true; break; }
    }
    if (!$hasRadiusRecord && $mac !== '' && $ip !== '') {
        $lookup = 'IP';
        $payload = json_encode(['qtype' => 'radacct.framedipaddress', 'query' => $ip, 'oper' => '=', 'page' => '1', 'rp' => '20', 'sortname' => 'radacct.radacctid', 'sortorder' => 'desc']);
        $ch = curl_init($ixcUrl);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'ixcsoft: listar', 'Authorization: Basic ' . base64_encode($token)], CURLOPT_POSTFIELDS => $payload]);
        $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) throw new RuntimeException('Falha ao consultar RADIUS pelo IP PPPoE.');
        $response = json_decode($body, true);
        $records = is_array($response['registros'] ?? null) ? $response['registros'] : (is_array($response) ? $response : []);
    }
    $active = null;
    foreach ($records as $record) {
        if (!is_array($record) || empty($record['radacctid'])) continue;
        if (trim((string)($record['acctstoptime'] ?? '')) === '') { $active = $record; break; }
    }
    $record = $active;
    if ($record === null) {
        foreach ($records as $candidate) {
            if (is_array($candidate) && !empty($candidate['radacctid'])) { $record = $candidate; break; }
        }
    }
    if ($record === null) radiusOut(['success' => true, 'source' => 'IXC/RADIUS', 'online' => false, 'lookup' => $lookup, 'message' => 'Nenhuma sessão RADIUS encontrada.']);
    $sampleTime = $record['acctupdatetime'] ?? $record['updated_at'] ?? $record['last_update'] ?? null;

    $radiusUsername = trim((string)($record['username'] ?? ''));
    $ixcLogin = $radiusUsername !== ''
        ? radiusIxcListOne(
            $ixcBaseUrl,
            $token,
            'radusuarios',
            'radusuarios.login',
            $radiusUsername
        )
        : [];

    radiusOut(['success' => true, 'source' => 'IXC/RADIUS', 'online' => $active !== null, 'lookup' => $lookup, 'mac' => $mac ?: null,
        'ixc_login_id' => $ixcLogin['id'] ?? null,
        'session' => [
            'session_id' => $record['radacctid'] ?? null,
            'username' => $record['username'] ?? null,
            'ip' => $record['framedipaddress'] ?? null,
            'bras' => $record['nasipaddress'] ?? null,
            'interface' => $record['nasportid'] ?? null,
            'started_at' => $record['acctstarttime'] ?? null,
            'stopped_at' => $record['acctstoptime'] ?? null,
            'sample_time' => $sampleTime,
            'seconds' => numberOrNull($record['acctsessiontime'] ?? null),
            // No RADIUS, input = upload do assinante e output = download do assinante.
            'upload_bytes' => numberOrNull($record['acctinputoctets'] ?? null),
            'download_bytes' => numberOrNull($record['acctoutputoctets'] ?? null),
            // Mantidos por compatibilidade com telas antigas.
            'bytes_received' => numberOrNull($record['acctinputoctets'] ?? null),
            'bytes_sent' => numberOrNull($record['acctoutputoctets'] ?? null)
        ]]);
} catch (Throwable $e) { radiusOut(['success' => false, 'source' => 'IXC/RADIUS', 'message' => $e->getMessage()], 502); }
