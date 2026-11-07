<?php
declare(strict_types=1);
// Operator-run, read-only pilot for one contract. Does not reserve or change DIDs.
// Expected input: sanitized JSON from scripts/voip-pilot-4740-ixc-readonly.mjs.
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "BLOQUEADO: somente CLI.\n"); exit(2); }
require_once is_file('/var/www/gacs/lib/voip-stock.php')
    ? '/var/www/gacs/lib/voip-stock.php'
    : __DIR__ . '/../lib/voip-stock.php';
const PILOT_CONTRACT = '4740';
const PILOT_PRODUCT = '571';

function voipPilotPreview(array $snapshot, array $stock): array
{
    if (($snapshot['contrato_id'] ?? null) !== PILOT_CONTRACT ||
        ($snapshot['produto_registro_id'] ?? null) !== PILOT_PRODUCT ||
        ($snapshot['produto_id'] ?? null) !== '74' ||
        ($snapshot['observacao_marcador_unico'] ?? null) !== true ||
        ($snapshot['somente_leitura'] ?? null) !== true ||
        ($snapshot['escrita_ixc'] ?? null) !== false ||
        !is_string($snapshot['status_ixc_bruto'] ?? null) ||
        strlen($snapshot['status_ixc_bruto']) > 40) {
        throw new RuntimeException('Snapshot piloto inválido.');
    }
    if (($stock['version'] ?? null) !== 1 || !is_array($stock['numbers'] ?? null)) {
        throw new RuntimeException('Estoque inválido.');
    }
    $candidates = [];
    foreach ($stock['numbers'] as $num => $row) {
        if (!is_array($row)) throw new RuntimeException('Registro do estoque inválido.');
        $n = (string)$num;
        if (!preg_match('/^[1-9][0-9]{9}$/D', $n)) {
            throw new RuntimeException('Número inválido no estoque.');
        }
        if ((string)($row['contract_id'] ?? '') === PILOT_CONTRACT ||
            (string)($row['product_record_id'] ?? '') === PILOT_PRODUCT) {
            return [
                'contrato_id'=>PILOT_CONTRACT,
                'produto_registro_id'=>PILOT_PRODUCT,
                'status'=>'ja_vinculado_ou_reservado',
                'did_candidato'=>null,
                'disponiveis'=>0,
                'escrita_ixc'=>false,
                'reserva_did'=>false,
                'estoque_modificado'=>false,
            ];
        }
        if (($row['status'] ?? null) === 'disponivel' &&
            empty($row['contract_id']) && empty($row['product_record_id']) &&
            empty($row['reservation_id'])) {
            $candidates[] = $n;
        }
    }
    sort($candidates, SORT_STRING);
    $first = $candidates[0] ?? null;
    return [
        'contrato_id'=>PILOT_CONTRACT,
        'produto_registro_id'=>PILOT_PRODUCT,
        'status'=>$first !== null ? 'apto_para_proxima_fase' : 'sem_estoque',
        'status_ixc_bruto'=>$snapshot['status_ixc_bruto'],
        'marcador_confirmado'=>true,
        'disponiveis'=>count($candidates),
        'did_candidato'=>$first,
        'resultado_previsto'=>$first === null ? null :
            'JRC_VOIP_CONTRATO=' . PILOT_CONTRACT . ';DID=' . $first,
        'escrita_ixc'=>false,
        'reserva_did'=>false,
        'estoque_modificado'=>false,
    ];
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $raw = stream_get_contents(STDIN, 4097);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 4096) {
            throw new RuntimeException('Entrada ausente/inválida');
        }
        $snapshot = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) throw new RuntimeException('Entrada inválida');
        $store = new VoipStock();
        $out = voipPilotPreview($snapshot, $store->read());
        echo json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    } catch (Throwable) {
        fwrite(STDERR, "BLOQUEADO: preview nao concluido. Nenhuma atribuicao ou escrita realizada.\n");
        exit(2);
    }
}
