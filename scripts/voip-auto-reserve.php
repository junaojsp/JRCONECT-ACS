<?php
declare(strict_types=1);
// Unattended, root-only, local-stock reservation. No PUT and no VoiceCopy API.
if (PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0) {
    fwrite(STDERR,"BLOQUEADO: requer PHP CLI root.\n");exit(2);
}
require_once '/var/www/gacs/lib/voip-stock.php';
require_once '/var/www/gacs/lib/voip-auto-assign.php';
require_once '/var/www/gacs/lib/voip-ixc-monitor.php';
require_once __DIR__.'/lib/voip-ixc-reservation-reader.php';
require_once __DIR__.'/lib/voip-automatic-reservation.php';
try {
    if (count($argv)!==1) throw new RuntimeException('Sem parametros.');
    $lock=fopen('/run/lock/jrconect-voip-auto-reserve.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB))
        throw new RuntimeException('Reserva automatica ja em execucao.');
    $monitor=VoipIxcMonitor::read();
    if ($monitor===null) throw new RuntimeException('Monitor IXC nao configurado.');
    $reader=new VoipIxcReservationReader();
    $stock=new VoipStock();
    $result=VoipAutomaticReservation::process(
        $monitor,$stock,
        static fn(string $contractId,string $productId):array=>
            $reader->readFresh($contractId,$productId)
    );
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,"BLOQUEADO: reserva automatica nao concluiu; nao houve escrita IXC.\n");
    exit(2);
}
