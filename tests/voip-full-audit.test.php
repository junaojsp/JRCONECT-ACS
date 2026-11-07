<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/voip-full-audit.php';
function testFull(bool $c,string $m):void{if(!$c)throw new RuntimeException('FAIL '.$m);}
function rejectFull(callable $f,string $m):void{try{$f();}catch(RuntimeException $e){return;}throw new RuntimeException('FAIL rejection '.$m);}
$s=['version'=>1,'numbers'=>[
 '1151961252'=>['status'=>'ocupado','contract_id'=>'2210','product_record_id'=>'396'],
 '1156641257'=>['status'=>'disponivel','contract_id'=>null,'product_record_id'=>null],
 '1156641258'=>['status'=>'disponivel','contract_id'=>null,'product_record_id'=>null]
]];
$rows=[
 ['contrato_id'=>'2210','registros_produto_74'=>['396'],'status_ixc_bruto'=>'A','marcador_count'=>0,'possui_vinculo_observacao'=>false],
 ['contrato_id'=>'4740','registros_produto_74'=>['571'],'status_ixc_bruto'=>'A','marcador_count'=>1,'possui_vinculo_observacao'=>false],
 ['contrato_id'=>'5000','registros_produto_74'=>['702'],'status_ixc_bruto'=>'I','marcador_count'=>0,'possui_vinculo_observacao'=>false],
];
$input=['produto_id'=>'74','somente_leitura'=>true,'escrita_ixc'=>false,'reserva_did'=>false,
 'total_contratos'=>3,'total_registros_produto'=>3,'contratos'=>$rows];
$before=serialize($s);
$out=VoipFullAudit::compare($input,$s);
testFull($out['summary']['contratos']===3,'three');
testFull($out['summary']['ja_vinculados']===1,'existing');
testFull($out['summary']['solicitacoes_sem_did']===1,'new request');
testFull($out['summary']['sem_marcador_sem_did']===1,'not requested');
testFull($out['summary']['estoque_disponivel']===2,'free count');
testFull(!$out['escrita_ixc']&&!$out['reserva_did']&&!$out['liberacao_automatica'],'no writing');
testFull(serialize($s)===$before,'no stock mutation');
$dupe=$input;$dupe['contratos'][1]['marcador_count']=2;
testFull(VoipFullAudit::compare($dupe,$s)['summary']['marcador_duplicado']===1,'duplicated marker');
$already=$input;$already['contratos'][0]['marcador_count']=1;
testFull(VoipFullAudit::compare($already,$s)['summary']['marcador_ja_vinculado']===1,'linked with marker');
$orphan=$input;$orphan['contratos']=array_slice($orphan['contratos'],1);$orphan['total_contratos']=2;$orphan['total_registros_produto']=2;
testFull(VoipFullAudit::compare($orphan,$s)['summary']['divergencia']===1,'orphaned stock link');
$existing=$input;$existing['contratos'][1]['possui_vinculo_observacao']=true;
testFull(VoipFullAudit::compare($existing,$s)['summary']['divergencia']===1,'marker plus saved binding');
$bad=$input;$bad['total_registros_produto']=4;
rejectFull(fn()=>VoipFullAudit::compare($bad,$s),'incomplete');
$bad=$input;$bad['contratos'][1]['contrato_id']='2210';
rejectFull(fn()=>VoipFullAudit::compare($bad,$s),'duplicated contract');
$bad=$input;$bad['escrita_ixc']=true;
rejectFull(fn()=>VoipFullAudit::compare($bad,$s),'write flag');
echo "OK: auditoria total IXC somente leitura, sem mutacoes.\n";
