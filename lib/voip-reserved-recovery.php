<?php
declare(strict_types=1);

/** Recover an existing reservation ONLY, without ever allocating another DID. */
final class VoipReservedRecovery
{
    private static function sameExceptObservation(array $baseline, array $current): bool
    {
        unset($baseline['obs_contrato'], $current['obs_contrato']);
        return $baseline === $current;
    }

    public static function inspect(array $inventory, array $fresh, array $baseline): array
    {
        if (($inventory['version'] ?? null) !== 1 || !is_array($inventory['numbers'] ?? null) ||
            !is_array($fresh['contract'] ?? null) || !is_array($fresh['product_rows'] ?? null) ||
            count($fresh['product_rows']) !== 1 ||
            (string)($fresh['contract']['id'] ?? '') !== '4740' ||
            (string)($fresh['product_rows'][0]['id'] ?? '') !== '571' ||
            (string)($fresh['product_rows'][0]['id_contrato'] ?? '') !== '4740' ||
            (string)($fresh['product_rows'][0]['id_produto'] ?? '') !== '74' ||
            ($fresh['contract']['status'] ?? null) !== 'A' ||
            (string)($baseline['id'] ?? '') !== '4740') {
            throw new RuntimeException('Contrato, produto ou baseline divergente.');
        }

        $hits = [];
        foreach ($inventory['numbers'] as $number => $row) {
            if (!is_array($row)) throw new RuntimeException('Estoque invalido.');
            if ((string)($row['contract_id'] ?? '') === '4740' ||
                (string)($row['product_record_id'] ?? '') === '571') {
                $hits[] = ['number'=>(string)$number,'row'=>$row];
            }
        }
        if (count($hits) !== 1) throw new RuntimeException('Reserva ausente ou duplicada: nenhuma nova reserva permitida.');
        $number = $hits[0]['number'];
        $row = $hits[0]['row'];
        if (!preg_match('/^[1-9][0-9]{9}$/D', $number) ||
            ($row['status'] ?? null) !== 'reservado' ||
            (string)($row['contract_id'] ?? '') !== '4740' ||
            (string)($row['product_record_id'] ?? '') !== '571' ||
            !preg_match('/^[a-f0-9]{32}$/D', (string)($row['reservation_id'] ?? ''))) {
            throw new RuntimeException('Reserva nao e integra para recuperacao.');
        }
        if (!self::sameExceptObservation($baseline, $fresh['contract'])) {
            throw new RuntimeException('Dados do contrato mudaram desde o backup. Revisao manual obrigatoria.');
        }
        $previous = $baseline['obs_contrato'] ?? null;
        $observation = $fresh['contract']['obs_contrato'] ?? null;
        if (!is_string($previous) || !is_string($observation) ||
            substr_count($previous, VoipAutoAssignment::MARKER) !== 1 ||
            str_contains($previous, 'JRC_VOIP_CONTRATO=')) {
            throw new RuntimeException('Baseline da observacao nao corresponde ao pedido original.');
        }
        $next=VoipAutoAssignment::replacement($previous,'4740',$number);
        if ($observation === $next) $status='confirm_only';
        elseif ($observation === $previous) $status='write_pending';
        else throw new RuntimeException('Observacao diferente do backup e do vinculo esperado.');

        return ['status'=>$status,'did'=>$number,'contract_id'=>'4740','product_record_id'=>'571',
            'reservation'=>['number'=>$number,'contract_id'=>'4740','product_record_id'=>'571',
                'reservation_id'=>$row['reservation_id']],
            'before'=>$previous,'next'=>$next];
    }

    public static function recover(
        VoipStock $stock, VoipIxcControlledWriter $writer,
        array $baseline, bool $writeApproved, string $actor
    ): array {
        if ($actor !== 'voip-ixc-approved-writer') throw new RuntimeException('Operador nao autorizado.');
        $state=self::inspect($stock->read(),$writer->readFresh('4740'),$baseline);
        if ($state['status'] === 'write_pending') {
            if (!$writeApproved) throw new RuntimeException('PUT nao autorizado: somente diagnostico.');
            // Independent fresh GET + full-record PUT + readback are performed by the writer.
            // If a PUT times out, the reservation stays reserved and should be inspected again.
            try {
                $writer->writeObservation('4740',$state['before'],$state['next'],$baseline);
            } catch (Throwable $putFailure) {
                // A 2xx response, network timeout or error decoding is not proof
                // of failure. Perform a NEW independent GET. If the exact DID was
                // persisted and no other contract field changed, finish the existing
                // reservation. Otherwise preserve it and report the original error.
                try {
                    $afterFailure=self::inspect(
                        $stock->read(), $writer->readFresh('4740'), $baseline
                    );
                    if ($afterFailure['status'] !== 'confirm_only' ||
                        $afterFailure['did'] !== $state['did']) {
                        throw $putFailure;
                    }
                } catch (Throwable $readbackFailure) {
                    // Never auto-release or reserve a second number.
                    throw $putFailure;
                }
            }
        }
        $verified=self::inspect($stock->read(),$writer->readFresh('4740'),$baseline);
        if ($verified['status'] !== 'confirm_only' ||
            $verified['did'] !== $state['did']) {
            throw new RuntimeException('Readback nao confirmou DID reservado.');
        }
        $stock->confirmVoipAssignment($state['reservation'],true,$actor);
        return ['status'=>'ocupado','contrato_id'=>'4740','produto_registro_id'=>'571',
            'did'=>$state['did'],'confirmado_por_readback'=>true];
    }

    public static function publicStatus(array $state): array
    {
        return ['contrato_id'=>'4740','produto_registro_id'=>'571','did_reservado'=>$state['did'],
            'acao_recomendada'=>$state['status']==='confirm_only'?'confirmar_reserva':'put_pendente',
            'nova_reserva'=>false,'gravacao_ixc'=>false,'liberacao_did'=>false];
    }
}
