<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/voip-link-prefill.php';
function ok(bool $value, string $why): void {
    if (!$value) throw new RuntimeException('FAIL: ' . $why);
}
$stock = [
    '1151961252' => ['number'=>'1151961252','status'=>'ocupado','contract_id'=>null,'product_record_id'=>null],
    '1151961253' => ['number'=>'1151961253','status'=>'a_conferir','contract_id'=>null,'product_record_id'=>null],
    '1151961254' => ['number'=>'1151961254','status'=>'ocupado','contract_id'=>'4444','product_record_id'=>'8888'],
];
$match = ['classification'=>'unica','candidates'=>['1151961252'],'contract_id'=>'2210',
    'product_ids'=>['396'],'status'=>'A'];
ok(VoipLinkPrefill::suggest($match, $stock) === [
    'number'=>'1151961252','contract_id'=>'2210','product_record_id'=>'396'
], 'unique unlinked suggestion');
ok(VoipLinkPrefill::suggest(array_merge($match,['classification'=>'ambigua']),$stock) === null, 'ambiguous blocked');
ok(VoipLinkPrefill::suggest(array_merge($match,['product_ids'=>['396','397']]),$stock) === null, 'multiple product IDs blocked');
ok(VoipLinkPrefill::suggest(array_merge($match,['candidates'=>['1151961253']]),$stock) === null, 'unverified DID blocked');
ok(VoipLinkPrefill::suggest(array_merge($match,['candidates'=>['1151961254']]),$stock) === null, 'linked DID blocked');
ok(VoipLinkPrefill::suggest(array_merge($match,['candidates'=>['1151960000']]),$stock) === null, 'unknown DID blocked');
ok(VoipLinkPrefill::suggest(array_merge($match,['contract_id'=>'4444']),$stock) === null, 'duplicate linked contract blocked');
ok(VoipLinkPrefill::suggest(array_merge($match,['product_ids'=>['8888']]),$stock) === null, 'duplicate product record blocked');
ok(VoipLinkPrefill::suggest(array_merge($match,['contract_id'=>'2 OR 1=1']),$stock) === null, 'invalid ID blocked');
ok($stock['1151961252']['contract_id'] === null, 'does not write stock');
echo "OK: prefill conservative, no writes, duplicate/ambiguous blocked.\n";
