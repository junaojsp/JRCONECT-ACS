<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/voip-stock.php';
require_once __DIR__.'/../lib/voip-auto-assign.php';
require_once __DIR__.'/../lib/voip-automatic-reservation.php';
function arCheck(bool $condition,string $msg):void{if(!$condition)throw new RuntimeException('FAIL '.$msg);}
$dir=sys_get_temp_dir().'/voip-auto-reserve-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
try {
 $stock=new VoipStock($dir);
 $stock->import("1156641257;a_conferir;\n1156641258;a_conferir;\n1156641259;a_conferir;\n1151961252;ocupado;Legacy",'initial');
 foreach(['1156641257','1156641258','1156641259'] as $did)$stock->confirmAvailable($did,'voicecopy');
 $stock->linkManually('1151961252','2210','396','operator');
 $stock->import("1151961251;ocupado;Legacy cancel",'initial');
 $stock->linkManually('1151961251','2250','397','operator');
 $existing=$stock->reserveForVoip('4740','571','operator');
 arCheck($existing['number']==='1156641257','existing reservation');
 $monitor=[
  'version'=>1,'mode'=>'read_only','generated_at'=>gmdate('c'),
  'contracts'=>[
   '2210'=>['status'=>'A','product_record_ids'=>['396']],
   '4740'=>['status'=>'A','product_record_ids'=>['571']],
   '4800'=>['status'=>'A','product_record_ids'=>['572']],
   '4801'=>['status'=>'A','product_record_ids'=>['573']],
   '4802'=>['status'=>'I','product_record_ids'=>['574']],
   '4803'=>['status'=>'A','product_record_ids'=>['575','576']],
  ],
 ];
 $reads=0;
 $reader=function(string $contract,string $product)use(&$reads):array{
  $reads++;
  $obs=$contract==='4800'?'Plano 500\n#JRC_VOIP_SOLICITAR#':'Plano normal';
  return ['contract'=>['id'=>$contract,'status'=>'A','obs_contrato'=>$obs],
   'product_rows'=>[['id'=>$product,'id_contrato'=>$contract,'id_produto'=>'74']]];
 };
 $contractReader=function(string $contract):array{
  if($contract==='2250') return ['contract'=>['id'=>'2250','status'=>'I','obs_contrato'=>'#JRC_VOIP_CANCELAR#']];
  return ['contract'=>['id'=>$contract,'status'=>'A','obs_contrato'=>'Plano normal']];
 };
 $result=VoipAutomaticReservation::process($monitor,$stock,$reader,null,$contractReader);
 arCheck($result['reservados']===1,'exactly one reservation');
 arCheck($result['novos'][0]['contrato_id']==='4800','correct contract');
 arCheck($result['novos'][0]['did']==='1156641258','next free DID');
 arCheck($result['ja_vinculados']===2,'historical and pilot untouched');
 arCheck($result['cancelados']===1,'cancel product-removed link');
 arCheck($reads===2,'skip existing before read');
 arCheck($stock->read()['numbers']['1156641257']['contract_id']==='4740','pilot preserved');
 arCheck($stock->read()['numbers']['1156641258']['status']==='reservado','new DID reserved, not occupied');
 arCheck($stock->read()['numbers']['1156641259']['status']==='disponivel','third DID remains free');
 $again=VoipAutomaticReservation::process($monitor,$stock,$reader);
 arCheck($again['reservados']===0,'idempotent next 15 minute cycle');
 arCheck($stock->read()['numbers']['1151961252']['contract_id']==='2210','legacy preserved');
 arCheck($stock->read()['numbers']['1151961251']['status']==='disponivel','released after product removal');
 $stale=$monitor;$stale['generated_at']='2020-01-01T00:00:00Z';
 try{VoipAutomaticReservation::process($stale,$stock,$reader);throw new RuntimeException('FAIL stale monitor');}
 catch(RuntimeException $e){arCheck(str_contains($e->getMessage(),'desatualizado'),'stale rejected');}
 echo "OK: marker+74+active, no duplicate pilot, idempotent, no PUT, legacy unchanged.\n";
} finally {
 foreach(glob($dir.'/*')?:[] as $file)unlink($file);
 rmdir($dir);
}
