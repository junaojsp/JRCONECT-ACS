<?php
require_once dirname(__DIR__) . '/lib/CPEProfiles.php';
require_once dirname(__DIR__) . '/lib/OpticalHistory.php';

function historyCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$now = strtotime('2026-10-05T22:00:00Z');
$fiber = ['sinal_rx' => '-22,5', 'sinal_tx' => '2.1', 'data_sinal' => '2026-10-05 19:00:00'];
$sample = acsOpticalSample('hwtc12345678', $fiber, $now);
historyCheck($sample['rx_power'] === -22.5 && $sample['tx_power'] === 2.1, 'Potências decimais');
historyCheck($sample['measured_at'] === '2026-10-05 22:00:00', 'Timezone IXC -> UTC');
historyCheck($sample['sample_key'] === acsOpticalSample('HWTC12345678', $fiber, $now + 900)['sample_key'], 'Leitura repetida não é nova medição');
historyCheck(acsOpticalMeasuredAt('05/10/2026 19:00:00')->format('c') === '2026-10-05T22:00:00+00:00', 'Data brasileira');
historyCheck(acsOpticalMeasuredAt('2026-10-05T22:00:00Z')->format('c') === '2026-10-05T22:00:00+00:00', 'Data ISO');
foreach ([-999, 'NaN', '', null, true, [], 99] as $value) {
    historyCheck(acsOpticalSample('SN1', ['sinal_rx' => $value], $now) === null, 'RX inválido não vira zero');
}
foreach (['0000-00-00 00:00:00', '2026-02-30 10:00:00', 'invalid', [], '2026-10-06 19:00:00', '2025-01-01 19:00:00'] as $date) {
    historyCheck(acsOpticalSample('SN1', ['sinal_rx' => -20, 'data_sinal' => $date], $now) === null, 'Horário inválido, futuro ou fora da retenção');
}
$observed = acsOpticalSample('SN1', ['sinal_rx' => -20], $now);
historyCheck($observed['measured_at'] === null, 'Horário observado distinto de horário medido');
historyCheck($observed['sample_key'] === acsOpticalSample('SN1', ['sinal_rx' => -20], $now + 30)['sample_key'], 'Uma observação por intervalo');
historyCheck($observed['sample_key'] !== acsOpticalSample('SN1', ['sinal_rx' => -20], $now + 900)['sample_key'], 'Nova observação no intervalo seguinte');
$device = fn($serial) => ['_id' => 'OUI-MODEL-' . $serial, '_deviceId' => ['_SerialNumber' => $serial]];
$data = acsOpticalMatchSamples([$device('HWTC12345678'), $device('HWTC12345678'), $device('SN2'), $device('SN3')],
    [array_merge($fiber, ['mac' => '4857544312345678']), ['mac' => 'SN3', 'sinal_rx' => -20], ['mac' => 'SN3', 'sinal_rx' => -21]], $now);
historyCheck(count($data['samples']) === 1, 'Aliases e deduplicação');
historyCheck($data['stats']['without_link'] === 1 && $data['stats']['ambiguous'] === 1, 'Vínculos ausentes e ambíguos');
echo "PASS: potências, horários, deduplicação, retenção, aliases e vínculos ambíguos\n";
