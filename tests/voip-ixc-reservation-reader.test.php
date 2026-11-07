<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/voip-ixc-reservation-reader.php';
require_once __DIR__.'/../lib/voip-reconcile.php';
require_once __DIR__.'/../lib/voip-ixc-monitor.php';
require_once __DIR__.'/../lib/voip-stock.php';
require_once __DIR__.'/../lib/voip-auto-assign.php';
require_once __DIR__.'/../lib/voip-automatic-reservation.php';
function checkReader(bool $ok,string $msg):void { if (!$ok) throw new RuntimeException($msg); }
function failsReader(callable $fn):void {
    try {$fn();} catch (RuntimeException $e) {return;}
    throw new RuntimeException('Expected rejection');
}
// No production secrets or privileged fixture required. Only replace the test transport.
function readerFixture(callable $transport):VoipIxcReservationReader {
    $r=new ReflectionClass(VoipIxcReservationReader::class);
    $reader=$r->newInstanceWithoutConstructor();
    $r->getProperty('transport')->setValue($reader,$transport);
    return $reader;
}
$products=[];$contracts=[];
for($i=0;$i<18;$i++) {
    $id=(string)(4740+$i);$pid=(string)(571+$i);
    $products[]=['id'=>$pid,'id_contrato'=>$id,'id_produto'=>'74'];
    $contracts[]=['id'=>$id,'status'=>'A','obs_contrato'=>'#JRC_VOIP_SOLICITAR#'];
}
$calls=[];
$transport=function($resource,$query,$body)use(&$calls,&$products,&$contracts) {
    $calls[]=[$resource,$body];
    $rows=$resource==='cliente_contrato'?$contracts:$products;
    $field=explode('.',$body['qtype'])[1];
    $wanted=explode(',',$query);
    $rows=array_values(array_filter($rows,fn($row)=>in_array($row[$field],$wanted,true)));
    if(isset($body['grid_param'])) {
        checkReader(json_decode($body['grid_param'],true)===[
            ['TB'=>'vd_contratos_produtos.id_produto','OP'=>'=','P'=>'74']
        ],'product filter');
        $rows=array_values(array_filter($rows,fn($r)=>$r['id_produto']==='74'));
    }
    return ['total'=>count($rows),'registros'=>array_slice($rows,((int)$body['page']-1)*20,20)];
};
$reader=readerFixture($transport);
$snapshot=$reader->snapshot();
checkReader(count($calls)===2 && $snapshot['total_contratos']===18,'18 contracts use two GETs');
$monitor=VoipIxcMonitor::consume(json_encode($snapshot),null,gmdate('c'));
checkReader(count($monitor['contracts'])===18,'existing monitor accepts snapshot');
$reader->readFresh('4740','571');
checkReader(count($calls)===4,'fresh lookup validates contract and all products');
// A second product added after snapshot must stop reservation.
$products[]=['id'=>'999','id_contrato'=>'4740','id_produto'=>'74'];
failsReader(fn()=>$reader->readFresh('4740','571'));
array_pop($products);
// More than one page/batch.
for($i=18;$i<41;$i++){
    $products[]=['id'=>(string)(571+$i),'id_contrato'=>(string)(4740+$i),'id_produto'=>'74'];
    $contracts[]=['id'=>(string)(4740+$i),'status'=>'A'];
}
$calls=[];$reader->snapshot();
checkReader(count($calls)===6,'41 contracts: three product pages + three batches');
foreach ([
    ['registros'=>[]], ['total'=>'oops','registros'=>[]],
    ['total'=>201,'registros'=>[]], ['total'=>1,'registros'=>[]],
    ['total'=>2,'registros'=>[$products[0],$products[0]]],
    ['total'=>1,'registros'=>[['id'=>'1','id_contrato'=>'4740','id_produto'=>'75']]],
    ['total'=>0,'registros'=>[],'error'=>'private upstream response']
] as $bad) {
    failsReader(fn()=>readerFixture(fn()=>$bad)->snapshot());
}
failsReader(fn()=>readerFixture(function($resource)use($products){
    return $resource==='vd_contratos_produtos'
        ?['total'=>1,'registros'=>[$products[0]]]
        :['total'=>0,'registros'=>[]];
})->snapshot());
failsReader(fn()=>readerFixture(function(){throw new RuntimeException('simulated timeout');})->snapshot());
$empty=readerFixture(fn()=>['total'=>0,'registros'=>[]])->snapshot();
checkReader($empty['total_contratos']===0,'verified empty result');
// Integrated snapshot -> existing monitor -> actual local stock, two consecutive cycles.
$products=array_slice($products,0,18);$contracts=array_slice($contracts,0,18);
$dir=sys_get_temp_dir().'/voip-reader-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
try {
    $stock=new VoipStock($dir);
    $stock->import("1156641257;a_conferir;\n1156641258;a_conferir;\n",'fixture');
    foreach(['1156641257','1156641258'] as $did)$stock->confirmAvailable($did,'fixture');
    $stock->reserveForVoip('4740','571','fixture');
    foreach($contracts as &$c)$c['obs_contrato']=$c['id']==='4741'?'#JRC_VOIP_SOLICITAR#':'normal';
    unset($c);
    $calls=[];
    $state=VoipIxcMonitor::consume(json_encode($reader->snapshot()),null,gmdate('c'));
    $result=VoipAutomaticReservation::process($state,$stock,[$reader,'readFresh']);
    checkReader($result['reservados']===1 && $result['falhas']===0,'one eligible contract reserved');
    checkReader($result['novos'][0]['contrato_id']==='4741','correct new contract');
    checkReader($stock->read()['numbers']['1156641257']['contract_id']==='4740','pilot preserved');
    $before=file_get_contents($dir.'/stock.json');
    $again=VoipAutomaticReservation::process($state,$stock,[$reader,'readFresh']);
    checkReader($again['reservados']===0 && file_get_contents($dir.'/stock.json')===$before,'second cycle unchanged');
    $failed=VoipAutomaticReservation::process($state,$stock,function(){throw new RuntimeException('secret');});
    checkReader($failed['falhas']>0 && !str_contains(json_encode($failed),'secret'),'safe failure details');
} finally {
    foreach(glob($dir.'/*')?:[] as $file)unlink($file);
    rmdir($dir);
}
echo "OK: batched snapshot, pagination, fail closed, fresh product uniqueness, local end-to-end and idempotency.\n";
