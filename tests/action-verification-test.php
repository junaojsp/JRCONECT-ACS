<?php
require_once __DIR__ . '/../lib/ActionVerification.php';
function verifyCheck($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$path='Device.WiFi.SSID.1.SSID';
$expected=acsVerificationExpected([[$path,'desiredSSID','xsd:string'],['Device.WiFi.AccessPoint.1.Security.KeyPassphrase','secretPASS','xsd:string'],['Device.WiFi.AccessPoint.1.Security.PreSharedKey','secretKEY','xsd:string']]);
verifyCheck(count($expected['fields'])===1 && $expected['excluded']===2,'Exclude credentials');
verifyCheck(!str_contains(json_encode($expected),'secret') && !str_contains(json_encode($expected),'desiredSSID'),'No values persisted');
verifyCheck(!acsVerificationSafePath('Device.Password.SSID'),'Secret ancestors');
verifyCheck(!acsVerificationSafePath('Device.WiFi.SSID.1.SSID,Device.Secret'),'Projection injection');
verifyCheck(acsVerificationCanonical('0010','xsd:int')==='10','Integer equivalence');
verifyCheck(acsVerificationCanonical('1','xsd:boolean')==='true','Boolean equivalence');
verifyCheck(acsVerificationCanonical('invalid','xsd:boolean')===null,'Invalid boolean');
$start=(int)floor(microtime(true)*1000)-10000;
$fresh=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
$old=(new DateTimeImmutable('-1 hour',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
$device=['Device'=>['WiFi'=>['SSID'=>[1=>['SSID'=>['_value'=>'desiredSSID','_timestamp'=>$fresh]]]]]];
verifyCheck(acsVerificationCompare($expected['fields'],$device,$start)['status']==='confirmed','Fresh matching value');
$device['Device']['WiFi']['SSID'][1]['SSID']['_timestamp']=$old;
verifyCheck(acsVerificationCompare($expected['fields'],$device,$start)['status']==='pending','Cached match not confirmation');
$device['Device']['WiFi']['SSID'][1]['SSID']=['_value'=>'differentSSID','_timestamp'=>$fresh];
verifyCheck(acsVerificationCompare($expected['fields'],$device,$start)['status']==='different','Fresh divergence');
verifyCheck(acsVerificationCompare($expected['fields'],[],$start)['status']==='pending','Missing field');
$device['Device']['WiFi']['SSID'][1]['SSID']['_timestamp']='tomorrow';
verifyCheck(acsVerificationCompare($expected['fields'],$device,$start)['status']==='pending','Invalid timestamp');
$wan=acsVerificationExpected(['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Enable'=>true,'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password'=>'hidden']);
verifyCheck(count($wan['fields'])===1 && $wan['excluded']===1,'Associative WAN parameters');
echo "Configuration verification tests passed.\n";
