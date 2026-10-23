<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/IXCConfig.php';

if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jrc_ixc_get(string $entity, array $query): array {
    $cfg = getIxcConfig();
    $url = rtrim((string)$cfg['base_url'], '/') . '/webservice/v1/' . $entity . '?' . http_build_query($query);
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('curl_init_failed');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode((string)$cfg['token']),
        ],
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $http < 200 || $http >= 300) {
        throw new RuntimeException('ixc_http_' . $http . ($err !== '' ? '_' . $err : ''));
    }
    $json = json_decode((string)$body, true);
    if (!is_array($json)) throw new RuntimeException('ixc_invalid_json');
    $rows = isset($json['registros']) && is_array($json['registros'])
        ? $json['registros']
        : (array_is_list($json) ? $json : []);
    return [$json, $rows];
}

function jrc_find_login_id(string $login): string {
    [, $rows] = jrc_ixc_get('radusuarios', [
        'qtype' => 'radusuarios.login',
        'query' => $login,
        'oper' => '=',
        'page' => '1',
        'rp' => '20',
        'sort' => 'id',
        'order' => 'desc',
    ]);
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if (strcasecmp(trim((string)($row['login'] ?? '')), $login) !== 0) continue;
        $id = trim((string)($row['id'] ?? ''));
        if (preg_match('/^\d+$/', $id)) return $id;
    }
    throw new RuntimeException('login_ixc_nao_encontrado');
}

function jrc_date_ts(array $row): ?int {
    foreach (['data','data_hora','datahora','datetime','created_at','dt_criacao'] as $key) {
        if (!isset($row[$key]) || trim((string)$row[$key]) === '') continue;
        $ts = strtotime((string)$row[$key]);
        if ($ts !== false) return $ts;
    }
    return null;
}

function jrc_bytes(array $row, string $direction): float {
    $keys = $direction === 'rx'
        ? ['consumo','download','download_bytes','bytes_download','acctinputoctets']
        : ['consumo_upload','upload','upload_bytes','bytes_upload','acctoutputoctets'];
    foreach ($keys as $key) {
        if (isset($row[$key]) && is_numeric($row[$key])) return max(0.0, (float)$row[$key]);
    }
    return 0.0;
}

function jrc_bucket_size(int $hours): int {
    if ($hours <= 1) return 1200;
    if ($hours <= 24) return 3600;
    if ($hours <= 168) return 21600;
    return 86400;
}

try {
    $login = trim((string)($_GET['login'] ?? ''));
    $hours = max(1, min(720, (int)($_GET['hours'] ?? 720)));

    if ($login === '' || strlen($login) > 128 || !preg_match('/^[A-Za-z0-9._@:+\-]+$/', $login)) {
        throw new InvalidArgumentException('login_invalido');
    }

    $loginId = jrc_find_login_id($login);
    $cutoff = time() - ($hours * 3600);
    $records = [];

    for ($page = 1; $page <= 6; $page++) {
        [, $rows] = jrc_ixc_get('radusuarios_consumo', [
            'qtype' => 'radusuarios_consumo.id_login',
            'query' => $loginId,
            'oper' => '=',
            'page' => (string)$page,
            'rp' => '1000',
            'sort' => 'id',
            'order' => 'desc',
        ]);
        if (!$rows) break;

        $stop = false;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $ts = jrc_date_ts($row);
            if ($ts !== null && $ts < $cutoff) {
                $stop = true;
                continue;
            }
            $records[] = $row;
        }

        if ($stop || count($rows) < 1000) break;
    }

    $bucketSize = jrc_bucket_size($hours);
    $buckets = [];
    $rxTotal = 0.0;
    $txTotal = 0.0;

    foreach ($records as $row) {
        $ts = jrc_date_ts($row);
        if ($ts === null || $ts < $cutoff) continue;

        $rx = jrc_bytes($row, 'rx');
        $tx = jrc_bytes($row, 'tx');
        if ($rx <= 0 && $tx <= 0) continue;

        $bucket = (int)(floor($ts / $bucketSize) * $bucketSize);
        if (!isset($buckets[$bucket])) {
            $buckets[$bucket] = ['t'=>$bucket*1000,'rx_bytes'=>0.0,'tx_bytes'=>0.0,'records'=>0];
        }
        $buckets[$bucket]['rx_bytes'] += $rx;
        $buckets[$bucket]['tx_bytes'] += $tx;
        $buckets[$bucket]['records']++;
        $rxTotal += $rx;
        $txTotal += $tx;
    }

    ksort($buckets);
    $points = array_values($buckets);
    $rxPeak = 0.0;
    $txPeak = 0.0;
    foreach ($points as $p) {
        $rxPeak = max($rxPeak, (float)$p['rx_bytes']);
        $txPeak = max($txPeak, (float)$p['tx_bytes']);
    }
    $count = count($points);

    echo json_encode([
        'success'=>true,
        'source'=>'IXC radusuarios_consumo',
        'login'=>$login,
        'login_id'=>(int)$loginId,
        'hours'=>$hours,
        'bucket_seconds'=>$bucketSize,
        'points'=>$points,
        'summary'=>[
            'rx_bytes'=>$rxTotal,
            'tx_bytes'=>$txTotal,
            'total_bytes'=>$rxTotal+$txTotal,
            'rx_avg_bytes'=>$count ? $rxTotal/$count : 0,
            'tx_avg_bytes'=>$count ? $txTotal/$count : 0,
            'rx_peak_bytes'=>$rxPeak,
            'tx_peak_bytes'=>$txPeak,
            'records'=>count($records),
            'buckets'=>$count,
        ],
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success'=>false,
        'error'=>'historico_ixc_indisponivel',
        'message'=>$e->getMessage(),
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
