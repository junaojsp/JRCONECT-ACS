<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/voip-reconcile.php';
require_once dirname(__DIR__) . '/lib/voip-ixc-monitor.php';

function verify(bool $value, string $message): void {
    if (!$value) throw new RuntimeException('FAIL: ' . $message);
}
function snap(array $contracts): string {
    $rows = [];
    foreach ($contracts as $row) {
        $rows[] = [
            'contrato_id'=>$row[0], 'nome_cliente'=>'DADO PESSOAL NAO PERSISTIR',
            'status_ixc_bruto'=>$row[1], 'registros_produto_74'=>[$row[2]]
        ];
    }
    return json_encode([
        'produto_id'=>'74','total_registros_produto'=>count($rows),
        'total_contratos'=>count($rows),'somente_leitura'=>true,
        'liberacao_automatica'=>false,'contratos'=>$rows
    ], JSON_THROW_ON_ERROR);
}
$first = VoipIxcMonitor::consume(snap([['2210','A','396'],['5006','A','303']]), null, '2026-11-07T03:00:00+00:00');
verify($first['events'] === [] && $first['new_events'] === 0, 'baseline no false alerts');
verify(!str_contains(json_encode($first), 'DADO PESSOAL'), 'no persisted names');
verify(count($first['contracts']) === 2, 'baseline intact');
$second = VoipIxcMonitor::consume(snap([
    ['2210','I','396'],['5006','A','303'],['4099','A','562']
]), $first, '2026-11-07T03:15:00+00:00');
verify($second['new_events'] === 2, 'new contract and status change');
$types = array_column($second['events'], 'type');
verify(in_array('new_product_74',$types,true), 'new product detected');
verify(in_array('status_changed',$types,true), 'status change detected');
verify(!str_contains(json_encode($second), 'DADO PESSOAL'), 'no personal name leak');
$third = VoipIxcMonitor::consume(snap([
    ['2210','I','396'],['4099','A','562']
]), $second, '2026-11-07T03:30:00+00:00');
verify(in_array('product_74_absent',array_column($third['events'], 'type'),true), 'removed product alert');
verify($third['new_events'] === 1, 'one new event only');
$fourth = VoipIxcMonitor::consume(snap([
    ['2210','I','396'],['4099','A','562']
]), $third, '2026-11-07T03:45:00+00:00');
verify($fourth['new_events'] === 0, 'no repeated unchanged alerts');
verify(count($fourth['events']) === 3, 'history preserved');
try {
    VoipIxcMonitor::consume('{"produto_id":"74"}', $fourth, '2026-11-07T04:00:00+00:00');
    throw new RuntimeException('FAIL: accepted incomplete IXC');
} catch (RuntimeException $error) {
    verify(str_contains($error->getMessage(), 'Snapshot incompatível'), 'snapshot invalid fail closed');
}
$stock = [['number'=>'1151961252','contract_id'=>'2210']];
$view = VoipIxcMonitor::overview($fourth, $stock, PHP_INT_MAX);
verify($view['unlinked'] === ['4099'], 'only unlinked contract flagged');
verify($view['total_contracts'] === 2, 'accurate count');
verify(VoipIxcMonitor::overview(null,[])['status'] === 'not_configured','not activated state');
echo "OK: baseline, deltas, invalid snapshot and privacy.\n";
