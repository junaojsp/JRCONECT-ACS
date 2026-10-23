<?php
require_once __DIR__ . '/../lib/NocIxcOptical.php';

function check($actual, $expected, string $label): void
{
    if ($actual !== $expected) throw new RuntimeException('Failed: ' . $label);
}

foreach ([['-25,40', -25.4], [-50, -50.0], [-50.01, null], [0, null],
    [10, null], ['', null], [null, null], [false, null], [['bad'], null], ['INF', null]] as $case) {
    check(App\NocIxcOptical::normalizeRx($case[0]), $case[1], 'RX normalization');
}
check(App\NocIxcOptical::serialAliases('48:57:54:43:12:34:56:78'),
    ['4857544312345678', 'HWTC12345678'], 'Hex vendor alias');
check(App\NocIxcOptical::serialAliases('hwtc12345678'),
    ['HWTC12345678', '4857544312345678'], 'ASCII vendor alias');
check(App\NocIxcOptical::serialAliases(''), [], 'Empty serial');
foreach (['http://example.com', 'https://user:pass@example.com', 'https://example.com?token=x', 'file:///tmp/data'] as $url) {
    try {
        new App\NocIxcOptical(['base_url' => $url, 'token' => 'synthetic']);
        throw new LogicException('Unsafe connection accepted');
    } catch (RuntimeException $e) {
        // Expected configuration rejection.
    }
}
echo "PASS RX validation, serial aliases and connection configuration\n";
