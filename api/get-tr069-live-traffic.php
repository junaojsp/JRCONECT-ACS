<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use App\GenieACS;

if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');

function trOut(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function trGet(array $device, string $path): array {
    $node = $device;
    foreach (explode('.', $path) as $key) {
        if (!isset($node[$key]) || !is_array($node[$key])) {
            return ['exists'=>false,'value'=>null,'timestamp'=>null];
        }
        $node = $node[$key];
    }
    return [
        'exists'=>true,
        'value'=>$node['_value'] ?? null,
        'timestamp'=>$node['_timestamp'] ?? null,
    ];
}

function trNumber(mixed $value): ?float {
    if (is_int($value) || is_float($value)) return max(0.0, (float)$value);
    if (!is_string($value)) return null;
    $value = trim($value);
    if ($value === '' || !preg_match('/^\d+(?:\.\d+)?$/', $value)) return null;
    return max(0.0, (float)$value);
}

function trCandidatePairs(array $device): array {
    $pairs = [];
    $product = strtoupper((string)($device['_deviceId']['_ProductClass'] ?? ''));
    $isHg6143 = str_contains($product, 'HG6143D3');

    // FiberHome HG6143D3: este firmware publica os totais WAN aqui.
    $common = 'InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig';
    if ($isHg6143) {
        $pairs[] = [
            'kind'=>'TR-098 WAN common',
            'download'=>$common.'.TotalBytesReceived',
            'upload'=>$common.'.TotalBytesSent',
            'priority'=>150,
        ];
    }

    // TR-098 PPPoE / IPoE.
    for ($wd=1; $wd<=4; $wd++) {
        for ($conn=1; $conn<=8; $conn++) {
            foreach (['WANPPPConnection','WANIPConnection'] as $type) {
                for ($i=1; $i<=4; $i++) {
                    $base="InternetGatewayDevice.WANDevice.$wd.WANConnectionDevice.$conn.$type.$i";
                    foreach ([
                        ['Stats.BytesReceived','Stats.BytesSent'],
                        ['Stats.EthernetBytesReceived','Stats.EthernetBytesSent'],
                    ] as [$rx,$tx]) {
                        $pairs[]=[
                            'kind'=>'TR-098 '.$type,
                            'download'=>$base.'.'.$rx,
                            'upload'=>$base.'.'.$tx,
                            'priority'=>$type==='WANPPPConnection'?130:120,
                        ];
                    }
                }
            }
        }
    }

    // WANCommon fallback for outros TR-098.
    if (!$isHg6143) {
        $pairs[] = [
            'kind'=>'TR-098 WAN common',
            'download'=>$common.'.TotalBytesReceived',
            'upload'=>$common.'.TotalBytesSent',
            'priority'=>110,
        ];
    }

    // TR-181 PPP and IP interfaces.
    foreach (['PPP'=>125,'IP'=>105,'Ethernet'=>95] as $family=>$priority) {
        for ($i=1; $i<=16; $i++) {
            $base="Device.$family.Interface.$i.Stats";
            $pairs[]=[
                'kind'=>"TR-181 $family",
                'download'=>$base.'.BytesReceived',
                'upload'=>$base.'.BytesSent',
                'priority'=>$priority,
            ];
        }
    }

    return $pairs;
}

function trSelectPair(array $device): ?array {
    $best = null;
    foreach (trCandidatePairs($device) as $pair) {
        $rx = trGet($device, $pair['download']);
        $tx = trGet($device, $pair['upload']);
        if (!$rx['exists'] || !$tx['exists']) continue;

        $down = trNumber($rx['value']);
        $up = trNumber($tx['value']);
        if ($down === null || $up === null) continue;

        $score = (int)$pair['priority'];
        if ($down > 0 || $up > 0) $score += 15;

        $row = $pair + [
            'download_bytes'=>$down,
            'upload_bytes'=>$up,
            'download_timestamp'=>$rx['timestamp'],
            'upload_timestamp'=>$tx['timestamp'],
            'score'=>$score,
        ];

        if ($best === null || $row['score'] > $best['score']) $best = $row;
    }
    return $best;
}

function trCachePath(string $deviceId, string $downloadPath, string $uploadPath): string {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'jrconect_tr069_traffic_'
        . sha1($deviceId.'|'.$downloadPath.'|'.$uploadPath)
        . '.json';
}

function trRefreshStatePath(string $deviceId): string {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'jrconect_tr069_refresh_'
        . sha1($deviceId)
        . '.json';
}

function trLoadState(string $path): array {
    if (!is_file($path)) return [];
    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function trSaveState(string $path, array $state): void {
    @file_put_contents(
        $path,
        json_encode($state, JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function trDelta(float $current, float $previous): ?float {
    if ($current >= $previous) return $current - $previous;

    // TR-098 usa muitos contadores unsignedInt de 32 bits.
    $max32 = 4294967296.0;
    if ($previous < $max32 && $current < $max32 && $previous > ($max32 * 0.75)) {
        return ($max32 - $previous) + $current;
    }

    return null;
}

function trCalculate(string $deviceId, array $pair, bool $refreshSucceeded): array {
    $now = microtime(true);
    $file = trCachePath($deviceId, $pair['download'], $pair['upload']);
    $previous = null;

    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) $previous = $decoded;
    }

    @file_put_contents($file, json_encode([
        'at'=>$now,
        'down'=>$pair['download_bytes'],
        'up'=>$pair['upload_bytes'],
        'down_ts'=>$pair['download_timestamp'],
        'up_ts'=>$pair['upload_timestamp'],
    ]), LOCK_EX);

    if (!$previous) {
        return ['available'=>false,'reason'=>'collecting_second_sample','interval_seconds'=>null];
    }

    $dt = $now - (float)($previous['at'] ?? 0);
    if ($dt < 2.0 || $dt > 45.0) {
        return ['available'=>false,'reason'=>'sample_window_invalid','interval_seconds'=>round($dt,3)];
    }

    $previousDown = (float)($previous['down'] ?? $pair['download_bytes']);
    $previousUp = (float)($previous['up'] ?? $pair['upload_bytes']);
    $downDelta = trDelta((float)$pair['download_bytes'], $previousDown);
    $upDelta = trDelta((float)$pair['upload_bytes'], $previousUp);

    $sameValues = (float)$pair['download_bytes'] === $previousDown
        && (float)$pair['upload_bytes'] === $previousUp;
    $sameTimestamps =
        ($pair['download_timestamp'] ?? null) !== null &&
        ($pair['upload_timestamp'] ?? null) !== null &&
        ($pair['download_timestamp'] ?? null) === ($previous['down_ts'] ?? null) &&
        ($pair['upload_timestamp'] ?? null) === ($previous['up_ts'] ?? null);

    if ($sameValues && (!$refreshSucceeded || $sameTimestamps)) {
        return [
            'available'=>false,
            'reason'=>'stale_counters',
            'interval_seconds'=>round($dt,3),
        ];
    }

    if ($downDelta === null || $upDelta === null) {
        return ['available'=>false,'reason'=>'counter_reset','interval_seconds'=>round($dt,3)];
    }

    return [
        'available'=>true,
        'reason'=>null,
        'interval_seconds'=>round($dt,3),
        'download_mbps'=>($downDelta * 8) / $dt / 1000000,
        'upload_mbps'=>($upDelta * 8) / $dt / 1000000,
    ];
}

try {
    $deviceId = trim((string)($_GET['device_id'] ?? ''));
    if ($deviceId === '') trOut(['success'=>false,'message'=>'device_id não informado.'],400);

    if (!isGenieACSConfigured()) {
        trOut(['success'=>true,'available'=>false,'reason'=>'genieacs_not_configured','message'=>'GenieACS não configurado.']);
    }

    $conn = getDBConnection();
    $result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
    $credentials = $result ? $result->fetch_assoc() : null;
    if (!$credentials) {
        trOut(['success'=>true,'available'=>false,'reason'=>'genieacs_offline','message'=>'GenieACS não está conectado.']);
    }

    $genieacs = new GenieACS(
        $credentials['host'],
        $credentials['port'],
        $credentials['username'],
        $credentials['password']
    );

    $first = $genieacs->getDevice($deviceId);
    if (empty($first['success']) || !is_array($first['data'] ?? null)) {
        trOut(['success'=>true,'available'=>false,'reason'=>'device_not_found','message'=>'Equipamento não encontrado no GenieACS.']);
    }

    $device = $first['data'];
    $pair = trSelectPair($device);
    if (!$pair) {
        $discoverStateFile = trRefreshStatePath($deviceId . '|discover');
        $discoverState = trLoadState($discoverStateFile);
        $lastDiscover = (float)($discoverState['at'] ?? 0);
        $refreshQueued = false;

        if (microtime(true) - $lastDiscover >= 30) {
            trSaveState($discoverStateFile, ['at'=>microtime(true)]);
            $diagnosticRefresh = $genieacs->refreshDeviceDiagnostics($deviceId);
            $refreshQueued = !empty($diagnosticRefresh['success']);
        }

        trOut([
            'success'=>true,
            'available'=>false,
            'reason'=>'traffic_counters_not_found',
            'message'=>$refreshQueued
                ? 'Solicitei ao TR-069 os contadores WAN. Aguarde a ONU responder e atualize novamente.'
                : 'O CPE ainda não publicou contadores WAN de bytes reconhecidos pelo TR-069.',
            'source'=>'TR-069 / GenieACS',
            'refresh'=>[
                'requested'=>$refreshQueued,
                'success'=>false,
                'queued'=>$refreshQueued,
                'minimum_interval_seconds'=>5,
            ],
            'device'=>[
                'manufacturer'=>$device['_deviceId']['_Manufacturer'] ?? null,
                'model'=>$device['_deviceId']['_ProductClass'] ?? null,
            ],
        ]);
    }

    // Evita Connection Request agressivo: no máximo uma atualização dos
    // contadores a cada 5 segundos por CPE enquanto Monitoramento estiver aberto.
    $refreshStateFile = trRefreshStatePath($deviceId);
    $refreshState = trLoadState($refreshStateFile);
    $now = microtime(true);
    $lastRefresh = (float)($refreshState['at'] ?? 0);

    if ($now - $lastRefresh < 5) {
        trOut([
            'success'=>true,
            'available'=>false,
            'reason'=>'waiting_next_refresh',
            'message'=>'Aguardando a próxima amostra dos contadores TR-069.',
            'source'=>'TR-069 / GenieACS',
            'transport'=>'cwmp',
            'profile'=>$pair['kind'],
            'paths'=>[
                'download'=>$pair['download'],
                'upload'=>$pair['upload'],
            ],
            'refresh'=>[
                'requested'=>false,
                'success'=>false,
                'queued'=>false,
                'minimum_interval_seconds'=>5,
                'next_in_seconds'=>max(1, (int)ceil(5 - ($now - $lastRefresh))),
            ],
            'device'=>[
                'manufacturer'=>$device['_deviceId']['_Manufacturer'] ?? null,
                'model'=>$device['_deviceId']['_ProductClass'] ?? null,
            ],
        ]);
    }

    trSaveState($refreshStateFile, [
        'at'=>$now,
        'download'=>$pair['download'],
        'upload'=>$pair['upload'],
    ]);

    // Atualiza apenas os dois contadores selecionados.
    $refresh = $genieacs->getParameterValues(
        $deviceId,
        [$pair['download'], $pair['upload']],
        7000
    );

    $second = $genieacs->getDevice($deviceId);
    if (!empty($second['success']) && is_array($second['data'] ?? null)) {
        $freshPair = trSelectPair($second['data']);
        if ($freshPair && $freshPair['download'] === $pair['download'] && $freshPair['upload'] === $pair['upload']) {
            $pair = $freshPair;
        }
    }

    $refreshAccepted = !empty($refresh['success']);
    $refreshSucceeded = $refreshAccepted && (int)($refresh['http_code'] ?? 0) === 200;
    $refreshQueued = $refreshAccepted && !$refreshSucceeded;
    $rate = trCalculate($deviceId, $pair, $refreshSucceeded);

    $message = null;
    if (empty($rate['available'])) {
        $message = match($rate['reason'] ?? '') {
            'collecting_second_sample' => 'Primeira leitura TR-069 recebida; aguardando a próxima amostra.',
            'sample_window_invalid' => 'Aguardando uma nova janela de amostragem TR-069.',
            'counter_reset' => 'Os contadores WAN reiniciaram; aguardando nova amostra.',
            'stale_counters' => 'O CPE não atualizou os contadores WAN nesta leitura; aguardando a próxima comunicação TR-069.',
            default => 'Aguardando nova leitura TR-069.',
        };
    }

    trOut([
        'success'=>true,
        'available'=>!empty($rate['available']),
        'reason'=>$rate['reason'] ?? null,
        'message'=>$message,
        'source'=>'TR-069 / GenieACS',
        'transport'=>'cwmp',
        'profile'=>$pair['kind'],
        'paths'=>[
            'download'=>$pair['download'],
            'upload'=>$pair['upload'],
        ],
        'refresh'=>[
            'requested'=>true,
            'success'=>$refreshSucceeded,
            'queued'=>$refreshQueued,
            'http_code'=>$refresh['http_code'] ?? null,
            'minimum_interval_seconds'=>5,
        ],
        'counters'=>[
            'download_bytes'=>$pair['download_bytes'],
            'upload_bytes'=>$pair['upload_bytes'],
            'download_timestamp'=>$pair['download_timestamp'],
            'upload_timestamp'=>$pair['upload_timestamp'],
        ],
        'live'=>!empty($rate['available']) ? [
            'download_mbps'=>round((float)$rate['download_mbps'],6),
            'upload_mbps'=>round((float)$rate['upload_mbps'],6),
            'sample_time'=>date(DATE_ATOM),
            'interval_seconds'=>$rate['interval_seconds'],
            'transport'=>'tr069-counter-delta',
        ] : null,
        'device'=>[
            'manufacturer'=>$device['_deviceId']['_Manufacturer'] ?? null,
            'model'=>$device['_deviceId']['_ProductClass'] ?? null,
        ],
    ]);

} catch (Throwable $e) {
    trOut([
        'success'=>true,
        'available'=>false,
        'reason'=>'tr069_request_failed',
        'message'=>'Falha ao consultar os contadores de tráfego via TR-069.',
        'diagnostic'=>[
            'detail'=>mb_substr($e->getMessage(),0,220),
        ],
    ]);
}
