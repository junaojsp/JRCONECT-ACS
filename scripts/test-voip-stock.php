<?php
declare(strict_types=1);
require __DIR__ . '/../lib/voip-stock.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejected(callable $fn): void { try { $fn(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Operação deveria falhar'); }
$dir = sys_get_temp_dir() . '/voip-test-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
try {
    $store = new VoipStock($dir);
    check(count($store->read()['numbers']) === 0, 'Estoque inicial');
    $a = "1199990001;ocupado;Cliente de teste\n1199990002;a_conferir;";
    $first = $store->import($a, 'test');
    check(count($first['numbers']) === 2 && count($first['audit']) === 2, 'Importação e auditoria');
    check($store->import($a, 'test') === $first, 'Idempotência');
    rejected(fn() => $store->import("1199990003;a_conferir;\n1199990001;ocupado;Outro", 'test'));
    check($store->read() === $first, 'Conflito não deve importar parcialmente');
    foreach (["1199990001;disponivel;", "199990001;ocupado;Teste", "1199990001;ocupado;", "1199990001\n1199990001"] as $invalid) {
        rejected(fn() => $store->import($invalid, 'test'));
    }
    check($store->read() === $first, 'Falhas preservam estoque');
    file_put_contents($dir . '/stock.json', '{corrupt');
    try { $store->import($a, 'test'); throw new RuntimeException('Corrupção não detectada'); } catch (JsonException $e) {}
    check(file_get_contents($dir . '/stock.json') === '{corrupt', 'Não sobrescrever arquivo corrompido');
    echo "OK: importação, auditoria, idempotência, validação e preservação em falhas\n";
} finally {
    foreach (glob($dir . '/*') as $path) unlink($path);
    rmdir($dir);
}
