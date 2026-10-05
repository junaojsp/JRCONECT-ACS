<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/OpticalHistory.php';
header('Content-Type: application/json; charset=utf-8');
requireLogin();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$serialInput = $_GET['serial'] ?? '';
$serial = is_string($serialInput) ? acsOpticalSerial($serialInput) : '';
$hours = (int)($_GET['hours'] ?? 24);
if ($serial === '' || strlen($serial) > 128 || !in_array($hours, [24, 168, 720], true)) {
    jsonResponse(['success' => false, 'message' => 'Serial ou período inválido.'], 400);
}
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = getDBConnection();
    $conn->query("SET time_zone = '+00:00'");
    $start = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
    $end = gmdate('Y-m-d H:i:s');
    $bucket = $hours === 24 ? 900 : ($hours === 168 ? 3600 : 21600);
    $stmt = $conn->prepare('SELECT FLOOR(UNIX_TIMESTAMP(sample_at) / ?) * ? AS bucket_time,
        AVG(rx_power) AS rx_avg, MIN(rx_power) AS rx_min, MAX(rx_power) AS rx_max,
        AVG(tx_power) AS tx_avg, COUNT(*) AS samples
        FROM onu_optical_history WHERE serial_number = ? AND sample_at BETWEEN ? AND ?
        GROUP BY bucket_time ORDER BY bucket_time ASC');
    $stmt->bind_param('iisss', $bucket, $bucket, $serial, $start, $end);
    $stmt->execute();
    $rows = $stmt->get_result();
    $points = [];
    while ($row = $rows->fetch_assoc()) {
        $points[] = ['time' => (int)$row['bucket_time'] * 1000, 'rx' => round((float)$row['rx_avg'], 3),
            'min' => (float)$row['rx_min'], 'max' => (float)$row['rx_max'],
            'tx' => $row['tx_avg'] === null ? null : round((float)$row['tx_avg'], 3), 'samples' => (int)$row['samples']];
    }
    $stmt = $conn->prepare('SELECT COUNT(*) AS samples, MIN(rx_power) AS rx_min, MAX(rx_power) AS rx_max,
        AVG(rx_power) AS rx_avg, SUM(measured_at IS NULL) AS unconfirmed_time,
        MAX(last_seen_at) AS last_seen_at FROM onu_optical_history WHERE serial_number = ? AND sample_at BETWEEN ? AND ?');
    $stmt->bind_param('sss', $serial, $start, $end);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    jsonResponse(['success' => true, 'source' => 'IXC', 'serial' => $serial, 'hours' => $hours,
        'bucket_seconds' => $bucket, 'points' => $points,
        'stats' => ['samples' => (int)$stats['samples'], 'min' => $stats['rx_min'] === null ? null : (float)$stats['rx_min'],
            'max' => $stats['rx_max'] === null ? null : (float)$stats['rx_max'],
            'avg' => $stats['rx_avg'] === null ? null : round((float)$stats['rx_avg'], 3),
            'unconfirmed_time' => (int)($stats['unconfirmed_time'] ?? 0),
            'last_seen_at' => $stats['last_seen_at'] ? str_replace(' ', 'T', $stats['last_seen_at']) . 'Z' : null]]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => (int)$e->getCode() === 1146
        ? 'Histórico ainda não ativado no servidor.' : 'Não foi possível consultar o histórico de potência.'], 503);
}
