<?php
declare(strict_types=1);
// Root-only VoIP controlled assignment. NO cron, no HTTP route, no automatic activation.
if (PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0) {
    fwrite(STDERR,"BLOQUEADO: execute como root pelo PHP CLI.\n");
    exit(2);
}
require_once is_file('/var/www/gacs/lib/voip-stock.php')
    ? '/var/www/gacs/lib/voip-stock.php' : __DIR__.'/../lib/voip-stock.php';
require_once is_file('/var/www/gacs/lib/voip-auto-assign.php')
    ? '/var/www/gacs/lib/voip-auto-assign.php' : __DIR__.'/../lib/voip-auto-assign.php';
require_once is_file('/var/www/gacs/lib/voip-ixc-controlled-writer.php')
    ? '/var/www/gacs/lib/voip-ixc-controlled-writer.php' : __DIR__.'/../lib/voip-ixc-controlled-writer.php';

try {
    $mode=$argv[1]??'';
    if (!in_array($mode,['--dry-run','--execute-4740'],true) || count($argv)!==2)
        throw new RuntimeException('Modo invalido.');
    $stock=new VoipStock();
    $writer=new VoipIxcControlledWriter();
    $input=$writer->readFresh('4740');
    $valid=VoipAutoAssignment::validateRequest($input['contract'],$input['product_rows'],'4740');
    $inventory=$stock->read();
    $free=[];
    foreach ($inventory['numbers'] as $number=>$row) {
        if ((string)($row['contract_id']??'')==='4740' ||
            (string)($row['product_record_id']??'')==='571') {
            throw new RuntimeException('Contrato ja vinculado ou reservado.');
        }
        if (($row['status']??null)==='disponivel' &&
            empty($row['contract_id']) && empty($row['product_record_id']) &&
            empty($row['reservation_id'])) $free[]=(string)$number;
    }
    sort($free,SORT_STRING);
    if (!$free) throw new RuntimeException('Sem DIDs disponiveis.');
    if ($mode==='--dry-run') {
        echo json_encode([
            'contrato'=>'4740','produto_registro'=>'571',
            'did_candidato'=>$free[0],'disponiveis'=>count($free),
            'resultado_previsto'=>VoipAutoAssignment::replacement(
                $valid['observation'],'4740',$free[0]),
            'executado'=>false,'gravacao_ixc'=>false,'reserva_did'=>false,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
        exit(0);
    }
    // Separate operator enable switch. Not stored in a repo, not part of cron.
    if (getenv('JRC_VOIP_CONFIRM_WRITE')!=='4740:571' ||
        !is_file('/etc/jrconect/voip-ixc-writer-approved')) {
        throw new RuntimeException('Escrita de piloto nao autorizada.');
    }
    $st=stat('/etc/jrconect/voip-ixc-writer-approved');
    if (!is_array($st) || $st['uid']!==0 || ($st['mode']&0077)!==0 ||
        is_link('/etc/jrconect/voip-ixc-writer-approved')) {
        throw new RuntimeException('Arquivo de autorizacao inseguro.');
    }
    $result=VoipAutoAssignment::execute(
        '4740',$stock,
        static fn(string $id):array=>$writer->readFresh($id),
        static fn(string $id,string $before,string $after)=>$writer->writeObservation($id,$before,$after),
        'voip-ixc-approved-writer'
    );
    echo json_encode([
        'status'=>$result['status'],
        'contrato_id'=>$result['contract_id'],
        'did'=>$result['number'],
        'observacao_registrada'=>'JRC_VOIP_CONTRATO=4740;DID='.$result['number'],
        'confirmado_por_readback'=>true
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
} catch(Throwable $e) {
    // Do not leak contract fields or credentials. On ambiguous PUT failures,
    // VoipAutoAssignment retains the DID reservation for manual review.
    fwrite(STDERR,"BLOQUEADO: piloto nao concluiu. Conferir status do estoque e observacao IXC sem repetir cegamente.\n");
    exit(2);
}
