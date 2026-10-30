<?php
require_once __DIR__ . '/../config/config.php';

set_time_limit(30);

requireLogin();

use App\GenieACS;
use App\GenieACS_Fast;

header('Content-Type: application/json');

try {

    $db = getDBConnection();

    $stmt = $db->prepare(
        "SELECT host, port, username, password
         FROM genieacs_credentials
         LIMIT 1"
    );

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        jsonResponse([
            'success' => false,
            'message' => 'GenieACS not configured'
        ]);
        exit;
    }

    $config = $result->fetch_assoc();
    $stmt->close();

    $genieacs = new GenieACS(
        $config['host'],
        $config['port'],
        $config['username'],
        $config['password']
    );

    /*
     * PRIMEIRA CONSULTA
     * Busca somente ID e último Inform.
     */
    $result = $genieacs->getDevices(
        [],
        0,
        0,
        ['_id', '_lastInform']
    );

    if (!$result['success']) {
        jsonResponse([
            'success' => false,
            'message' => 'Failed to fetch device index from GenieACS'
        ]);
        exit;
    }

    $index = [];

    foreach ($result['data'] as $device) {

        $index[] = [
            'id' => $device['_id'] ?? null,
            'timestamp' => isset($device['_lastInform'])
                ? strtotime($device['_lastInform'])
                : 0
        ];
    }

    usort($index, function ($a, $b) {
        return $b['timestamp'] <=> $a['timestamp'];
    });

    /*
     * Somente os 10 mais recentes.
     */
    $index = array_slice($index, 0, 10);

    $recentDevices = [];

    /*
     * SEGUNDA CONSULTA
     * Busca completo somente os 10 selecionados.
     */
    foreach ($index as $item) {

        if (empty($item['id'])) {
            continue;
        }

        $full = $genieacs->getDevice(
            $item['id']
        );

        if (!$full['success']) {
            continue;
        }

        $parsed =
            GenieACS_Fast::parseDeviceDataFast(
                $full['data']
            );

        $parsed['last_inform_timestamp'] =
            $item['timestamp'];

        $recentDevices[] = $parsed;
    }

    jsonResponse([
        'success' => true,
        'devices' => $recentDevices
    ]);

} catch (Throwable $e) {

    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}