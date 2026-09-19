<?php

declare(strict_types=1);

/* Sessão PPPoE/Radius do IXC. Somente leitura. */
require_once __DIR__ . '/../config/config.php';
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
function numberOrNull(mixed $value): ?int {
    return is_numeric($value) ? (int)$value : null;
}

try {
    $deviceId = trim((string)($_GET['device_id'] ?? ''));
    if ($deviceId === '') radiusOut(['success' => false, 'message' => 'device_id não informado.'], 400);

    /* Credencial já configurada no endpoint IXC existente; evita duplicação. */
    $opticalSource = (string)file_get_contents(__DIR__ . '/get-onu-optical.php');
    if (!preg_match('/\\$ixcToken\\s*=\\s*\'([^\']+)\'/', $opticalSource, $tokenMatch)) {
        throw new RuntimeException('Token IXC não localizado na configuração existente.');
    }
    $token = $tokenMatch[1];
    if (!preg_match('/\\$ixcBaseUrl\\s*=\\s*\'([^\']+)\'/', $opticalSource, $urlMatch)) {
        throw new RuntimeException('URL IXC não localizada na configuração existente.');
    }
    $ixcUrl = rtrim($urlMatch[1], '/') . '/webservice/v1/radacct';

    $query = rawurlencode((string)json_encode(['_id' => $deviceId]));
    $raw = file_get_contents('http://127.0.0.1:7557/devices/?query=' . $query);
    $devices = json_decode($raw ?: '', true);
    if (!is_array($devices) || empty($devices[0])) throw new RuntimeException('Equipamento não encontrado no GenieACS.');
    $device = $devices[0];
    $mac = normalizeMac((string)(findByKey($device, 'MACAddress') ?? ''));
    $ip = (string)(findByKey($device, 'ExternalIPAddress') ?? '');
    if ($mac === '' && $ip === '') throw new RuntimeException('MAC e IP WAN não encontrados para consultar o RADIUS.');

    $filter = $mac !== ''
        ? ['qtype' => 'radacct.callingstationid', 'query' => $mac, 'oper' => '=']
        : ['qtype' => 'radacct.framedipaddress', 'query' => $ip, 'oper' => '='];
    $payload = json_encode($filter + ['page' => '1', 'rp' => '20', 'sortname' => 'radacct.radacctid', 'sortorder' => 'desc']);
    $ch = curl_init($ixcUrl);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'ixcsoft: listar', 'Authorization: Basic ' . base64_encode($token)], CURLOPT_POSTFIELDS => $payload]);
    $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
    if ($body === false) throw new RuntimeException('Falha na consulta IXC: ' . $error);
    if ($status < 200 || $status >= 300) throw new RuntimeException('IXC retornou HTTP ' . $status . '.');
    $response = json_decode($body, true);
    $records = is_array($response['registros'] ?? null) ? $response['registros'] : (is_array($response) ? $response : []);
    $active = null;
    foreach ($records as $record) {
        if (is_array($record) && trim((string)($record['acctstoptime'] ?? '')) === '') { $active = $record; break; }
    }
    $record = $active ?? (is_array($records[0] ?? null) ? $records[0] : null);
    if ($record === null) radiusOut(['success' => true, 'source' => 'IXC/RADIUS', 'online' => false, 'lookup' => $mac !== '' ? 'MAC' : 'IP', 'message' => 'Nenhuma sessão RADIUS encontrada.']);
    radiusOut(['success' => true, 'source' => 'IXC/RADIUS', 'online' => $active !== null, 'lookup' => $mac !== '' ? 'MAC' : 'IP', 'mac' => $mac ?: null,
        'session' => ['username' => $record['username'] ?? null, 'ip' => $record['framedipaddress'] ?? null, 'bras' => $record['nasipaddress'] ?? null,
            'interface' => $record['nasportid'] ?? null, 'started_at' => $record['acctstarttime'] ?? null, 'stopped_at' => $record['acctstoptime'] ?? null,
            'seconds' => numberOrNull($record['acctsessiontime'] ?? null), 'bytes_received' => numberOrNull($record['acctinputoctets'] ?? null), 'bytes_sent' => numberOrNull($record['acctoutputoctets'] ?? null)]]);
} catch (Throwable $e) { radiusOut(['success' => false, 'source' => 'IXC/RADIUS', 'message' => $e->getMessage()], 502); }
