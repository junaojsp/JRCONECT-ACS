<?php
declare(strict_types=1);
require_once __DIR__ . '/../scripts/voip-pilot-4740-preview.php';
function pilotCheck(bool $okay,string $reason):void {
    if (!$okay) throw new RuntimeException('FAIL: '.$reason);
}
function pilotReject(callable $f,string $reason):void {
    try {$f();}catch(RuntimeException $e){return;}
    throw new RuntimeException('FAIL: expected block '.$reason);
}
$payload = [
    'contrato_id'=>'4740','produto_registro_id'=>'571','produto_id'=>'74',
    'observacao_marcador_unico'=>true,'status_ixc_bruto'=>'A',
    'somente_leitura'=>true,'escrita_ixc'=>false
];
$rows=[
  '1156641257'=>['number'=>'1156641257','status'=>'disponivel','contract_id'=>null,'product_record_id'=>null],
  '1156641258'=>['number'=>'1156641258','status'=>'disponivel','contract_id'=>null,'product_record_id'=>null],
  '1151961252'=>['number'=>'1151961252','status'=>'ocupado','contract_id'=>'2210','product_record_id'=>'396'],
  '1151961212'=>['number'=>'1151961212','status'=>'ocupado','contract_id'=>'4102','product_record_id'=>'111'],
  '1156641259'=>['number'=>'1156641259','status'=>'reservado','contract_id'=>'9000','product_record_id'=>'900','reservation_id'=>'abc']
];
$stock=['version'=>1,'revision'=>50,'numbers'=>$rows,'audit'=>[]];
$before=serialize($stock);
$result=voipPilotPreview($payload,$stock);
pilotCheck($result['status']==='apto_para_proxima_fase','ready');
pilotCheck($result['did_candidato']==='1156641257','first free DID');
pilotCheck($result['disponiveis']===2,'two free DIDs');
pilotCheck($result['resultado_previsto']==='JRC_VOIP_CONTRATO=4740;DID=1156641257','expected link format');
pilotCheck(!$result['reserva_did'] && !$result['escrita_ixc'] && !$result['estoque_modificado'],'read only');
pilotCheck(serialize($stock)===$before,'no store modification');
pilotReject(fn()=>voipPilotPreview([...$payload,'produto_registro_id'=>'396'],$stock),'wrong product');
pilotReject(fn()=>voipPilotPreview([...$payload,'escrita_ixc'=>true],$stock),'write attempts');
pilotReject(fn()=>voipPilotPreview([...$payload,'observacao_marcador_unico'=>false],$stock),'no marker');
$linked=$stock;
$linked['numbers']['1156641257']['contract_id']='4740';
pilotCheck(voipPilotPreview($payload,$linked)['status']==='ja_vinculado_ou_reservado','preexisting link');
$noFree=$stock;
$noFree['numbers']['1156641257']['status']='a_conferir';
$noFree['numbers']['1156641258']['status']='a_conferir';
pilotCheck(voipPilotPreview($payload,$noFree)['status']==='sem_estoque','no availability');
echo "OK: piloto 4740 somente leitura e protegido contra duplicacao.\n";
