<?php
require __DIR__.'/../lib/NocTelemetryCatalogue.php';
$d=['Device'=>['CPUUsage'=>['_value'=>0],'Temperature'=>['_value'=>42],'MemoryPassword'=>['_value'=>123456],'RxPower'=>['_value'=>'PRIVATE_TEXT'],'TXPower'=>['_value'=>INF],'SSID'=>['CPUUsage'=>['_value'=>999]]]];
$r=App\NocTelemetryCatalogue::collect($d,true);
$j=json_encode($r,JSON_THROW_ON_ERROR);
assert(!str_contains($j,'PRIVATE_TEXT')&&!str_contains($j,'123456')&&!str_contains($j,'999'));
assert(count($r['parameters'])===4);
assert($r['parameters'][0]['numeric_value']===0);
assert(!str_contains(json_encode(App\NocTelemetryCatalogue::collect($d)), 'numeric_value'));
for($i=0;$i<400;$i++)$d['Device']['CPU'.$i]=['_value'=>$i];
assert(App\NocTelemetryCatalogue::collect($d)['truncated']===true);
echo "PASS telemetry catalogue: numeric zero, secret exclusion, string exclusion, nonfinite values and bounded output\n";
