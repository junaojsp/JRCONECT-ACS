<?php
declare(strict_types=1);

require_once __DIR__ . '/IXCConfig.php';

use App\GenieACS;
use App\CPEProfiles;

function jrSimsIxcList(string $table, string $qtype, string $query, int $rp = 20): array
{
    $cfg = getIxcConfig();
    $ch = curl_init($cfg['base_url'] . '/webservice/v1/' . rawurlencode($table));
    if ($ch === false) throw new RuntimeException('Falha ao iniciar consulta IXC.');

    $payload = json_encode([
        'qtype' => $qtype,
        'query' => $query,
        'oper' => '=',
        'page' => '1',
        'rp' => (string)$rp,
        'sortname' => $table . '.id',
        'sortorder' => 'desc',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($cfg['token']),
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException('Falha na consulta IXC: ' . $error);
    if ($http < 200 || $http >= 300) throw new RuntimeException('IXC retornou HTTP ' . $http . '.');

    $json = json_decode($body, true);
    if (!is_array($json)) return [];

    $rows = $json['registros'] ?? $json['records'] ?? (array_is_list($json) ? $json : []);
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function jrSimsResolveFiber(string $idLogin, string $idContrato): array
{
    $rows = [];
    if ($idLogin !== '') {
        $rows = jrSimsIxcList(
            'radpop_radio_cliente_fibra',
            'radpop_radio_cliente_fibra.id_login',
            $idLogin,
            10
        );
    }
    if (!$rows && $idContrato !== '') {
        $rows = jrSimsIxcList(
            'radpop_radio_cliente_fibra',
            'radpop_radio_cliente_fibra.id_contrato',
            $idContrato,
            10
        );
    }
    if (!$rows) throw new RuntimeException('Equipamento não encontrado no IXC para o contrato/login informado.');
    return $rows[0];
}

function jrSimsGenie(): GenieACS
{
    $db = getDBConnection();
    $stmt = $db->prepare('SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1');
    if (!$stmt) throw new RuntimeException('Falha ao ler configuração GenieACS.');
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new RuntimeException('GenieACS não configurado.');
    return new GenieACS($row['host'], $row['port'], $row['username'], $row['password']);
}

function jrSimsFindDeviceBySerial(GenieACS $genie, string $serial): array
{
    foreach (CPEProfiles::serialAliases($serial) as $alias) {
        foreach ([
            ['_deviceId._SerialNumber' => $alias],
            ['InternetGatewayDevice.DeviceInfo.SerialNumber._value' => $alias],
            ['Device.DeviceInfo.SerialNumber._value' => $alias],
        ] as $query) {
            $r = $genie->getDevices($query, 2);
            if (!empty($r['success']) && !empty($r['data'][0]) && is_array($r['data'][0])) {
                return $r['data'][0];
            }
        }
    }
    throw new RuntimeException('Equipamento localizado no IXC, mas não encontrado no GenieACS.');
}

function jrSimsDeviceContext(string $idLogin, string $idContrato): array
{
    $fiber = jrSimsResolveFiber($idLogin, $idContrato);
    $serial = strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', (string)($fiber['mac'] ?? '')));
    if ($serial === '') throw new RuntimeException('Serial/MAC da ONU não disponível no IXC.');

    $genie = jrSimsGenie();
    $device = jrSimsFindDeviceBySerial($genie, $serial);

    return [
        'genie' => $genie,
        'device' => $device,
        'device_id' => (string)($device['_id'] ?? ''),
        'serial' => $serial,
        'fiber' => $fiber,
    ];
}

function jrSimsGet(array $device, string $path): mixed
{
    $node = $device;
    foreach (explode('.', $path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) return null;
        $node = $node[$part];
    }
    return is_array($node) && array_key_exists('_value', $node) ? $node['_value'] : (is_scalar($node) ? $node : null);
}

function jrSimsBandIndexes(array $device, string $band): array
{
    $band = strtolower(trim($band));
    if ($band === '' || $band === '2.4' || $band === '2g' || $band === '2.4ghz') return [1];

    $has5 = jrSimsGet($device, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID') !== null
        || jrSimsGet($device, 'Device.WiFi.SSID.5.SSID') !== null;

    $index5 = $has5 ? 5 : 2;

    if ($band === '5' || $band === '5g' || $band === '5ghz') return [$index5];
    if ($band === 'both' || $band === 'ambas') return [1, $index5];

    throw new InvalidArgumentException('Banda inválida. Use 2.4, 5 ou both.');
}

function jrSimsStatus(array $device): array
{
    $lastInform = $device['_lastInform'] ?? null;
    $ts = is_string($lastInform) ? strtotime($lastInform) : false;
    $age = $ts !== false ? max(0, time() - $ts) : null;

    return [
        'online' => $age !== null && $age < 300,
        'last_inform' => $lastInform,
        'last_inform_age_seconds' => $age,
    ];
}
