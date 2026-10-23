<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/IXCConfig.php';

function out(string $k, mixed $v): void {
    if (is_array($v) || is_object($v)) {
        echo $k . ': ' . json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
    } else {
        echo $k . ': ' . (string)$v . PHP_EOL;
    }
}

$loginId = trim((string)($argv[1] ?? ''));
if ($loginId === '' || !preg_match('/^\d+$/', $loginId)) {
    fwrite(STDERR, "Uso: php testar_historico_ixc.php <login_id_numerico>\n");
    exit(2);
}

$cfg = getIxcConfig();
$base = rtrim((string)$cfg['base_url'], '/');
$token = (string)$cfg['token'];

$tests = [
    [
        'name' => 'radusuarios_consumo',
        'url' => $base . '/webservice/v1/radusuarios_consumo',
        'query' => [
            'qtype' => 'radusuarios_consumo.id_login',
            'query' => $loginId,
            'oper' => '=',
            'page' => '1',
            'rp' => '20',
            'sort' => 'id',
            'order' => 'desc',
        ],
    ],
    [
        'name' => 'radacct',
        'url' => $base . '/webservice/v1/radacct',
        'query' => [
            'qtype' => 'radacct.id_radusuarios',
            'query' => $loginId,
            'oper' => '=',
            'page' => '1',
            'rp' => '5',
            'sort' => 'id',
            'order' => 'desc',
        ],
    ],
];

foreach ($tests as $test) {
    echo "=== {$test['name']} ===\n";
    $url = $test['url'] . '?' . http_build_query($test['query']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
        ],
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    out('http', $http);
    if ($err !== '') out('curl_error', $err);
    if (!is_string($body)) {
        echo "sem corpo\n\n";
        continue;
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        out('body_prefix', substr($body, 0, 500));
        echo "\n";
        continue;
    }

    $records = [];
    if (isset($json['registros']) && is_array($json['registros'])) $records = $json['registros'];
    elseif (array_is_list($json)) $records = $json;

    out('total', $json['total'] ?? count($records));
    out('registros_recebidos', count($records));

    if ($records) {
        $first = $records[0];
        out('campos_primeiro_registro', array_keys($first));

        $safe = [];
        foreach ($first as $k => $v) {
            $lk = strtolower((string)$k);
            if (preg_match('/senha|password|token|secret|chave/', $lk)) continue;
            if (is_scalar($v) || $v === null) $safe[$k] = $v;
        }
        out('primeiro_registro', $safe);
    }
    echo "\n";
}
