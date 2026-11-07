<?php
declare(strict_types=1);

/**
 * VoIP request processor. No network client is installed by this PR.
 * Writer and readback functions must be explicitly provided by a separately
 * approved integration. Do not invoke in the existing 15-minute monitor.
 */
final class VoipAutoAssignment
{
    public const MARKER = '#JRC_VOIP_SOLICITAR#';
    public const CANCEL_MARKER = '#JRC_VOIP_CANCELAR#';

    public static function cancellationRequested(string $observation): bool
    {
        return substr_count($observation, self::CANCEL_MARKER) === 1;
    }

    public static function assignmentPresent(string $observation, string $contractId, string $did): bool
    {
        return preg_match('/JRC_VOIP_CONTRATO='.preg_quote($contractId,'/').';DID='.preg_quote($did,'/').'\b/', $observation) === 1;
    }

    public static function replacement(string $observation, string $contractId, string $did): string
    {
        if (!preg_match('/^[0-9]{1,12}$/D', $contractId) ||
            !preg_match('/^[1-9][0-9]{9}$/D', $did) ||
            strlen($observation) > 40000) {
            throw new RuntimeException('Parâmetros de atribuição inválidos.');
        }
        if (substr_count($observation, self::MARKER) !== 1) {
            throw new RuntimeException('Marcador ausente ou duplicado: revisão necessária.');
        }
        if (str_contains($observation, 'JRC_VOIP_CONTRATO=')) {
            throw new RuntimeException('Observação já tem vínculo VoIP; revisão necessária.');
        }
        return str_replace(self::MARKER, 'JRC_VOIP_CONTRATO=' . $contractId . ';DID=' . $did, $observation);
    }

    /**
     * Fail-closed validation; never infer IXC status A/I semantics.
     * Product row ID must be unique, exact, and supplied by fresh IXC lookup.
     */
    public static function validateRequest(array $contract, array $productRows, string $expectedContractId): array
    {
        if (!preg_match('/^[0-9]{1,12}$/D', $expectedContractId) ||
            (string)($contract['id'] ?? '') !== $expectedContractId ||
            !is_string($contract['obs_contrato'] ?? null)) {
            throw new RuntimeException('Contrato/campo de observação não confirmado.');
        }
        $observation = $contract['obs_contrato'];
        if (substr_count($observation, self::MARKER) !== 1 ||
            str_contains($observation, 'JRC_VOIP_CONTRATO=')) {
            throw new RuntimeException('Solicitação ausente, ambígua ou já processada.');
        }
        if (count($productRows) !== 1) {
            throw new RuntimeException('Necessário exatamente um registro do produto 74.');
        }
        $product = $productRows[0];
        $recordId = (string)($product['id'] ?? '');
        if (!preg_match('/^[0-9]{1,12}$/D', $recordId) ||
            (string)($product['id_contrato'] ?? '') !== $expectedContractId ||
            (string)($product['id_produto'] ?? '') !== '74') {
            throw new RuntimeException('Produto 74 divergente do contrato.');
        }
        return ['contract_id'=>$expectedContractId,'product_record_id'=>$recordId,
            'observation'=>$observation];
    }

    /**
     * All hooks must be supplied explicitly. No default writer or scheduler.
     *
     * readFresh(): ['contract'=>..., 'product_rows'=>...], retrieved from IXC.
     * writeObservation($contractId, $previousObservation, $nextObservation):
     * MUST be a separately validated conditional, minimal-scope IXC writer.
     * readFresh is used again to verify exact saved observation and product.
     *
     * Any fault AFTER reservation leaves the DID reserved (not available).
     */
    public static function execute(
        string $contractId,
        VoipStock $stock,
        callable $readFresh,
        callable $writeObservation,
        string $actor
    ): array {
        if ($actor !== 'voip-ixc-approved-writer') {
            throw new RuntimeException('Escrita automática ainda não autorizada.');
        }
        $original = $readFresh($contractId);
        if (!is_array($original) || !is_array($original['contract'] ?? null) ||
            !is_array($original['product_rows'] ?? null)) {
            throw new RuntimeException('Leitura IXC incompleta.');
        }
        $valid = self::validateRequest($original['contract'], $original['product_rows'], $contractId);
        $reservation = $stock->reserveForVoip($contractId, $valid['product_record_id'], $actor);
        $next = self::replacement($valid['observation'], $contractId, $reservation['number']);
        try {
            // MUST NOT be a blind PUT: check full-field semantics/version first.
            $writeObservation($contractId, $valid['observation'], $next);
            $again = $readFresh($contractId);
            if (!is_array($again) || !is_array($again['contract'] ?? null) ||
                !is_array($again['product_rows'] ?? null) ||
                (string)($again['contract']['id'] ?? '') !== $contractId ||
                (string)($again['contract']['obs_contrato'] ?? '') !== $next) {
                throw new RuntimeException('Confirmação de escrita IXC não obtida.');
            }
            if (count($again['product_rows']) !== 1 ||
                (string)($again['product_rows'][0]['id'] ?? '') !== $valid['product_record_id'] ||
                (string)($again['product_rows'][0]['id_produto'] ?? '') !== '74' ||
                (string)($again['product_rows'][0]['id_contrato'] ?? '') !== $contractId) {
                throw new RuntimeException('Produto 74 alterado durante a operação.');
            }
            $stock->confirmVoipAssignment($reservation, true, $actor);
            return ['status'=>'ocupado', 'contract_id'=>$contractId,
                'number'=>$reservation['number']];
        } catch (Throwable $error) {
            // Do not auto-release: PUT can succeed even if the network times out.
            throw new RuntimeException('Atribuição pendente de revisão; DID mantido reservado.', 0, $error);
        }
    }
}
