<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/voip-stock.php';
function checkBatch(bool $condition,string $message):void {
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
}
function rejectBatch(callable $fn,string $message):void {
    try {$fn();}catch(RuntimeException $e){return;}
    throw new RuntimeException('FAIL: expected rejection '.$message);
}
$dir=sys_get_temp_dir().'/voip-batch-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
try {
    $stock=new VoipStock($dir);
    $stock->import("1151961252;ocupado;CLIENTE ANTIGO\n".
        "1156641257;a_conferir;\n".
        "1156641258;a_conferir;\n".
        "1156641259;a_conferir;",'initial');
    $stock->linkManually('1151961252','2210','396','operator');
    $before=$stock->read();
    $rev=$before['revision'];
    rejectBatch(fn()=>$stock->confirmNewBatchAvailable([], $rev,'operator'),'empty');
    rejectBatch(fn()=>$stock->confirmNewBatchAvailable(['1156641257','1156641257'],$rev,'operator'),'duplicates');
    rejectBatch(fn()=>$stock->confirmNewBatchAvailable(['1156641257','bad'],$rev,'operator'),'invalid number');
    rejectBatch(fn()=>$stock->confirmNewBatchAvailable(['1156641257','1151961252'],$rev,'operator'),'occupied rejected atomically');
    checkBatch($stock->read()['numbers']['1156641257']['status']==='a_conferir','no partial changes');
    rejectBatch(fn()=>$stock->confirmNewBatchAvailable(['1156641257'], $rev+1,'operator'),'stale revision');
    $stock->confirmNewBatchAvailable(['1156641257','1156641258','1156641259'],$rev,'operator');
    $after=$stock->read();
    checkBatch($after['revision']===$rev+1,'one atomic revision');
    checkBatch($after['numbers']['1151961252']===$before['numbers']['1151961252'],'historical link preserved');
    foreach(['1156641257','1156641258','1156641259'] as $n) {
        checkBatch($after['numbers'][$n]['status']==='disponivel','new number available');
        checkBatch(empty($after['numbers'][$n]['contract_id']),'no contract assigned');
    }
    $events=array_values(array_filter($after['audit'],static fn($event)=>($event['action']??'')==='new_did_batch_available_confirmed'));
    checkBatch(count($events)===3,'audit each DID');
    checkBatch(count(array_unique(array_column($events,'batch_id')))===1,'one batch ID');
    rejectBatch(fn()=>$stock->confirmNewBatchAvailable(['1156641257'],$after['revision'],'operator'),'already confirmed');
    rejectBatch(fn()=>$stock->confirmNewBatchAvailable(['1156641258'],$rev,'operator'),'duplicate submission');
    // A reserved DID cannot be reconfirmed or released by a stale form.
    $reservation=$stock->reserveForVoip('4740','571','operator');
    rejectBatch(fn()=>$stock->confirmNewBatchAvailable([$reservation['number']],$stock->read()['revision'],'operator'),'reserved');
    checkBatch($stock->read()['numbers']['1151961252']['status']==='ocupado','occupied still occupied');
    echo "OK: disponibilidade em lote atomica, auditoria e protecao de vinculos.\n";
} finally {
    foreach(glob($dir.'/*')?:[] as $file) unlink($file);
    rmdir($dir);
}
