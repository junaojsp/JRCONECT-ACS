<?php
/**
 * JRC-ACS NOC v2 - agregacao de leitura (somente consulta).
 * Fonte: GenieACS TR-069; nao representa status SNMP das OLTs.
 * Nunca retorna senhas de Wi-Fi, PPPoE ou credenciais.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
requireLogin();
// Liberar sessao para nao bloquear outras telas durante leitura.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
set_time_limit(90);

function nocReply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function nocReadRx(array $device): ?float
{
    $value = $device['VirtualParameters']['RXPower']['_value']
        ?? $device['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']['RXPower']['_value']
        ?? $device['Device']['Optical']['Interface']['1']['RxPower']['_value']
        ?? null;

    if (!is_numeric($value)) {
        return null;
    }
    $rx = (float) $value;
    if ($rx > 100) {
        $rx = ($rx / 100) - 40;
    }
    // Leituras zeradas e valores fora de faixa nao sao potencia valida.
    if (!is_finite($rx) || $rx >= 0 || $rx < -50) {
        return null;
    }
    return round($rx, 2);
}

try {
    if (!isGenieACSConfigured()) {
        nocReply(503, ['success' => false, 'message' => 'GenieACS não configurado.']);
    }

    $db = getDBConnection();
    $result = $db->query(
        'SELECT host, port, username, password FROM genieacs_credentials '
        . 'WHERE is_connected = 1 ORDER BY id DESC LIMIT 1'
    );
    $credentials = $result ? $result->fetch_assoc() : null;
    if (!$credentials) {
        nocReply(503, ['success' => false, 'message' => 'Conexão GenieACS não configurada.']);
    }

    require_once __DIR__ . '/../lib/NocGenieACS.php';
    $genie = new \App\NocGenieACS(
        $credentials['host'],
        $credentials['port'],
        $credentials['username'],
        $credentials['password']
    );
    $now = time();
    $deadline = microtime(true) + 65;
    $summary = [
        'total' => 0,
        'online' => 0,
        'offline' => 0,
        'optical_good' => 0,
        'optical_attention' => 0,
        'optical_critical' => 0,
        'optical_unavailable' => 0,
    ];
    $issues = [];

    $skip = 0;
    $pageSize = 100;
    do {
        $remaining = (int) ($deadline - microtime(true));
        if ($remaining < 1) nocReply(504, ['success' => false, 'message' => 'Consulta excedeu o tempo limite.']);
        $response = $genie->getDevices($pageSize, $skip, min(15, $remaining));
        if (empty($response['success']) || !is_array($response['data'] ?? null)) {
            nocReply(502, ['success' => false, 'message' => 'Falha ao consultar lotes do GenieACS.']);
        }
        $pageCount = count($response['data']);
        foreach ($response['data'] as $device) {
        if (!is_array($device)) {
            continue;
        }
        $summary['total']++;
        $lastInform = $device['_lastInform'] ?? null;
        $timestamp = is_string($lastInform) ? strtotime($lastInform) : false;
        $isOnline = $timestamp !== false
            && $timestamp !== null
            && $timestamp <= $now
            && ($now - $timestamp) < 300;

        $summary[$isOnline ? 'online' : 'offline']++;
        $rx = nocReadRx($device);
        if ($rx === null) {
            $summary['optical_unavailable']++;
        } elseif ($rx < -28) {
            $summary['optical_critical']++;
        } elseif ($rx <= -25) {
            $summary['optical_attention']++;
        } else {
            $summary['optical_good']++;
        }

        // Ocorrencias atuais: nao representam alarmes persistidos nem falha massiva.
        $priority = null;
        $reason = '';
        if ($isOnline && $rx !== null && $rx < -28) {
            $priority = 0;
            $reason = 'RX óptico crítico';
        } elseif (!$isOnline) {
            $priority = 1;
            $reason = 'Sem comunicação TR-069';
        } elseif ($rx !== null && $rx <= -25) {
            $priority = 2;
            $reason = 'RX óptico em atenção';
        }

        if ($priority !== null) {
            $identity = $device['_deviceId'] ?? [];
            $issues[] = [
                'device_id' => (string) ($device['_id'] ?? ''),
                'serial' => (string) ($identity['_SerialNumber'] ?? 'N/D'),
                'model' => (string) ($identity['_ProductClass'] ?? 'N/D'),
                'manufacturer' => (string) ($identity['_Manufacturer'] ?? 'N/D'),
                'status' => $isOnline ? 'online' : 'offline',
                'rx' => $rx,
                'reason' => $reason,
                'priority' => $priority,
                'last_inform' => $timestamp ? gmdate(DATE_ATOM, $timestamp) : null,
            ];
            if (count($issues) > 32) {
                usort($issues, static fn($a, $b) =>
                    ($a['priority'] <=> $b['priority'])
                    ?: strcmp((string) ($b['last_inform'] ?? ''), (string) ($a['last_inform'] ?? ''))
                );
                $issues = array_slice($issues, 0, 12);
            }
        }
    }

        $skip += $pageCount;
        unset($response, $device);
    } while ($pageCount === $pageSize);

    usort($issues, static fn($a, $b) =>
        ($a['priority'] <=> $b['priority'])
        ?: strcmp((string) ($b['last_inform'] ?? ''), (string) ($a['last_inform'] ?? ''))
    );
    $summary['availability_percent'] = $summary['total'] > 0
        ? round(($summary['online'] / $summary['total']) * 100, 1)
        : null;

    nocReply(200, [
        'success' => true,
        'source' => 'TR-069 / GenieACS',
        'captured_at' => gmdate(DATE_ATOM, $now),
        'summary' => $summary,
        'issues' => array_slice($issues, 0, 8),
        'capabilities' => [
            'historical_availability' => false,
            'pon_capacity' => false,
            'mass_outage_detection' => false,
            'ixc_optical_primary' => false,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[JRC NOC] Falha ao carregar snapshot: ' . $e->getMessage());
    nocReply(500, ['success' => false, 'message' => 'Falha interna ao consultar o painel NOC.']);
}
