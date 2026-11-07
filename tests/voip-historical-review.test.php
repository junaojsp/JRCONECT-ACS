<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/voip-historical-review.php';
require_once __DIR__ . '/../lib/voip-stock.php';

function assertHist(bool $good, string $why): void {
    if (!$good) throw new RuntimeException('FAIL historical: ' . $why);
}
function shouldReject(callable $fn,string $why): void {
    try {$fn();} catch (RuntimeException $e) {return;}
    throw new RuntimeException('FAIL historical expected rejection: ' . $why);
}
$contracts = [
    ['contract_id'=>'100','customer_name'=>'JOAO DA SILVA','status'=>'A','product_ids'=>['991']],
    ['contract_id'=>'200','customer_name'=>'MARIA SILVA','status'=>'I','product_ids'=>['992']],
    ['contract_id'=>'300','customer_name'=>'CLIENTE REPETIDO','status'=>'A','product_ids'=>['993']],
    ['contract_id'=>'301','customer_name'=>'CLIENTE REPETIDO','status'=>'I','product_ids'=>['994']],
    ['contract_id'=>'400','customer_name'=>'LINKED','status'=>'I','product_ids'=>['995']],
];
$inventory = [
    ['number'=>'1151961100','holder'=>'João da Silva','status'=>'ocupado','contract_id'=>null,'product_record_id'=>null],
    ['number'=>'1151961101','holder'=>'Maria Silva','status'=>'ocupado','contract_id'=>null,'product_record_id'=>null],
    ['number'=>'1151961102','holder'=>'DESCONHECIDO','status'=>'ocupado','contract_id'=>null,'product_record_id'=>null],
    ['number'=>'1151961103','holder'=>'CLIENTE REPETIDO','status'=>'ocupado','contract_id'=>null,'product_record_id'=>null],
    ['number'=>'1151961104','holder'=>'LINKED','status'=>'ocupado','contract_id'=>'400','product_record_id'=>'995'],
    ['number'=>'1151961105','holder'=>'','status'=>'a_conferir','contract_id'=>null,'product_record_id'=>null],
];
$review=VoipHistoricalReview::analyze($inventory,$contracts);
assertHist($review['summary']['historical_occupied']===5,'historical total');
assertHist($review['summary']['active_candidate']===1,'active candidate');
assertHist($review['summary']['inactive_candidate']===1,'inactive candidate');
assertHist($review['summary']['missing_product74']===1,'missing not auto-free');
assertHist($review['summary']['ambiguous']===1,'duplicate name');
assertHist($review['summary']['already_linked']===1,'protected link');
assertHist(count($review['rows'])===5,'only occupied');
assertHist($review['read_only']===true && $review['no_automatic_release']===true,'analysis read only');
assertHist($inventory[2]['status']==='ocupado','never modifies input');
shouldReject(fn()=>VoipHistoricalReview::analyze([$inventory[0],$inventory[0]],$contracts),'duplicated DID');
shouldReject(fn()=>VoipHistoricalReview::analyze($inventory,[$contracts[0],$contracts[0]]),'duplicated contract');
$dir=sys_get_temp_dir() . '/voip-history-' . bin2hex(random_bytes(8));
mkdir($dir,0700);
try {
    $stock = new VoipStock($dir);
    $stock->import("1151961100;ocupado;Joao da Silva\n1151961101;ocupado;Maria Silva\n1151961105;a_conferir;",'test');
    $stock->linkManually('1151961100','100','991','test');
    shouldReject(fn()=>$stock->confirmLegacyFree('1151961100','test'),'cannot release linked historical');
    shouldReject(fn()=>$stock->confirmLegacyFree('1151961105','test'),'cannot release a conferir');
    $before=$stock->read();
    $stock->confirmLegacyFree('1151961101','operator');
    $after=$stock->read();
    assertHist($after['numbers']['1151961101']['status']==='disponivel','historical DID released after manual verification');
    assertHist($after['numbers']['1151961100']===$before['numbers']['1151961100'],'linked DID preserved');
    shouldReject(fn()=>$stock->confirmLegacyFree('1151961101','operator'),'cannot release twice');
    $audit=array_column($after['audit'],'action');
    assertHist(in_array('legacy_free_confirmed_voicecopy',$audit,true),'audit event');
} finally {
    foreach (glob($dir.'/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}
echo "OK: revisão histórica, candidatos, bloqueio de vínculos e liberação supervisionada.\n";
