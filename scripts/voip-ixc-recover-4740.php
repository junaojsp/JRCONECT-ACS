<?php
declare(strict_types=1);
// Root-only recovery tool. Never creates or releases a DID.
if(PHP_SAPI!=='cli'||!function_exists('posix_geteuid')||posix_geteuid()!==0){
 fwrite(STDERR,"BLOQUEADO: somente root CLI.\n");exit(2);
}
require_once is_file('/var/www/gacs/lib/voip-stock.php') ? '/var/www/gacs/lib/voip-stock.php' : __DIR__.'/../lib/voip-stock.php';
require_once is_file('/var/www/gacs/lib/voip-auto-assign.php') ? '/var/www/gacs/lib/voip-auto-assign.php' : __DIR__.'/../lib/voip-auto-assign.php';
require_once __DIR__.'/../lib/voip-ixc-controlled-writer.php';
require_once __DIR__.'/../lib/voip-reserved-recovery.php';

try {
 $mode=$argv[1]??''; $path=$argv[2]??'';
 if(count($argv)!==3 || !in_array($mode,['--inspect','--resume-4740'],true) ||
   !preg_match('#^/root/voip-4740-final-[0-9]{8}-[0-9]{6}/contrato-4740\.json$#D',$path) ||
   !is_file($path) || is_link($path)) throw new RuntimeException('Backup ou modo invalido.');
 $s=stat($path);
 if(!is_array($s)||$s['uid']!==0||($s['mode']&0077)!==0)
   throw new RuntimeException('Backup inseguro.');
 $raw=file_get_contents($path);
 if(!is_string($raw)||strlen($raw)>200000) throw new RuntimeException('Backup inacessivel.');
 $baseline=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
 if(!is_array($baseline))throw new RuntimeException('Backup invalido.');
 $stock=new VoipStock();
 $writer=new VoipIxcControlledWriter();
 $state=VoipReservedRecovery::inspect($stock->read(),$writer->readFresh('4740'),$baseline);
 if($mode==='--inspect'){
   echo json_encode(VoipReservedRecovery::publicStatus($state),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
   exit(0);
 }
 // Prevent two operator sessions from running the same PUT concurrently.
 $lock=fopen('/run/lock/jrconect-voip-4740-recovery.lock','c');
 if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))
   throw new RuntimeException('Recuperacao em andamento em outra sessao.');
 // Re-read stock and IXC AFTER the lock in recover(), never reuse pre-lock state.
 if(getenv('JRC_VOIP_CONFIRM_RECOVERY')!=='4740:571' ||
    !is_file('/etc/jrconect/voip-ixc-writer-approved') ||
    is_link('/etc/jrconect/voip-ixc-writer-approved')) throw new RuntimeException('Recuperacao nao autorizada.');
 $auth=stat('/etc/jrconect/voip-ixc-writer-approved');
 if(!is_array($auth)||$auth['uid']!==0||($auth['mode']&0077)!==0)
   throw new RuntimeException('Autorizacao insegura.');
 $out=VoipReservedRecovery::recover($stock,$writer,$baseline,true,'voip-ixc-approved-writer');
 echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $e){
 // Diagnostic stays numeric/sanitized only, never prints contract fields or credentials.
 $messages=[
   'IXC PUT HTTP 400.','IXC PUT HTTP 401.','IXC PUT HTTP 403.',
   'IXC PUT HTTP 404.','IXC PUT HTTP 409.','IXC PUT HTTP 422.',
   'IXC PUT HTTP 500.','IXC PUT HTTP 502.','IXC PUT HTTP 503.',
   'IXC PUT retornou indicacao de falha.',
   'IXC PUT retornou texto de erro.',
 ];
 $reason=$e->getMessage();
 if(!in_array($reason,$messages,true)) $reason='Verificar reserva, backup, leitura IXC e permissao de edicao.';
 fwrite(STDERR,"BLOQUEADO: ".$reason." DID continua reservado; nao executar novamente sem conferir IXC.\n");
 exit(2);
}
