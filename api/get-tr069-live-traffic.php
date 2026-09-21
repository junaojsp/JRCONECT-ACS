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

function trDelta(float $current, float $previous): ?float {
    if ($current >= $previous) return $current - $previous;

    // TR-098 usa muitos contadores unsignedInt de 32 bits.
    $max32 = 4294967296.0;
    if ($previous < $max32 && $current < $max32 && $previous > ($max32 * 0.75)) {
        return ($max32 - $previous) + $current;
    }

    return null;
}

function trCalculate(string $deviceId, array $pair): array {
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

    $downDelta = trDelta((float)$pair['download_bytes'], (float)($previous['down'] ?? $pair['download_bytes']));
    $upDelta = trDelta((float)$pair['upload_bytes'], (float)($previous['up'] ?? $pair['upload_bytes']));

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
        trOut([
            'success'=>true,
            'available'=>false,
            'reason'=>'traffic_counters_not_found',
            'message'=>'O CPE não publicou contadores WAN de bytes reconhecidos pelo TR-069.',
            'source'=>'TR-069 / GenieACS',
            'device'=>[
                'manufacturer'=>$device['_deviceId']['_Manufacturer'] ?? null,
                'model'=>$device['_deviceId']['_ProductClass'] ?? null,
            ],
        ]);
    }

    // Atualiza apenas os dois contadores selecionados. A requisição ao CPE
    // acontece somente enquanto a aba Monitoramento está aberta no navegador.
    $refresh = $genieacs->getParameterValues(
        $deviceId,
        [$pair['download'], $pair['upload']],
        4500
    );

    $second = $genieacs->getDevice($deviceId);
    if (!empty($second['success']) && is_array($second['data'] ?? null)) {
        $freshPair = trSelectPair($second['data']);
        if ($freshPair && $freshPair['download'] === $pair['download'] && $freshPair['upload'] === $pair['upload']) {
            $pair = $freshPair;
        }
    }

    $rate = trCalculate($deviceId, $pair);

    $message = null;
    if (empty($rate['available'])) {
        $message = match($rate['reason'] ?? '') {
            'collecting_second_sample' => 'Primeira leitura TR-069 recebida; aguardando a próxima amostra.',
            'sample_window_invalid' => 'Aguardando uma nova janela de amostragem TR-069.',
            'counter_reset' => 'Os contadores WAN reiniciaram; aguardando nova amostra.',
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
            'success'=>!empty($refresh['success']),
            'http_code'=>$refresh['http_code'] ?? null,
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
