<?php
declare(strict_types=1);
// Unattended, root-only, local-stock reservation. No PUT and no VoiceCopy API.
if (PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0) {
    fwrite(STDERR,"BLOQUEADO: requer PHP CLI root.\n");exit(2);
}
require_once '/var/www/gacs/lib/voip-stock.php';
$assignmentCandidates = [
    '/var/www/gacs/lib/voip-auto-assign.php',
    '/opt/jrconect-voip-writer/lib/voip-auto-assign.php',
    __DIR__.'/lib/voip-auto-assign.php',
];
$assignmentPath = null;
foreach ($assignmentCandidates as $candidate) {
    if (is_file($candidate) && !is_link($candidate)) {
        $assignmentPath = $candidate;
        break;
    }
}
if ($assignmentPath === null) {
    fwrite(STDERR,"BLOQUEADO: modulo de validacao VoIP ausente.\n");
    exit(2);
}
require_once $assignmentPath;
require_once '/var/www/gacs/lib/voip-ixc-monitor.php';
require_once '/var/www/gacs/lib/voip-reconcile.php';
require_once __DIR__.'/lib/voip-ixc-reservation-reader.php';
require_once __DIR__.'/lib/voip-automatic-reservation.php';
$writerPath='/opt/jrconect-voip-writer/lib/voip-ixc-controlled-writer.php';
$writeGate='/etc/jrconect/voip-auto-reserve-write.enabled';
try {
    if (count($argv)>2 || (count($argv)===2 && !in_array($argv[1],['--monitor','--check-monitor'],true)))
        throw new RuntimeException('Parametro invalido.');
    $lock=fopen('/run/lock/jrconect-voip-auto-reserve.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB))
        throw new RuntimeException('Reserva automatica ja em execucao.');
    $reader=new VoipIxcReservationReader();
    if (($argv[1]??'')==='--check-monitor') {
        $start=microtime(true);
        $snapshot=$reader->snapshot();
        $validated=VoipIxcMonitor::consume(json_encode($snapshot,JSON_THROW_ON_ERROR),null,gmdate('c'));
        echo 'CONSULTA OK: contratos='.count($validated['contracts']).
            ' duracao_s='.round(microtime(true)-$start,2).' (sem gravacao local ou IXC)'.PHP_EOL;
        exit(0);
    }
    if (($argv[1]??'')==='--monitor') {
        $start=microtime(true);
        try {
            $snapshot=$reader->snapshot();
            $monitor=VoipIxcMonitor::ingest(json_encode($snapshot,JSON_THROW_ON_ERROR));
        } catch (Throwable $error) {
            // Reader diagnostics contain only fixed resource names/page/reason.
            $message=$error->getMessage();
            if (preg_match('/^IXC GET (cliente_contrato|vd_contratos_produtos) pagina=[0-9]+ (timeout|http_ou_transporte)$/D',$message))
                fwrite(STDERR,$message.PHP_EOL);
            fwrite(STDERR,"MONITOR FALHOU: snapshot incompleto; estado anterior preservado; reserva nao iniciada.\n");
            exit(2);
        }
        echo 'MONITOR OK: contratos='.count($monitor['contracts']).
            ' duracao_s='.round(microtime(true)-$start,2).PHP_EOL;
    } else {
        $monitor=VoipIxcMonitor::read();
    }
    if ($monitor===null) throw new RuntimeException('Monitor IXC nao configurado.');
    $stockFile='/var/lib/jrconect-voip/stock.json';
    $stockMeta=is_file($stockFile) ? stat($stockFile) : null;
    $stock=new VoipStock();
    $writeIxc=null;
    if (is_file($writeGate) && !is_link($writeGate)) {
        $gateStat=stat($writeGate);
        $gateValue=trim((string)file_get_contents($writeGate));
        if (!is_array($gateStat) || $gateStat['uid']!==0 || ($gateStat['mode']&0077)!==0 ||
            $gateValue!=='ENABLE_LOCAL_IXC_WRITE') {
            throw new RuntimeException('Gate de escrita IXC inseguro.');
        }
        if (!is_file($writerPath) || is_link($writerPath)) {
            throw new RuntimeException('Writer IXC ausente.');
        }
        require_once $writerPath;
        $writer=new VoipIxcControlledWriter();
        $writeIxc=static function(string $contractId,string $productId,string $before,string $after) use ($writer): void {
            $writer->writeObservationPair($contractId,$productId,$before,$after);
        };
    }
    $result=VoipAutomaticReservation::process(
        $monitor,$stock,
        static fn(string $contractId,string $productId):array=>
            $reader->readFresh($contractId,$productId),
        $writeIxc
    );
    // Root performs the atomic stock write; restore the web worker's original
    // ownership/mode so the panel keeps reading the audit and links.
    if (is_array($stockMeta) && is_file($stockFile)) {
        if (!chown($stockFile,(int)$stockMeta['uid']) ||
            !chgrp($stockFile,(int)$stockMeta['gid']) ||
            !chmod($stockFile,(int)$stockMeta['mode'] & 0777)) {
            throw new RuntimeException('Nao foi possivel restaurar acesso ao estoque.');
        }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),PHP_EOL;
    if ($result['falhas']>0) exit(2);
} catch (Throwable $error) {
    fwrite(STDERR,"BLOQUEADO: reserva automatica nao concluiu; nao houve escrita IXC.\n");
    exit(2);
}

