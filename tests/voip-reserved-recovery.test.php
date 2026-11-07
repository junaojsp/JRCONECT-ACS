<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/voip-stock.php';
require_once __DIR__.'/../lib/voip-auto-assign.php';
require_once __DIR__.'/../lib/voip-ixc-controlled-writer.php';
require_once __DIR__.'/../lib/voip-reserved-recovery.php';
function recOk(bool $v,string $message):void{if(!$v)throw new RuntimeException('FAIL '.$message);}
function recFail(callable $fn,string $message):void{
 try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected failure: '.$message);
}
$dir=sys_get_temp_dir().'/voip-recovery-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$cred=$dir.'/writer.basic';file_put_contents($cred,'testuser:dedicated-token');chmod($cred,0600);
$original=['id'=>'4740','obs_contrato'=>"Plano 500M\n#JRC_VOIP_SOLICITAR#\nVisita matutina",'status'=>'A'];
for($i=0;$i<40;$i++)$original['campo_'.$i]='valor'.$i;
foreach(['tipo','id_cliente','id_vd_contrato','contrato','id_tipo_contrato','id_modelo',
'id_filial','data','endereco_padrao_cliente','alerta_contrato'] as $k)$original[$k]='1';
$product=['id'=>'571','id_contrato'=>'4740','id_produto'=>'74'];
$live=$original;$puts=0;$rejectPut=false;
$transport=function(string $method,string $uri,array $body)use(&$live,&$puts,&$rejectPut,$product):array{
 if($method==='GET')return str_contains($uri,'vd_contratos_produtos')?
  ['total'=>1,'registros'=>[$product]]:['total'=>1,'registros'=>[$live]];
 $puts++;
 if($rejectPut)throw new RuntimeException('IXC PUT HTTP 422.');
 $live['obs_contrato']=$body['obs_contrato'];
 return ['success'=>true];
};
try{
 $writer=new VoipIxcControlledWriter($cred,$transport);
 $stock=new VoipStock($dir);
 $stock->import("1156641257;a_conferir;\n1156641258;a_conferir;\n1151961252;ocupado;Legacy",'initial');
 $stock->confirmAvailable('1156641257','operator');
 $stock->confirmAvailable('1156641258','operator');
 $stock->linkManually('1151961252','2210','396','operator');
 $reserve=$stock->reserveForVoip('4740','571','operator');
 recOk($reserve['number']==='1156641257','exact existing reservation');
 $before=$stock->read();
 $view=VoipReservedRecovery::inspect($before,$writer->readFresh('4740'),$original);
 recOk($view['status']==='write_pending'&&$view['did']==='1156641257','pending state');
 recOk(VoipReservedRecovery::publicStatus($view)['nova_reserva']===false,'inspect read only');
 recOk(serialize($before)===serialize($stock->read()),'no mutation inspection');
 recFail(fn()=>VoipReservedRecovery::recover($stock,$writer,$original,false,'voip-ixc-approved-writer'),'no PUT without approval');
 recOk($puts===0,'no unauthorized PUT');
 $rejectPut=true;
 recFail(fn()=>VoipReservedRecovery::recover($stock,$writer,$original,true,'voip-ixc-approved-writer'),'failed PUT');
 recOk($stock->read()['numbers']['1156641257']['status']==='reservado','failed PUT stays reserved');
 recOk($stock->read()['numbers']['1156641258']['status']==='disponivel','no second DID');
 $rejectPut=false;
 $done=VoipReservedRecovery::recover($stock,$writer,$original,true,'voip-ixc-approved-writer');
 recOk($done['did']==='1156641257'&&$done['status']==='ocupado','resume same number');
 recOk($stock->read()['numbers']['1156641257']['status']==='ocupado','stock complete');
 recOk($stock->read()['numbers']['1151961252']['contract_id']==='2210','legacy link retained');
 recOk($puts===2,'only rejected + successful PUT');
 recFail(fn()=>VoipReservedRecovery::recover($stock,$writer,$original,true,'voip-ixc-approved-writer'),'no repeat after completion');
 recOk($puts===2,'no second PUT');
 // Simulate a prior ambiguous PUT that succeeded, then re-read and confirm without writing.
 $live=$original;
 $stock2=new VoipStock($dir.'/second');mkdir($dir.'/second',0700);
 $stock2->import("1156641257;a_conferir;",'operator');
 $stock2->confirmAvailable('1156641257','operator');
 $stock2->reserveForVoip('4740','571','operator');
 $live['obs_contrato']=VoipAutoAssignment::replacement($original['obs_contrato'],'4740','1156641257');
 $beforeCount=$puts;
 $confirm=VoipReservedRecovery::recover($stock2,$writer,$original,false,'voip-ixc-approved-writer');
 recOk($confirm['status']==='ocupado' && $beforeCount===$puts,'readback confirm without repeat PUT');
 $live=$original;$live['contrato']='unexpected-change';
 $stock3=new VoipStock($dir.'/third');mkdir($dir.'/third',0700);
 $stock3->import("1156641257;a_conferir;",'operator');
 $stock3->confirmAvailable('1156641257','operator');
 $stock3->reserveForVoip('4740','571','operator');
 recFail(fn()=>VoipReservedRecovery::recover($stock3,$writer,$original,true,'voip-ixc-approved-writer'),'changed unrelated contract field');
 recOk($stock3->read()['numbers']['1156641257']['status']==='reservado','changed contract keeps reservation');
 echo "OK: retomada de DID existente, 422, confirmacao sem PUT repetido e protecao dos demais campos.\n";
}finally{
 foreach([$dir.'/second',$dir.'/third'] as $sub) {
   if(is_dir($sub)){foreach(glob($sub.'/*')?:[] as $f)unlink($f);rmdir($sub);}
 }
 foreach(glob($dir.'/*')?:[] as $f)if(is_file($f))unlink($f);
 rmdir($dir);
}
