<?php
require_once __DIR__ . '/../lib/ActionHistory.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
check(acsActionFields([['Device.WiFi.SSID.1.SSID','secret-value'],['Device.WiFi.AccessPoint.1.Security.KeyPassphrase','password-value']]) === 'SSID, Senha', 'Only categories');
foreach ([[200,true,'completed'],[202,true,'queued'],[400,false,'failed'],[500,false,'unconfirmed'],[0,false,'unconfirmed'],[200,false,'unconfirmed']] as [$code,$success,$state]) {
    check(acsActionResultState(['http_code'=>$code,'success'=>$success]) === $state, 'HTTP state');
}
$task = str_repeat('a',24);
check(acsActionTaskId(['data'=>['_id'=>['$oid'=>$task]]]) === $task,'Object ID');
check(acsActionTaskId(['data'=>['_id'=>'invalid']]) === null,'Reject invalid ID');
$entry=['status'=>'queued','task_id'=>$task,'device_id'=>'ONU'];
check(acsActionRemoteState($entry,[$task=>true],[]) === 'queued','Queue');
check(acsActionRemoteState($entry,[],[]) === 'unconfirmed','Disappearance not completion');
check(acsActionRemoteState($entry,[$task=>true],['ONU:task_'.$task=>true]) === 'failed','Fault takes precedence');
$entry['status']='completed';
check(acsActionRemoteState($entry,[],['ONU:task_'.$task=>true]) === 'completed','Terminal state');
echo "Action history tests passed.\n";
