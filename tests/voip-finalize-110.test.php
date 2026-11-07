<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/voip-stock.php';
require_once __DIR__.'/../lib/voip-auto-assign.php';
require_once __DIR__.'/../lib/voip-ixc-controlled-writer.php';
require_once __DIR__.'/../lib/voip-reserved-recovery.php';
function verify110(bool $v,string $why):void { if(!$v)throw new RuntimeException('FAIL '.$why); }
function reject110(callable $f,string $why):void {try{$f();}catch(RuntimeException){return;}throw new RuntimeException('Expected reject '.$why);}
verify110(VoipIxcControlledWriter::interpretPutResponse('')['http_2xx']===true,'empty 2xx accepted');
verify110(VoipIxcControlledWriter::interpretPutResponse('Atualizado com sucesso')['http_2xx']===true,'plain text 2xx accepted');
verify110(VoipIxcControlledWriter::interpretPutResponse('{"success":true}')['http_2xx']===true,'json 2xx accepted');
reject110(fn()=>VoipIxcControlledWriter::interpretPutResponse('{"success":false}'),'explicit JSON rejection');
reject110(fn()=>VoipIxcControlledWriter::interpretPutResponse('ERRO: bloqueado'),'explicit plain text rejection');
$dir=sys_get_temp_dir().'/voip110-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$cred=$dir.'/writer.basic';file_put_contents($cred,'testuser:dedicated-token');chmod($cred,0600);
$base=['id'=>'4740','status'=>'A','obs_contrato'=>"Internet 500M\n#JRC_VOIP_SOLICITAR#\nVisita de manha"];
for($i=0;$i<40;$i++)$base['campo_'.$i]='v'.$i;
foreach(['tipo','id_cliente','id_vd_contrato','contrato','id_tipo_contrato','id_modelo','id_filial','data','endereco_padrao_cliente','alerta_contrato'] as $k)$base[$k]='1';
$product=['id'=>'571','id_contrato'=>'4740','id_produto'=>'74'];
$live=$base;$putCount=0;$simulate='ambiguous-success';
$transport=function(string $method,string $url,array $body)use(&$live,&$putCount,&$simulate,$product):array{
 if($method==='GET')return str_contains($url,'vd_contratos_produtos')?['total'=>1,'registros'=>[$product]]:['total'=>1,'registros'=>[$live]];
 $putCount++;
 if($simulate==='definite-reject')throw new RuntimeException('IXC PUT HTTP 422.');
 $live['obs_contrato']=$body['obs_contrato'];
 if($simulate==='ambiguous-success')throw new RuntimeException('IXC PUT falha de transporte cURL 28.');
 return ['http_2xx'=>true];
};
try {
 $store=new VoipStock($dir);
 $store->import("1156641257;a_conferir;\n1156641258;a_conferir;\n1151961252;ocupado;Cliente Historico",'operator');
 $store->confirmAvailable('1156641257','operator');
 $store->confirmAvailable('1156641258','operator');
 $store->linkManually('1151961252','2210','396','operator');
 $reserved=$store->reserveForVoip('4740','571','operator');
 verify110($reserved['number']==='1156641257','existing reserved DID');
 $writer=new VoipIxcControlledWriter($cred,$transport);
 $result=VoipReservedRecovery::recover($store,$writer,$base,true,'voip-ixc-approved-writer');
 verify110($result['did']==='1156641257','retained DID despite timeout');
 verify110($store->read()['numbers']['1156641257']['status']==='ocupado','readback occupied');
 verify110($store->read()['numbers']['1156641258']['status']==='disponivel','second DID untouched');
 verify110($store->read()['numbers']['1151961252']['contract_id']==='2210','legacy untouched');
 verify110($putCount===1,'no duplicate PUT');
 reject110(fn()=>VoipReservedRecovery::recover($store,$writer,$base,true,'voip-ixc-approved-writer'),'no repeat after success');
 verify110($putCount===1,'no extra PUT on retry');
 $second=$dir.'/second';mkdir($second,0700);
 $stock2=new VoipStock($second);
 $stock2->import("1156641257;a_conferir;",'operator');
 $stock2->confirmAvailable('1156641257','operator');
 $stock2->reserveForVoip('4740','571','operator');
 $live=$base; $simulate='definite-reject';
 reject110(fn()=>VoipReservedRecovery::recover($stock2,$writer,$base,true,'voip-ixc-approved-writer'),'IXC HTTP 422');
 verify110($stock2->read()['numbers']['1156641257']['status']==='reservado','rejected PUT stays reserved');
 $live=$base; $live['contrato']='mudou';
 $simulate='success';
 $countBefore=$putCount;
 reject110(fn()=>VoipReservedRecovery::recover($stock2,$writer,$base,true,'voip-ixc-approved-writer'),'other contract field changed');
 verify110($putCount===$countBefore,'blocked before PUT');
 echo "OK: PUT vazio/texto, timeout apos escrita, recuperacao do mesmo DID e 422 sem segunda reserva.\n";
} finally {
 if(is_dir($dir.'/second')){foreach(glob($dir.'/second/*')?:[] as $file)unlink($file);rmdir($dir.'/second');}
 foreach(glob($dir.'/*')?:[] as $file)if(is_file($file))unlink($file);
 rmdir($dir);
}
