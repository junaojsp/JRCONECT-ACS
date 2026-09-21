<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
if (function_exists('requireLogin')) requireLogin();

header('Content-Type: application/json; charset=utf-8');

function diagOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function diagConfig(): array
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

function diagValue(mixed $node): mixed
{
    if (is_array($node) && array_key_exists('_value', $node)) return $node['_value'];
    if (is_scalar($node) || $node === null) return $node;
    return null;
}

function diagFindByKey(mixed $node, string $needle, int $depth = 0): mixed
{
    if (!is_array($node) || $depth > 16) return null;

    foreach ($node as $key => $value) {
        if (strcasecmp((string)$key, $needle) === 0) {
            $scalar = diagValue($value);
            if ($scalar !== null && trim((string)$scalar) !== '') return $scalar;
        }
    }

    foreach ($node as $value) {
        if (!is_array($value)) continue;
        $found = diagFindByKey($value, $needle, $depth + 1);
        if ($found !== null && trim((string)$found) !== '') return $found;
    }

    return null;
}

function diagMac(mixed $device): string
{
    foreach (['MACAddress', 'MACAddressOverride', 'PhysAddress', 'BSSID'] as $key) {
        $value = diagFindByKey($device, $key);
        if ($value === null) continue;
        $mac = strtoupper(preg_replace('/[^0-9A-F]/i', '', (string)$value) ?? '');
        if (strlen($mac) === 12) {
            return implode(':', str_split($mac, 2));
        }
    }
    return '';
}

function diagIxcList(
    string $baseUrl,
    string $token,
    string $table,
    string $qtype,
    string $query,
    int $rp = 20
): array {
    $url = $baseUrl . '/webservice/v1/' . rawurlencode($table);
    $payload = json_encode([
        'qtype' => $qtype,
        'query' => $query,
        'oper' => '=',
        'page' => '1',
        'rp' => (string)$rp,
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
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $http < 200 || $http >= 300) return [];
    $json = json_decode($body, true);
    if (!is_array($json)) return [];

    $rows = $json['registros'] ?? $json['records'] ?? (array_is_list($json) ? $json : []);
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function diagActiveSession(array $rows): ?array
{
    foreach ($rows as $row) {
        if (trim((string)($row['acctstoptime'] ?? '')) === '') return $row;
    }
    return $rows[0] ?? null;
}

function diagReadSse(
    string $baseUrl,
    string $token,
    int $loginId,
    int $seconds = 4
): array {
    $url = $baseUrl . '/aplicativo/radusuarios/rel_22021.php?trafego=' . $loginId;
    $buffer = '';
    $started = microtime(true);
    $ch = curl_init($url);

    if ($ch === false) {
        return ['http' => 0, 'content_type' => null, 'body' => '', 'error' => 'curl_init_failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $seconds + 2,
        CURLOPT_HTTPHEADER => [
            'Accept: text/event-stream',
            'Cache-Control: no-cache',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
            'X-Requested-With: XMLHttpRequest',
        ],
        CURLOPT_WRITEFUNCTION => static function($ch, string $chunk) use (&$buffer, $started, $seconds): int {
            $buffer .= $chunk;
            if (strlen($buffer) > 32768) $buffer = substr($buffer, -32768);
            if ((microtime(true) - $started) >= $seconds) return 0;
            return strlen($chunk);
        },
    ]);

    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'http' => $http,
        'content_type' => $contentType !== '' ? $contentType : null,
        'redirect' => $redirect !== '',
        'body' => $buffer,
        'error' => $error !== '' ? $error : null,
    ];
}

function diagParseSse(string $body): array
{
    $event = null;
    $samples = [];
    $data = [];

    foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
        if ($line === '') {
            if ($event === 'trafego' && $data) {
                $raw = implode("\n", $data);
                $json = json_decode($raw, true);
                if (is_array($json) && isset($json['rx'], $json['tx']) && is_numeric($json['rx']) && is_numeric($json['tx'])) {
                    $rx = max(0.0, (float)$json['rx']);
                    $tx = max(0.0, (float)$json['tx']);
                    $samples[] = [
                        'rx_bps' => $rx,
                        'tx_bps' => $tx,
                        'download_mbps' => $rx / 1000000,
                        'upload_mbps' => $tx / 1000000,
                    ];
                }
            }
            $event = null;
            $data = [];
            continue;
        }

        if (str_starts_with($line, 'event:')) {
            $event = trim(substr($line, 6));
        } elseif (str_starts_with($line, 'data:')) {
            $data[] = ltrim(substr($line, 5));
        }
    }

    return $samples;
}

try {
    $deviceId = trim((string)($_GET['device_id'] ?? ''));
    if ($deviceId === '') {
        diagOut(['success' => false, 'message' => 'device_id não informado.'], 400);
    }

    [$baseUrl, $token] = diagConfig();

    $query = rawurlencode((string)json_encode(['_id' => $deviceId]));
    $raw = @file_get_contents('http://127.0.0.1:7557/devices/?query=' . $query);
    $devices = json_decode($raw ?: '', true);

    if (!is_array($devices) || empty($devices[0])) {
        diagOut(['success' => false, 'message' => 'Equipamento não encontrado no GenieACS.'], 404);
    }

    $device = $devices[0];
    $ip = trim((string)(diagFindByKey($device, 'ExternalIPAddress') ?? ''));
    $mac = diagMac($device);
    $serial = (string)($device['_deviceId']['_SerialNumber'] ?? '');

    $radiusRows = [];
    $lookup = null;

    if ($ip !== '') {
        $radiusRows = diagIxcList(
            $baseUrl,
            $token,
            'radacct',
            'radacct.framedipaddress',
            $ip,
            20
        );
        $lookup = 'IP';
    }

    if (!$radiusRows && $mac !== '') {
        $radiusRows = diagIxcList(
            $baseUrl,
            $token,
            'radacct',
            'radacct.callingstationid',
            $mac,
            20
        );
        $lookup = 'MAC';
    }

    $session = diagActiveSession($radiusRows);
    if (!$session) {
        diagOut([
            'success' => true,
            'device_id' => $deviceId,
            'serial' => $serial ?: null,
            'ip' => $ip ?: null,
            'mac' => $mac ?: null,
            'radius' => [
                'found' => false,
                'lookup' => $lookup,
            ],
            'ixc_login' => null,
            'sse' => null,
            'message' => 'Nenhuma sessão RADIUS encontrada para o equipamento.',
        ]);
    }

    $username = trim((string)($session['username'] ?? ''));
    $loginRows = $username !== ''
        ? diagIxcList(
            $baseUrl,
            $token,
            'radusuarios',
            'radusuarios.login',
            $username,
            5
        )
        : [];

    $login = $loginRows[0] ?? null;
    $loginId = is_array($login) && isset($login['id']) && is_numeric($login['id'])
        ? (int)$login['id']
        : null;

    $sseResult = null;
    if ($loginId !== null) {
        $stream = diagReadSse($baseUrl, $token, $loginId, 4);
        $samples = diagParseSse((string)($stream['body'] ?? ''));
        $latest = $samples ? $samples[count($samples) - 1] : null;

        $sseResult = [
            'http_code' => $stream['http'],
            'content_type' => $stream['content_type'],
            'redirect_detected' => $stream['redirect'],
            'curl_error' => $stream['error'],
            'event_count' => count($samples),
            'latest' => $latest,
            'working' => $latest !== null,
        ];
    }

    diagOut([
        'success' => true,
        'device_id' => $deviceId,
        'serial' => $serial ?: null,
        'ip' => $ip ?: null,
        'mac' => $mac ?: null,
        'radius' => [
            'found' => true,
            'lookup' => $lookup,
            'username' => $username ?: null,
            'session_id' => $session['radacctid'] ?? null,
            'nas_ip' => $session['nasipaddress'] ?? null,
            'interface' => $session['nasportid'] ?? null,
        ],
        'ixc_login' => [
            'found' => $loginId !== null,
            'id' => $loginId,
            'login' => $username ?: null,
        ],
        'sse' => $sseResult,
    ]);

} catch (Throwable $e) {
    diagOut([
        'success' => false,
        'message' => 'Falha no diagnóstico do tráfego IXC.',
    ], 502);
}
