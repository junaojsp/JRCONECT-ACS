<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/voip-operational.php';
function verify(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $message);
}
$stock = [];
for ($i=1; $i<=30; $i++) {
    $stock[] = [
        'number' => '115196'.str_pad((string)$i, 4, '0', STR_PAD_LEFT),
        'holder' => $i <= 13 ? 'CLIENTE ' . $i : '',
        'status' => $i <= 13 ? 'ocupado' : 'a_conferir',
    ];
}
$rows = [];
for ($i=1; $i<=13; $i++) {
    $matched = $i <= 8;
    $rows[] = [
        'contract_id' => (string)(2000+$i),
        'status' => $i <= 9 ? 'A' : 'I',
        'classification' => $matched ? 'unica' : 'sem_correspondencia',
        'candidates' => $matched ? [$stock[$i-1]['number']] : [],
    ];
}
$result = VoipOperational::summarize($stock, ['rows'=>$rows]);
verify($result['stock']['ocupado'] === 13, 'occupied unchanged');
verify($result['stock']['a_conferir'] === 17, 'review unchanged');
verify($result['stock']['disponivel'] === 0, 'no false available');
verify($result['suggested_unique'] === 8, 'unique suggestions');
verify($result['occupied_without_suggestion'] === 5, 'five occupied without suggestion');
verify(count($result['contracts_status_A_without_phone']) === 1, 'one status A without suggested number');
verify(count($result['contracts_without_phone']) === 5, 'five missing contract matches');
verify($result['raw_status'] === ['A'=>9,'I'=>4], 'raw IXC status');
verify($result['confirmed_links'] === 0, 'no false linked numbers');
verify($result['availability_from_ixc'] === null, 'IXC never grants availability');
verify($stock[12]['status'] === 'ocupado', 'original stock untouched');
try {
    VoipOperational::summarize($stock, ['rows'=>[
        ['contract_id'=>'100','status'=>'A','classification'=>'unica','candidates'=>['1199999999']]
    ]]);
    throw new RuntimeException('FAIL unknown DID accepted');
} catch (RuntimeException $error) {
    verify(str_contains($error->getMessage(), 'Candidato telefônico inválido.'), 'fails closed on unknown DID');
}
echo "OK: indicadores e alertas de consulta somente leitura.\n";
