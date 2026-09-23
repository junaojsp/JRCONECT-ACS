#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/IXCConfig.php';

use App\GenieACS;
use App\CPEProfiles;

const IXC_SYNC_MISS_THRESHOLD = 3;
const IXC_SYNC_ORPHAN_THRESHOLD = 12;

function out(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function normalizeSerial(string $value): string
{
    return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', trim($value)));
}

function getParam(array $device, string $path): mixed
{
    $node = $device;
    foreach (explode('.', $path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) return null;
        $node = $node[$part];
    }
    return is_array($node) && array_key_exists('_value', $node) ? $node['_value'] : (is_scalar($node) ? $node : null);
}

function deviceSerial(array $device): string
{
    foreach ([
        getParam($device, '_deviceId._SerialNumber'),
        getParam($device, 'InternetGatewayDevice.DeviceInfo.SerialNumber'),
        getParam($device, 'Device.DeviceInfo.SerialNumber'),
    ] as $value) {
        if (is_string($value) && trim($value) !== '') return normalizeSerial($value);
    }
    return '';
}

function ixcList(string $baseUrl, string $token, string $table, string $qtype, string $query, int $rp = 20): array
{
    $ch = curl_init(rtrim($baseUrl, '/') . '/webservice/v1/' . rawurlencode($table));
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
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException('Falha IXC: ' . $error);
    if ($http < 200 || $http >= 300) throw new RuntimeException('IXC HTTP ' . $http . ' em ' . $table . '.');

    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException('Resposta inválida do IXC em ' . $table . '.');

    $rows = $json['registros'] ?? $json['records'] ?? (array_is_list($json) ? $json : []);
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function findFiberBySerial(string $baseUrl, string $token, string $serial): ?array
{
    foreach (CPEProfiles::serialAliases($serial) as $alias) {
        $rows = ixcList(
            $baseUrl,
            $token,
            'radpop_radio_cliente_fibra',
            'radpop_radio_cliente_fibra.mac',
            $alias,
            5
        );

        foreach ($rows as $row) {
            if (normalizeSerial((string)($row['mac'] ?? '')) === normalizeSerial($alias)) {
                return $row;
            }
        }

        if (count($rows) === 1) return $rows[0];
    }

    return null;
}

function loginStillExists(string $baseUrl, string $token, array $fiber): bool
{
    $idLogin = trim((string)($fiber['id_login'] ?? ''));
    if ($idLogin === '') return false;

    $rows = ixcList($baseUrl, $token, 'radusuarios', 'radusuarios.id', $idLogin, 1);
    return !empty($rows);
}

function stateFile(): string
{
    return dirname(__DIR__) . '/runtime/ixc-device-sync/state.json';
}

function logFile(): string
{
    return dirname(__DIR__) . '/logs/ixc-device-sync.log';
}

function loadState(): array
{
    $file = stateFile();
    if (!is_file($file)) return ['devices' => []];

    $raw = file_get_contents($file);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($json) ? $json : ['devices' => []];
}

function saveState(array $state): void
{
    $file = stateFile();
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0750, true);
    file_put_contents(
        $file,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    );
    @chmod($file, 0640);
}

function audit(string $event, array $data): void
{
    if (!is_dir(dirname(logFile()))) mkdir(dirname(logFile()), 0750, true);

    $line = json_encode([
        'at' => gmdate('c'),
        'event' => $event,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (is_string($line)) {
        file_put_contents(logFile(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

$apply = in_array('--apply', $argv, true);
out('IXC -> GenieACS sync iniciado (' . ($apply ? 'APPLY' : 'DRY-RUN') . ')');

try {
    $ixc = getIxcConfig();

    $db = getDBConnection();
    $res = $db->query("SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
    $cfg = $res ? $res->fetch_assoc() : null;
    if (!$cfg) throw new RuntimeException('GenieACS não configurado.');

    $genie = new GenieACS($cfg['host'], $cfg['port'], $cfg['username'], $cfg['password']);
    $devicesResult = $genie->getDevices();
    if (empty($devicesResult['success']) || !is_array($devicesResult['data'] ?? null)) {
        throw new RuntimeException('Falha ao listar dispositivos do GenieACS.');
    }

    $state = loadState();
    if (!isset($state['devices']) || !is_array($state['devices'])) $state['devices'] = [];

    $seenDeviceIds = [];

    foreach ($devicesResult['data'] as $device) {
        if (!is_array($device)) continue;

        $deviceId = (string)($device['_id'] ?? '');
        $serial = deviceSerial($device);
        if ($deviceId === '' || $serial === '') continue;

        $seenDeviceIds[$deviceId] = true;
        $entry = $state['devices'][$deviceId] ?? [
            'serial' => $serial,
            'ever_valid_in_ixc' => false,
            'consecutive_misses' => 0,
        ];

        $entry['serial'] = $serial;
        $entry['last_checked_at'] = gmdate('c');

        try {
            $fiber = findFiberBySerial($ixc['base_url'], $ixc['token'], $serial);
            $valid = $fiber !== null && loginStillExists($ixc['base_url'], $ixc['token'], $fiber);

            if ($valid) {
                $entry['ever_valid_in_ixc'] = true;
                $entry['consecutive_misses'] = 0;
                $entry['last_valid_at'] = gmdate('c');
                $entry['orphan_misses'] = 0;
                unset($entry['orphan_since']);
                $entry['id_login'] = $fiber['id_login'] ?? null;
                $entry['id_contrato'] = $fiber['id_contrato'] ?? null;
                unset($entry['pending_since']);

                out("OK {$serial} presente no IXC");
            } else {
                if (!empty($entry['ever_valid_in_ixc'])) {
                    $entry['consecutive_misses'] = (int)($entry['consecutive_misses'] ?? 0) + 1;
                    $entry['pending_since'] = $entry['pending_since'] ?? gmdate('c');

                    out("PENDENTE {$serial} ausente/desautorizada no IXC ({$entry['consecutive_misses']}/" . IXC_SYNC_MISS_THRESHOLD . ")");
                    audit('device_missing_in_ixc', [
                        'device_id' => $deviceId,
                        'serial' => $serial,
                        'misses' => $entry['consecutive_misses'],
                        'apply' => $apply,
                    ]);

                    if ($entry['consecutive_misses'] >= IXC_SYNC_MISS_THRESHOLD) {
                        if ($apply) {
                            $delete = $genie->deleteDevice($deviceId);

                            if (!empty($delete['success'])) {
                                out("REMOVIDA {$serial} do GenieACS");
                                audit('device_deleted_from_genieacs', [
                                    'device_id' => $deviceId,
                                    'serial' => $serial,
                                    'http_code' => $delete['http_code'] ?? null,
                                ]);
                                unset($state['devices'][$deviceId]);
                                continue;
                            }

                            out("ERRO ao remover {$serial} do GenieACS");
                            audit('device_delete_failed', [
                                'device_id' => $deviceId,
                                'serial' => $serial,
                                'http_code' => $delete['http_code'] ?? null,
                                'error' => $delete['error'] ?? null,
                            ]);
                        } else {
                            out("DRY-RUN: {$serial} seria removida agora");
                        }
                    }
                } else {
                    $entry['orphan_misses'] = (int)($entry['orphan_misses'] ?? 0) + 1;
                    $entry['orphan_since'] = $entry['orphan_since'] ?? gmdate('c');

                    out("ORFA {$serial} nao encontrada no IXC ({$entry['orphan_misses']}/" . IXC_SYNC_ORPHAN_THRESHOLD . ")");
                    audit('orphan_device_missing_in_ixc', [
                        'device_id' => $deviceId,
                        'serial' => $serial,
                        'misses' => $entry['orphan_misses'],
                        'apply' => $apply,
                    ]);

                    if ($entry['orphan_misses'] >= IXC_SYNC_ORPHAN_THRESHOLD) {
                        if ($apply) {
                            $delete = $genie->deleteDevice($deviceId);

                            if (!empty($delete['success'])) {
                                out("REMOVIDA ORFA {$serial} do GenieACS");
                                audit('orphan_device_deleted_from_genieacs', [
                                    'device_id' => $deviceId,
                                    'serial' => $serial,
                                    'http_code' => $delete['http_code'] ?? null,
                                ]);
                                unset($state['devices'][$deviceId]);
                                continue;
                            }

                            out("ERRO ao remover ORFA {$serial} do GenieACS");
                            audit('orphan_device_delete_failed', [
                                'device_id' => $deviceId,
                                'serial' => $serial,
                                'http_code' => $delete['http_code'] ?? null,
                                'error' => $delete['error'] ?? null,
                            ]);
                        } else {
                            out("DRY-RUN: ORFA {$serial} seria removida agora");
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            out("ERRO {$serial}: " . $e->getMessage());
            audit('device_check_error', [
                'device_id' => $deviceId,
                'serial' => $serial,
                'message' => $e->getMessage(),
            ]);
            // Important: IXC/API errors do not increase the miss counter.
        }

        $state['devices'][$deviceId] = $entry;
    }

    // Remove stale state entries for devices no longer present in GenieACS.
    foreach (array_keys($state['devices']) as $deviceId) {
        if (!isset($seenDeviceIds[$deviceId])) {
            unset($state['devices'][$deviceId]);
        }
    }

    $state['last_run_at'] = gmdate('c');
    $state['last_mode'] = $apply ? 'apply' : 'dry-run';
    saveState($state);

    out('Sincronização concluída.');
} catch (Throwable $e) {
    out('FALHA: ' . $e->getMessage());
    audit('sync_failed', ['message' => $e->getMessage(), 'apply' => $apply]);
    exit(1);
}
