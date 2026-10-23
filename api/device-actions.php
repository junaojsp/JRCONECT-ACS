<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/TrackedGenieACS.php';

use App\TrackedGenieACS;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success'=>false,'message'=>'Método não permitido.'], 405);
}

requireLogin();

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = getDBConnection();

    $uid = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $db->prepare('SELECT username, role, active FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int)$user['active'] !== 1) {
        jsonResponse(['success'=>false,'message'=>'Usuário sem permissão.'], 403);
    }

    $role = strtolower((string)$user['role']);
    if (!in_array($role, ['admin','noc','atendimento'], true)) {
        jsonResponse(['success'=>false,'message'=>'Perfil sem acesso às ações do equipamento.'], 403);
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        jsonResponse(['success'=>false,'message'=>'JSON inválido.'], 400);
    }

    $deviceId = trim((string)($payload['device_id'] ?? ''));
    $action = trim((string)($payload['action'] ?? ''));

    if ($deviceId === '' || strlen($deviceId) > 512 || preg_match('/[\x00-\x1f]/', $deviceId)) {
        jsonResponse(['success'=>false,'message'=>'Equipamento inválido.'], 400);
    }

    if (!in_array($action, ['refresh','reboot'], true)) {
        jsonResponse(['success'=>false,'message'=>'Ação não suportada.'], 400);
    }

    if ($action === 'reboot' && !in_array($role, ['admin','noc'], true)) {
        jsonResponse(['success'=>false,'message'=>'Reinício permitido apenas para Admin/NOC.'], 403);
    }

    if ($action === 'reboot' && ($payload['confirm'] ?? '') !== 'REINICIAR') {
        jsonResponse(['success'=>false,'message'=>'Confirmação de reinício inválida.'], 400);
    }

    $result = $db->query('SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1');
    $credentials = $result->fetch_assoc();
    if (!$credentials) {
        jsonResponse(['success'=>false,'message'=>'GenieACS não está conectado.'], 503);
    }

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $genieacs = new TrackedGenieACS(
        $credentials['host'],
        (int)$credentials['port'],
        $credentials['username'],
        $credentials['password']
    );

    if ($action === 'reboot') {
        $result = $genieacs->rebootDevice($deviceId);
        if (!empty($result['success'])) {
            jsonResponse([
                'success'=>true,
                'message'=>'Comando de reinício enviado ao equipamento.',
                'status'=>(int)($result['http_code'] ?? 0) === 202 ? 'queued' : 'submitted',
                'action_history_id'=>$result['action_history_id'] ?? null
            ]);
        }
        jsonResponse(['success'=>false,'message'=>'Não foi possível enviar o reinício ao equipamento.','details'=>$result], 502);
    }

    // Atualização segura: usa os mesmos objetos já empregados no diagnóstico.
    $paths = [
        'InternetGatewayDevice.WANDevice',
        'InternetGatewayDevice.LANDevice',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice',
        'Device.PPP',
        'Device.IP',
        'Device.Ethernet',
    ];

    $results = [];
    foreach ($paths as $path) {
        $results[] = $genieacs->addRefreshTask($deviceId, $path);
    }

    $accepted = array_values(array_filter($results, static function($item): bool {
        return is_array($item) && !empty($item['success']) && in_array((int)($item['http_code'] ?? 0), [200,202], true);
    }));

    if (!$accepted) {
        jsonResponse(['success'=>false,'message'=>'Nenhuma tarefa de atualização foi aceita pelo GenieACS.'], 502);
    }

    jsonResponse([
        'success'=>true,
        'message'=>'Atualização solicitada. Os novos valores aparecerão após a comunicação TR-069.',
        'accepted'=>count($accepted),
        'requested'=>count($results),
    ]);
} catch (Throwable $e) {
    jsonResponse(['success'=>false,'message'=>'Falha ao executar a ação do equipamento.'], 500);
}
