<?php
declare(strict_types=1);
// Invoke as root from the root-owned scheduled script. Never via HTTP.
if (PHP_SAPI !== 'cli' || function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "BLOQUEADO: requer PHP CLI como root.\n");
    exit(2);
}
require_once '/var/www/gacs/lib/voip-reconcile.php';
require_once '/var/www/gacs/lib/voip-ixc-monitor.php';
$payload = stream_get_contents(STDIN, 100002);
if (!is_string($payload) || strlen($payload) > 100000 || trim($payload) === '') {
    fwrite(STDERR, "BLOQUEADO: snapshot IXC ausente ou excedeu limite.\n");
    exit(2);
}
try {
    $state = VoipIxcMonitor::ingest($payload);
    echo 'MONITOR OK: contratos=' . count($state['contracts']) .
        ' eventos_novos=' . $state['new_events'] . ' (sem dados pessoais).' . PHP_EOL;
} catch (Throwable $error) {
    // Avoid inadvertently leaking customer data from upstream error messages.
    fwrite(STDERR, "BLOQUEADO: nao foi possivel atualizar o monitor. Estado anterior preservado.\n");
    exit(2);
}
