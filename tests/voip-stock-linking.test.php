<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/voip-stock.php';
function verify(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
}
function rejects(callable $fn, string $message): void {
    try { $fn(); }
    catch (RuntimeException $e) {return;}
    throw new RuntimeException('FAIL: expected failure: ' . $message);
}
$dir = sys_get_temp_dir() . '/voip-stock-test-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('Failed to create test directory');
try {
    $stock = new VoipStock($dir);
    $stock->import("1151961252;ocupado;JOAO DA SILVA\n1151961253;ocupado;MARIA\n1151961254;a_conferir;", 'operator');
    $before = $stock->read();
    verify(count($before['numbers']) === 3, 'initial count');
    verify($before['numbers']['1151961254']['status'] === 'a_conferir', 'import pending');

    $stock->linkManually('1151961252', '4099', '562', 'operator');
    $linked = $stock->read();
    verify($linked['numbers']['1151961252']['status'] === 'ocupado', 'linked occupied');
    verify($linked['numbers']['1151961252']['contract_id'] === '4099', 'contract saved');
    verify($linked['numbers']['1151961252']['product_record_id'] === '562', 'product record saved');
    rejects(fn()=>$stock->linkManually('1151961252','999','123','operator'), 'overwrite assignment');
    rejects(fn()=>$stock->linkManually('1151961253','4099','666','operator'), 'duplicate contract');
    rejects(fn()=>$stock->linkManually('1151961253','4000','562','operator'), 'duplicate product record');
    rejects(fn()=>$stock->linkManually('1151961254','4000','900','operator'), 'not confirmed available');

    $stock->confirmAvailable('1151961254', 'operator');
    $available = $stock->read();
    verify($available['numbers']['1151961254']['status'] === 'disponivel', 'manual release after supplier review');
    rejects(fn()=>$stock->confirmAvailable('1151961252','operator'), 'occupied cannot be released');
    rejects(fn()=>$stock->confirmAvailable('1151961254','operator'), 'repeat availability');
    $stock->linkManually('1151961254', '7000', '701', 'operator');
    verify($stock->read()['numbers']['1151961254']['status'] === 'ocupado', 'available to occupied on assignment');
    verify($stock->read()['numbers']['1151961253']['status'] === 'ocupado', 'other stock untouched');

    verify(($stock->read()['minimum_available'] ?? 5) === 5, 'default minimum');
    $stock->setMinimum(7, 'operator');
    verify($stock->read()['minimum_available'] === 7, 'new minimum');
    rejects(fn()=>$stock->setMinimum(0,'operator'), 'zero minimum');
    rejects(fn()=>$stock->setMinimum(101,'operator'), 'excessive minimum');
    $audit = array_column($stock->read()['audit'], 'action');
    verify(in_array('manual_link',$audit,true), 'link audited');
    verify(in_array('voicecopy_available_confirmed',$audit,true), 'availability audited');
    verify(in_array('minimum_changed',$audit,true), 'threshold audited');
    verify(count($stock->read()['numbers']) === 3, 'stock preserved');
    echo "OK: vinculo, exclusividade, disponibilidade supervisionada, minimo e auditoria.\n";
} finally {
    foreach (glob($dir.'/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}
