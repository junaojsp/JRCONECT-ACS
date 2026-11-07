<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/voip-reconcile.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}
function snapshot(array $contracts, int $total): string {
    return json_encode([
        'produto_id' => '74',
        'somente_leitura' => true,
        'liberacao_automatica' => false,
        'total_contratos' => count($contracts),
        'total_registros_produto' => $total,
        'contratos' => $contracts,
    ], JSON_THROW_ON_ERROR);
}
function row(string $id, ?string $name, string $product, string $status = 'A'): array {
    return ['contrato_id'=>$id, 'nome_cliente'=>$name,
        'registros_produto_74'=>[$product], 'status_ixc_bruto'=>$status];
}
function rejects(callable $call, string $reason): void {
    try { $call(); } catch (RuntimeException $error) {return;}
    throw new RuntimeException('FAIL: expected rejection: ' . $reason);
}
$stock = [
    ['number'=>'1140000001', 'holder'=>'JOAO DA SILVA', 'status'=>'ocupado'],
    ['number'=>'1140000002', 'holder'=>'JENIFER BRITO DE SA', 'status'=>'ocupado'],
    ['number'=>'1140000003', 'holder'=>'', 'status'=>'a_conferir'],
];
$parsed = VoipReconcile::parseSnapshot(snapshot([
    row('4099','João da Silva','562'),
    row('2526','Jenifer Brito de Sa','315','I'),
    row('6396','Jenifer Brito de Sa','383','I'),
    row('9000',null,'900'),
], 4));
$result = VoipReconcile::compare($stock, $parsed);
check(count($result['rows']) === 4, 'four contracts');
check($result['stats']['unica'] === 1, 'accent normalized, one unique');
check($result['stats']['ambigua'] === 2, 'two contracts with same holder must be ambiguous');
check($result['stats']['sem_nome'] === 1, 'unnamed customer cannot match');
check($result['rows'][0]['candidates'] === ['1140000001'], 'number suggestion');
check($result['rows'][0]['status'] === 'A', 'status remains raw');
check($stock[2]['status'] === 'a_conferir', 'stock never changed');
rejects(fn()=>VoipReconcile::parseSnapshot(snapshot([row('1','A','1'),row('1','B','2')],2)), 'duplicate contract');
rejects(fn()=>VoipReconcile::parseSnapshot(snapshot([row('1','A','1'),row('2','B','1')],2)), 'duplicate product');
rejects(fn()=>VoipReconcile::parseSnapshot(snapshot([row('1','A','1')],2)), 'wrong total');
rejects(fn()=>VoipReconcile::parseSnapshot('{"produto_id":"74"}'), 'partial snapshot');
rejects(fn()=>VoipReconcile::parseSnapshot(str_repeat('x',100001)), 'size limit');
echo "OK: conciliacao somente leitura, ambiguidade e seguranca validadas.\n";
