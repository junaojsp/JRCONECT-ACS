<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/voip-stock.php';
require_once __DIR__ . '/../lib/voip-auto-assign.php';

function ok(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FALHOU: ' . $label);
}
function fails(callable $f, string $label): void {
    try { $f(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Deveria bloquear: ' . $label);
}
function fixture(): array {
    $dir = sys_get_temp_dir() . '/voip-auto-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $stock = new VoipStock($dir);
    $stock->import('1151961252;ocupado;Legado' . "\n" .
        '1144888733;ocupado;Legado' . "\n" .
        '1151961200;a_conferir;' . "\n" .
        '1151961222;a_conferir;', 'test');
    return [$dir,$stock];
}
function cleanup(string $dir): void {
    foreach (glob($dir . '/*') ?: [] as $f) unlink($f);
    rmdir($dir);
}
function testFields(): array {
    return [
        'contract'=>[
            'id'=>'4740', 'obs_contrato'=>"Observacoes anteriores\n#JRC_VOIP_SOLICITAR#\nNao remover texto"
        ],
        'product_rows'=>[['id'=>'571','id_contrato'=>'4740','id_produto'=>'74']]
    ];
}
$start = testFields();
$v = VoipAutoAssignment::validateRequest($start['contract'],$start['product_rows'],'4740');
ok($v['product_record_id'] === '571','produto 571');
$formatted = VoipAutoAssignment::replacement($v['observation'],'4740','1151961199');
ok(str_contains($formatted,'JRC_VOIP_CONTRATO=4740;DID=1151961199'),'inseriu DID');
ok(str_contains($formatted,'Observacoes anteriores'),'preservou prefixo');
ok(str_contains($formatted,'Nao remover texto'),'preservou sufixo');
ok(!str_contains($formatted, '#JRC_VOIP_SOLICITAR#'),'marcador removido');
fails(fn()=>VoipAutoAssignment::replacement('#JRC_VOIP_SOLICITAR# #JRC_VOIP_SOLICITAR#','4740','1151961199'),'duplicado');
fails(fn()=>VoipAutoAssignment::validateRequest($start['contract'],[],'4740'),'sem produto');
fails(fn()=>VoipAutoAssignment::validateRequest($start['contract'],[
  ['id'=>'571','id_contrato'=>'4740','id_produto'=>'74'],
  ['id'=>'572','id_contrato'=>'4740','id_produto'=>'74']],'4740'),'produto ambíguo');
fails(fn()=>VoipAutoAssignment::validateRequest($start['contract'],[
  ['id'=>'571','id_contrato'=>'4741','id_produto'=>'74']],'4740'),'produto de outro contrato');

[$dir,$stock] = fixture();
try {
    fails(fn()=>VoipAutoAssignment::execute('4740',$stock,
      fn()=>testFields(),fn()=>null,'operador-nao-autorizado'),'writer não autorizado');
    fails(fn()=>$stock->reserveForVoip('4740','571','test'),'nenhum disponivel');
    $stock->confirmAvailable('1151961200','tester');
    $stock->confirmAvailable('1151961222','tester');
    $a = $stock->reserveForVoip('4740','571','test');
    ok($a['number']==='1151961200','escolha determinística');
    ok($stock->read()['numbers'][$a['number']]['status']==='reservado','reserva criada');
    fails(fn()=>$stock->reserveForVoip('4740','571','test'),'contrato duplicado');
    fails(fn()=>$stock->linkManually($a['number'],'999','999','tester'),'vinculo manual não pode roubar reserva');
    fails(fn()=>$stock->confirmVoipAssignment($a,false,'test'),'sem readback');
    fails(fn()=>$stock->confirmVoipAssignment([...$a,'reservation_id'=>str_repeat('a',32)],true,'test'),'token errado');
    $stock->confirmVoipAssignment($a,true,'test');
    ok($stock->read()['numbers'][$a['number']]['status']==='ocupado','ocupado somente apos confirmacao');
    fails(fn()=>$stock->reserveForVoip('4740','571','test'),'não distribui duas vezes');
} finally {cleanup($dir);}

[$dir,$stock] = fixture();
try {
    $stock->confirmAvailable('1151961200','tester');
    $stored = testFields();
    $reads=0;
    $read = function(string $id) use (&$stored,&$reads):array {
        $reads++;
        return $stored;
    };
    $write = function(string $id,string $previous,string $next) use (&$stored):void {
        ok($id==='4740','contrato correto');
        ok($previous===$stored['contract']['obs_contrato'],'sem sobrescrever texto concorrente');
        $stored['contract']['obs_contrato']=$next;
    };
    $done=VoipAutoAssignment::execute('4740',$stock,$read,$write,'voip-ixc-approved-writer');
    ok($reads===2,'duas leituras de contrato');
    ok($done['status']==='ocupado' && $done['number']==='1151961200','fluxo ponta a ponta simulado');
    ok($stock->read()['numbers']['1151961200']['contract_id']==='4740','vinculo persistido');
} finally {cleanup($dir);}

[$dir,$stock] = fixture();
try {
    $stock->confirmAvailable('1151961200','tester');
    $read=fn(string $id):array=>testFields();
    $write=fn(string $id,string $previous,string $next)=>throw new RuntimeException('timeout PUT');
    fails(fn()=>VoipAutoAssignment::execute('4740',$stock,$read,$write,'voip-ixc-approved-writer'),'falha de escrita');
    ok($stock->read()['numbers']['1151961200']['status']==='reservado','falha não libera número');
} finally {cleanup($dir);}
echo "PASS: fluxo VoIP automático protegido e testado (simulação sem IXC)\n";
