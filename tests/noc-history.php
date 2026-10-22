<?php
require_once __DIR__ . '/../lib/NocHistory.php';
require_once __DIR__ . '/../lib/NocIxcOptical.php';
$root = $argv[1];
putenv('NOC_CACHE_DIR=' . $root);
$credentials = ['endpoint' => 'synthetic', 'token' => 'test-only'];
$now = 1800000000;
$summary = ['total' => 100, 'online' => 90];
$old = App\NocHistory::record($credentials, $summary, $now - 90000);
$first = App\NocHistory::record($credentials, $summary, $now - 600);
assert(count($first['points']) === 1);
$duplicate = App\NocHistory::record($credentials, $summary, $now - 590);
assert(count($duplicate['points']) === 1);
$current = App\NocHistory::record($credentials, ['total' => 100, 'online' => 80], $now);
assert(count($current['points']) === 2);
assert($current['points'][1]['online'] === 80);
assert($current['points'][1]['at'] - $current['points'][0]['at'] === 600);
$separate = App\NocHistory::record(['endpoint' => 'other'], $summary, $now);
assert(count($separate['points']) === 1);
foreach ([[null, 'unavailable'], [-28.0, 'critical'], [-28.01, 'critical'],
    [-27.99, 'attention'], [-25.0, 'attention'], [-24.99, 'good']] as [$rx, $band]) {
    assert(App\NocIxcOptical::rxBand($rx) === $band);
}
echo "PASS history retention, deduplication, source isolation and optical boundaries\n";
