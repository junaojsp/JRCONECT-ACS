<?php
declare(strict_types=1);

/**
 * Read-only IXC eligibility, atomic local VoIP reservation.
 * Does NOT write to IXC, activate service, mark DID occupied or release DID.
 */
final class VoipAutomaticReservation
{
    public static function process(array $monitor, VoipStock $stock, callable $readFresh): array
    {
        if (($monitor['version']??null)!==1 || ($monitor['mode']??null)!=='read_only' ||
            !is_array($monitor['contracts']??null) ||
            count($monitor['contracts'])>200 ||
            !is_string($monitor['generated_at']??null)) {
            throw new RuntimeException('Monitor IXC invalido.');
        }
        $timestamp=strtotime($monitor['generated_at']);
        if ($timestamp===false || $timestamp>time()+300 || time()-$timestamp>600) {
            throw new RuntimeException('Monitor IXC desatualizado. Nenhuma reserva executada.');
        }
        $summary=['consultados'=>0,'reservados'=>0,'ja_vinculados'=>0,
            'sem_marcador_ou_inelegiveis'=>0,'falhas'=>0,'novos'=>[]];
        foreach ($monitor['contracts'] as $id=>$item) {
            $contractId=(string)$id;
            if (!preg_match('/^[0-9]{1,12}$/D',$contractId) || !is_array($item) ||
                !is_array($item['product_record_ids']??null)) {
                throw new RuntimeException('Monitor possui contrato invalido.');
            }
            if (($item['status']??null)!=='A' || count($item['product_record_ids'])!==1) {
                $summary['sem_marcador_ou_inelegiveis']++;
                continue;
            }
            $productId=(string)$item['product_record_ids'][0];
            if (!preg_match('/^[0-9]{1,12}$/D',$productId)) {
                throw new RuntimeException('Produto no monitor invalido.');
            }
            // Re-read stock on each iteration; lock inside reserveForVoip is the
            // final arbiter under concurrent manual/automated operations.
            $existing=false;
            foreach ($stock->read()['numbers'] as $row) {
                if ((string)($row['contract_id']??'')===$contractId ||
                    (string)($row['product_record_id']??'')===$productId) {
                    $existing=true;break;
                }
            }
            if ($existing) {
                $summary['ja_vinculados']++;
                continue;
            }
            $summary['consultados']++;
            try {
                $fresh=$readFresh($contractId,$productId);
                if (!is_array($fresh) || !is_array($fresh['contract']??null) ||
                    !is_array($fresh['product_rows']??null) ||
                    ($fresh['contract']['status']??null)!=='A') {
                    throw new RuntimeException('Leitura IXC incompleta.');
                }
                $valid=VoipAutoAssignment::validateRequest(
                    $fresh['contract'],$fresh['product_rows'],$contractId
                );
                if ($valid['product_record_id']!==$productId) {
                    throw new RuntimeException('Produto alterado durante consulta.');
                }
                $reservation=$stock->reserveForVoip(
                    $contractId,$productId,'voip-auto-reserve-monitor'
                );
                $summary['reservados']++;
                $summary['novos'][]=[
                    'contrato_id'=>$contractId,'produto_registro_id'=>$productId,
                    'did'=>$reservation['number'],'status'=>'reservado',
                    'sincronizacao_ixc'=>'manual_pendente'
                ];
            } catch (Throwable $error) {
                // Missing marker is normal; other errors are reported only as
                // aggregate to avoid logging IXC contract observations or PII.
                if (str_contains($error->getMessage(),'Solicitação ausente, ambígua ou já processada.')) {
                    $summary['sem_marcador_ou_inelegiveis']++;
                } else {
                    $summary['falhas']++;
                }
            }
        }
        return $summary;
    }
}
