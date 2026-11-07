<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(2); }
require_once is_file('/var/www/gacs/lib/voip-stock.php') ? '/var/www/gacs/lib/voip-stock.php' : __DIR__.'/../lib/voip-stock.php';
require_once is_file('/var/www/gacs/lib/voip-full-audit.php') ? '/var/www/gacs/lib/voip-full-audit.php' : __DIR__.'/../lib/voip-full-audit.php';
try {
    $raw=stream_get_contents(STDIN,100001);
    if(!is_string($raw)||strlen($raw)>100000||$raw==='') throw new RuntimeException('Entrada invalida');
    $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new RuntimeException('Snapshot invalido');
    $result=VoipFullAudit::compare($data,(new VoipStock())->read());
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $e) {
    fwrite(STDERR,"Auditoria bloqueada: snapshot incompleto ou estoque inconsistente. Nenhuma alteracao realizada.\n");
    exit(2);
}
