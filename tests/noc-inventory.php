<?php
require_once __DIR__ . '/../lib/NocInventory.php';
$now = 1800000000;
$device = ['_id' => 'test', '_deviceId' => ['_SerialNumber' => 'HWTC12345678', '_ProductClass' => 'TEST'],
    '_lastInform' => gmdate(DATE_ATOM, $now - 60),
    'VirtualParameters' => ['RXPower' => ['_value' => -28], 'gettemp' => ['_value' => 0], 'TXPower' => ['_value' => 0]],
    'InternetGatewayDevice' => ['DeviceInfo' => ['UpTime' => ['_value' => 0]],
        'LANDevice' => ['1' => ['WLANConfiguration' => ['1' => ['SSID' => ['_value' => '<test>'],
            'Enable' => ['_value' => false], 'KeyPassphrase' => ['_value' => 'NEVER_RETURN']]]]]]];
$row = App\NocInventory::parse($device, null, $now, true);
assert($row['rx']['value'] === -28.0 && $row['rx_band'] === 'critical');
assert($row['status'] === 'online' && $row['uptime'] === 0);
assert($row['temperature']['value'] === 0.0 && $row['tx']['value'] === 0.0);
assert($row['wifi'][0]['enabled'] === false);
assert(strpos(json_encode($row), 'NEVER_RETURN') === false);
$ixc = ['devices' => ['4857544312345678' => ['rx' => -24.5, 'updated_at' => 'test-date']]];
$row = App\NocInventory::parse($device, $ixc, $now, false);
assert($row['rx']['source'] === 'IXC' && $row['rx']['updated_at'] === 'test-date');
assert($row['rx_band'] === 'good' && !isset($row['wifi']));
$device['_lastInform'] = gmdate(DATE_ATOM, $now + 3600);
$device['VirtualParameters']['gettemp']['_value'] = 'invalid';
$device['VirtualParameters']['TXPower']['_value'] = 2500;
$row = App\NocInventory::parse($device, null, $now, true);
assert($row['status'] === 'offline' && $row['temperature']['value'] === null && $row['tx']['value'] === null);
foreach (App\NocInventory::projection(true) as $path) {
    assert(!preg_match('/password|passphrase|username|connectionrequest/i', $path));
}
unset($device['_deviceId']);
$device['InternetGatewayDevice']['DeviceInfo']['SerialNumber'] = ['_value' => 'FALLBACK-SERIAL'];
$device['InternetGatewayDevice']['DeviceInfo']['ModelName'] = ['_value' => 'FALLBACK-MODEL'];
$device['InternetGatewayDevice']['DeviceInfo']['Manufacturer'] = ['_value' => 'FALLBACK-VENDOR'];
$row = App\NocInventory::parse($device, null, $now, false);
assert($row['serial'] === 'FALLBACK-SERIAL' && $row['model'] === 'FALLBACK-MODEL' && $row['manufacturer'] === 'FALLBACK-VENDOR');
echo "PASS inventory parsing: secrets excluded, IXC aliases/priority, boundaries, zero values, invalid readings and future Inform\n";
