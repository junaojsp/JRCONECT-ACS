<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/IXCConfig.php';
if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');

function ixcActionOut(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function ixcActionFail(string $message, int $status = 422): never {
    throw new RuntimeException($message, $status);
}
function ixcActionConfig(): array {
    $cfg = getIxcConfig();
    return [$cfg['base_url'], $cfg['token']];
}
function ixcActionAuthorized(): array {
    $id = $_SESSION['user_id'] ?? null;
    if (!is_scalar($id) || !ctype_digit((string)$id)) ixcActionFail('Sessão expirada.', 401);
    $db = getDBConnection();
    $stmt = $db->prepare('SELECT role, active FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) ixcActionFail('Não foi possível validar o perfil.', 503);
    $userId = (int)$id;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || (int)$user['active'] !== 1) ixcActionFail('Usuário sem permissão.', 403);
    $role = strtolower(trim((string)$user['role']));
    if (!in_array($role, ['admin', 'noc'], true)) {
        ixcActionFail('Somente NOC/Admin pode executar ações no IXC.', 403);
    }
    return ['id' => $userId, 'role' => $role];
}
function ixcActionPost(string $baseUrl, string $token, string $route, array $payload): array {
    $ch = curl_init($baseUrl . '/webservice/v1/' . rawurlencode($route));
    if ($ch === false) throw new RuntimeException('Não foi possível iniciar a consulta IXC.', 502);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($token),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    if ($http < 200 || $http >= 300) {
        $message = is_array($json) ? (string)($json['message'] ?? $json['erro'] ?? '') : '';
        throw new RuntimeException($message !== '' ? $message : ($error !== '' ? $error : 'IXC recusou a operação.'), 502);
    }
    return is_array($json) ? $json : ['raw' => is_string($body) ? mb_substr($body, 0, 500) : null];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ixcActionFail('Método não permitido.', 405);
    $csrf = $_SESSION['device_control_csrf'] ?? '';
    $given = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($csrf) || $csrf === '' || !is_string($given) || !hash_equals($csrf, $given)) {
        ixcActionFail('Token de segurança expirado. Atualize a página.', 403);
    }
    $body = json_decode((string)file_get_contents('php://input', false, null, 0, 8192), true);
    if (!is_array($body)) ixcActionFail('JSON inválido.', 400);
    $action = (string)($body['action'] ?? '');
    $loginId = (string)($body['login_id'] ?? '');
    if (!in_array($action, ['disconnect', 'clear_mac'], true) || !preg_match('/^[1-9]\d*$/', $loginId)) {
        ixcActionFail('Ação ou login IXC inválido.', 400);
    }
    $user = ixcActionAuthorized();
    [$baseUrl, $token] = ixcActionConfig();
    $route = $action === 'disconnect' ? 'desconectar_clientes' : 'radusuarios_25452';
    $payload = $action === 'disconnect'
        ? ['id' => (int)$loginId]
        : ['get_id' => (int)$loginId];
    $response = ixcActionPost($baseUrl, $token, $route, $payload);
    ixcActionOut([
        'success' => true,
        'message' => $action === 'disconnect'
            ? 'Solicitação de desconexão PPPoE enviada ao IXC.'
            : 'MAC do login removido no IXC.',
        'action' => $action,
        'login_id' => (int)$loginId,
        'executed_by_role' => $user['role'],
        'ixc_response' => $response,
    ]);
} catch (Throwable $e) {
    $status = $e->getCode();
    ixcActionOut(['success' => false, 'message' => $e->getMessage()], $status >= 400 && $status <= 599 ? $status : 502);
}
