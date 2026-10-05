<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/ActionHistory.php';
require_once __DIR__ . '/../lib/ActionVerification.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireLogin();
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = getDBConnection();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $db->prepare('SELECT role, active FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $uid); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user || (int)$user['active'] !== 1 || !in_array(strtolower($user['role']), ['admin','noc','atendimento'], true)) {
        jsonResponse(['success' => false, 'message' => 'Perfil sem acesso ao histórico.'], 403);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $device = $_GET['device_id'] ?? null;
    if (!is_string($device) || $device === '' || strlen($device) > 512 || preg_match('/[\x00-\x1f]/', $device)) {
        jsonResponse(['success' => false, 'message' => 'Equipamento inválido.'], 400);
    }
    $key = hash('sha256', $device);
    $before = isset($_GET['before']) && is_string($_GET['before']) && ctype_digit($_GET['before']) ? (int)$_GET['before'] : PHP_INT_MAX;
    $stmt = $db->prepare('SELECT id, device_id, actor, action, fields_changed, status, task_id, created_at, updated_at
        FROM device_action_history WHERE device_key = ? AND id < ? ORDER BY id DESC LIMIT 51');
    $stmt->bind_param('si', $key, $before); $stmt->execute();
    $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $hasMore = count($entries) > 50; $entries = array_slice($entries, 0, 50);
    foreach ($entries as &$entry) {
        if (!$entry['task_id'] && in_array($entry['status'], ['submitted', 'queued'], true)
            && time() - strtotime($entry['created_at'] . ' UTC') > 600) {
            $time = gmdate('Y-m-d H:i:s'); $id = (int)$entry['id'];
            $update = $db->prepare('UPDATE device_action_history SET status = "unconfirmed", updated_at = ? WHERE id = ? AND task_id IS NULL AND status IN ("submitted", "queued")');
            $update->bind_param('si', $time, $id); $update->execute(); $update->close();
            $entry['status'] = 'unconfirmed'; $entry['updated_at'] = $time;
        }
    }
    unset($entry);
    $sync = true;
    if (array_filter($entries, fn($row) => in_array($row['status'], ['queued', 'submitted', 'unconfirmed'], true) && $row['task_id'])) {
        try {
            $result = $db->query('SELECT host,port,username,password FROM genieacs_credentials WHERE is_connected=1 ORDER BY id DESC LIMIT 1');
            $credentials = $result->fetch_assoc();
            if (!$credentials) throw new RuntimeException('NBI unavailable');
            $tasks = acsActionNbiRead($credentials, 'tasks', $device);
            $faults = acsActionNbiRead($credentials, 'faults', $device);
            foreach ($entries as &$entry) {
                $state = acsActionRemoteState($entry, $tasks, $faults);
                if ($state === $entry['status']) continue;
                $time = gmdate('Y-m-d H:i:s'); $id = (int)$entry['id'];
                $update = $db->prepare('UPDATE device_action_history SET status = ?, updated_at = ? WHERE id = ? AND status IN ("queued","submitted","unconfirmed")');
                $update->bind_param('ssi', $state, $time, $id); $update->execute(); $update->close();
                $entry['status'] = $state; $entry['updated_at'] = $time;
            }
            unset($entry);
        } catch (Throwable $e) { $sync = false; }
    }
    $verification = acsVerificationHistory($db, $entries, $device, $sync ? ($tasks ?? null) : null, $sync ? ($faults ?? null) : null);
    $output = array_map(static function ($entry) use ($verification) {
        return ['id' => (int)$entry['id'], 'actor' => $entry['actor'], 'action' => $entry['action'],
            'fields' => $entry['fields_changed'], 'status' => $entry['status'],
            'created_at' => str_replace(' ', 'T', $entry['created_at']) . 'Z',
            'updated_at' => str_replace(' ', 'T', $entry['updated_at']) . 'Z',
            'verification' => $verification[(int)$entry['id']] ?? null];
    }, $entries);
    jsonResponse(['success' => true, 'entries' => $output, 'sync_available' => $sync, 'has_more' => $hasMore,
        'next_before' => $hasMore ? (int)end($entries)['id'] : null]);
} catch (Throwable $error) {
    jsonResponse(['success' => false, 'message' => (int)$error->getCode() === 1146
        ? 'Histórico de ações ainda não ativado no servidor.' : 'Não foi possível consultar o histórico de ações.'], 503);
}
