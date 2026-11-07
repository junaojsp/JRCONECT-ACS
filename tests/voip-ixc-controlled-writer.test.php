<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/voip-ixc-controlled-writer.php';
require_once __DIR__.'/../lib/voip-auto-assign.php';
require_once __DIR__.'/../lib/voip-stock.php';
function checkWriter(bool $ok,string $message):void{
 if(!$ok)throw new RuntimeException('FAIL: '.$message);
}
function rejectsWriter(callable $fn,string $message):void{
 try{$fn();}catch(RuntimeException $e){return;}
 throw new RuntimeException('FAIL: expected rejection '.$message);
}
$dir=sys_get_temp_dir().'/voip-writer-test-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
$cred=$dir.'/writer.basic';
file_put_contents($cred,'writer:dedicated-test-token');
chmod($cred,0600);
$contract=['id'=>'4740','status'=>'A',
 'obs_contrato'=>"Cliente solicitou VoIP\n#JRC_VOIP_SOLICITAR#\nPreferencia periodo manha"];
for($i=1;$i<=46;$i++)$contract['campo_'.str_pad((string)$i,2,'0',STR_PAD_LEFT)]='valor'.(string)$i;
foreach(['tipo','id_cliente','id_vd_contrato','contrato','id_tipo_contrato',
 'id_modelo','id_filial','data','endereco_padrao_cliente','alerta_contrato'] as $k)$contract[$k]='1';
$product=['id'=>'571','id_produto'=>'74','id_contrato'=>'4740'];
$original=$contract;
$next=VoipAutoAssignment::replacement($contract['obs_contrato'],'4740','1156641257');
$payload=VoipIxcControlledWriter::payload($contract,$contract['obs_contrato'],$next);
checkWriter($payload['obs_contrato']===$next,'observation changed');
checkWriter(!array_key_exists('id',$payload),'id not in payload');
foreach($original as $key=>$value){
 if($key==='id'||$key==='obs_contrato')continue;
 checkWriter($payload[$key]===$value,'unchanged '.$key);
}
rejectsWriter(fn()=>VoipIxcControlledWriter::payload($original,$original['obs_contrato'],'garbage'),'invalid replacement');
rejectsWriter(fn()=>VoipIxcControlledWriter::payload($original,'old observation',$next),'stale observation');
rejectsWriter(fn()=>VoipIxcControlledWriter::payload([...$original,'status'=>'I'],$original['obs_contrato'],$next),'inactive');
$state=$contract;$writes=0;
$transport=function(string $method,string $url,array $body)use(&$state,&$writes,$product):array {
 if($method==='GET'){
   if(str_contains($url,'vd_contratos_produtos')){
     checkWriter($body['query']==='571','product id exact');
     return ['total'=>1,'registros'=>[$product]];
   }
   checkWriter($body['query']==='4740','contract id exact');
   return ['total'=>1,'registros'=>[$state]];
 }
 if($method==='PUT') {
   checkWriter(str_ends_with($url,'/cliente_contrato/4740'),'PUT exact route');
   $writes++;
   checkWriter($body['obs_contrato']!==$state['obs_contrato'],'changed marker only');
   foreach($state as $k=>$v) {
     if($k==='id'||$k==='obs_contrato')continue;
     checkWriter($v===$body[$k],'payload field unchanged '.$k);
   }
   $state['obs_contrato']=$body['obs_contrato'];
   return ['success'=>true];
 }
 throw new RuntimeException('Unexpected route');
};
try{
 $writer=new VoipIxcControlledWriter($cred,$transport);
 $read=$writer->readFresh('4740');
 checkWriter($read['contract']['obs_contrato']===$contract['obs_contrato'],'initial IXC read');
 $store=new VoipStock($dir);
 $store->import("1156641257;a_conferir;\n1156641258;a_conferir;\n1151961252;ocupado;Cliente Legado",'initial');
 $store->confirmAvailable('1156641257','operator');
 $store->confirmAvailable('1156641258','operator');
 $store->linkManually('1151961252','2210','396','operator');
 $result=VoipAutoAssignment::execute('4740',$store,
    fn(string $id):array=>$writer->readFresh($id),
    fn(string $id,string $before,string $after)=>$writer->writeObservation($id,$before,$after),
    'voip-ixc-approved-writer');
 checkWriter($result['number']==='1156641257' && $result['status']==='ocupado','assigned first DID');
 checkWriter($writes===1,'one PUT');
 checkWriter($state['obs_contrato']===$next,'actual contract observation updated');
 checkWriter($store->read()['numbers']['1156641257']['status']==='ocupado','stock updated only after readback');
 checkWriter($store->read()['numbers']['1151961252']['contract_id']==='2210','legacy protected');
 rejectsWriter(fn()=>VoipAutoAssignment::execute('4740',$store,
    fn(string $id):array=>$writer->readFresh($id),
    fn(string $id,string $before,string $after)=>$writer->writeObservation($id,$before,$after),
    'voip-ixc-approved-writer'),'no second assignment');
 checkWriter($writes===1,'no second PUT');
 $state=$original;
 $writerBad=new VoipIxcControlledWriter($cred,function(string $method,string $url,array $body)use(&$state,$product):array{
   if($method==='GET')return str_contains($url,'vd_contratos_produtos')?
     ['total'=>1,'registros'=>[$product]]:['total'=>1,'registros'=>[$state]];
   $state['obs_contrato']=$body['obs_contrato'];
   $state['contrato']='other value';
   return ['success'=>true];
 });
 rejectsWriter(fn()=>$writerBad->writeObservation('4740',$original['obs_contrato'],$next),'detect unexpected field change');
}finally{
 foreach(glob($dir.'/*')?:[] as $file)unlink($file);
 rmdir($dir);
}
echo "OK: IXC writer 4740 fake transport, full fields, atomic reservation, readback, idempotence.\n";
