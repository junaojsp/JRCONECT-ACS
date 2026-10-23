<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/IXCConfig.php';

function requestIxc(string $base, string $token, string $entity, array $query): array {
    $url = rtrim($base,'/') . '/webservice/v1/' . $entity . '?' . http_build_query($query);
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
    return [$http, $err, is_string($body) ? $body : ''];
}

function decodeRecords(string $body): array {
    $j = json_decode($body, true);
    if (!is_array($j)) return [null, []];
    if (isset($j['registros']) && is_array($j['registros'])) return [$j, $j['registros']];
    if (array_is_list($j)) return [$j, $j];
    return [$j, []];
}

function safeRecord(array $row): array {
    $out=[];
    foreach($row as $k=>$v){
        if (preg_match('/senha|password|token|secret|chave/i',(string)$k)) continue;
        if (is_scalar($v) || $v===null) $out[$k]=$v;
    }
    return $out;
}

$login = trim((string)($argv[1] ?? ''));
if ($login === '') {
    fwrite(STDERR, "Uso: php testar_historico_ixc_por_login.php <login_pppoe>\n");
    exit(2);
}

$cfg=getIxcConfig();
$base=(string)$cfg['base_url'];
$token=(string)$cfg['token'];

echo "=== LOCALIZANDO LOGIN IXC ===\n";
[$http,$err,$body]=requestIxc($base,$token,'radusuarios',[
    'qtype'=>'radusuarios.login',
    'query'=>$login,
    'oper'=>'=',
    'page'=>'1','rp'=>'10','sort'=>'id','order'=>'desc'
]);
echo "http: $http\n";
if($err!=='') echo "curl_error: $err\n";
[$json,$rows]=decodeRecords($body);
echo "registros: ".count($rows)."\n";
if(!$rows){
    echo "retorno: ".substr($body,0,700)."\n";
    exit(3);
}

$match=null;
foreach($rows as $row){
    if(strcasecmp(trim((string)($row['login'] ?? '')),$login)===0){$match=$row;break;}
}
$match=$match ?: $rows[0];
$id=(string)($match['id'] ?? '');
echo "login: ".($match['login'] ?? '')."\n";
echo "id_login: $id\n";
echo "online: ".($match['online'] ?? 'N/D')."\n";
if(!preg_match('/^\d+$/',$id)) exit(4);

foreach([
    ['radusuarios_consumo','radusuarios_consumo.id_login',20],
    ['radacct','radacct.id_radusuarios',10],
] as [$entity,$qtype,$rp]){
    echo "\n=== $entity ===\n";
    [$http,$err,$body]=requestIxc($base,$token,$entity,[
        'qtype'=>$qtype,'query'=>$id,'oper'=>'=',
        'page'=>'1','rp'=>(string)$rp,'sort'=>'id','order'=>'desc'
    ]);
    echo "http: $http\n";
    if($err!=='') echo "curl_error: $err\n";
    [$json,$records]=decodeRecords($body);
    echo "total: ".(is_array($json)&&isset($json['total'])?$json['total']:count($records))."\n";
    echo "registros_recebidos: ".count($records)."\n";
    if($records){
        echo "campos: ".implode(', ',array_keys($records[0]))."\n";
        echo "primeiro_registro: ".json_encode(safeRecord($records[0]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    } else {
        echo "retorno: ".substr($body,0,700)."\n";
    }
}
