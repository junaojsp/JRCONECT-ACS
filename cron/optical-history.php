<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/IXCConfig.php';
require_once __DIR__ . '/../lib/IXCRead.php';
require_once __DIR__ . '/../lib/OpticalHistory.php';

use App\GenieACS;

$lock = null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = getDBConnection();
    $conn->query("SET time_zone = '+00:00'");
    if (in_array('--install', $argv, true)) {
        $sql = file_get_contents(__DIR__ . '/../migrations/20261005_optical_history.sql');
        if ($sql === false) throw new RuntimeException('Migração não encontrada.');
        $conn->query($sql);
        echo "Tabela de histórico instalada.\n";
        exit;
    }
    $lockName = sys_get_temp_dir() . '/jrconect-optical-' . hash('sha256', __DIR__) . '.lock';
    $lock = fopen($lockName, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        echo "Uma coleta já está em execução.\n";
        exit;
    }
    $cfg = getIxcConfig();
    $res = $conn->query('SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1');
    $creds = $res->fetch_assoc();
    if (!$creds) throw new RuntimeException('GenieACS não configurado.');
    $genie = new GenieACS($creds['host'], $creds['port'], $creds['username'], $creds['password']);
    $devices = $genie->getDevices();
    if (empty($devices['success']) || !is_array($devices['data'] ?? null)) throw new RuntimeException('Falha ao listar ONUs no GenieACS.');
    $fibers = acsIxcReadTable($cfg, 'radpop_radio_cliente_fibra');
    $result = acsOpticalMatchSamples($devices['data'], $fibers, time());
    $stmt = $conn->prepare('INSERT INTO onu_optical_history
        (serial_number, source, sample_key, sample_at, measured_at, collected_at, last_seen_at, rx_power, tx_power)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)');
    $inserted = 0;
    $conn->begin_transaction();
    try {
        foreach ($result['samples'] as $sample) {
            $stmt->bind_param('sssssssdd', $sample['serial'], $sample['source'], $sample['sample_key'],
                $sample['sample_at'], $sample['measured_at'], $sample['collected_at'], $sample['collected_at'],
                $sample['rx_power'], $sample['tx_power']);
            $stmt->execute();
            if ($stmt->affected_rows === 1) $inserted++;
        }
        $conn->query('DELETE FROM onu_optical_history WHERE sample_at < UTC_TIMESTAMP() - INTERVAL 90 DAY');
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
    echo json_encode(['at' => gmdate('c'), 'inserted' => $inserted, 'valid_readings' => count($result['samples']),
        'stats' => $result['stats']], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException && !($e instanceof mysqli_sql_exception)
        ? $e->getMessage() : 'Falha no banco do histórico. Confira a instalação e as permissões.';
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
