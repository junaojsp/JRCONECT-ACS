<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/ConcentratorConfig.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método não permitido.'], 405);
}
requireLogin();

try {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Dados inválidos.');
    }

    $conn = getDBConnection();
    $data = normalizeConcentratorInput($input);
    $existing = $data['id'] > 0
        ? getConcentratorById($conn, $data['id'], true)
        : null;

    if ($data['password'] === '' && $existing) {
        $data['password'] = (string)($existing['password'] ?? '');
    }
    if ($data['password'] === '') {
        throw new InvalidArgumentException('Informe a senha para testar a conexão.');
    }

    if ($existing && !empty($existing['server_fingerprint'])) {
        $data['server_fingerprint'] = $existing['server_fingerprint'];
    }

    $result = testConcentratorConnection($data);

    if ($data['id'] > 0) {
        updateConcentratorTestStatus($conn, $data['id'], $result);
    }

    jsonResponse([
        'success' => !empty($result['success']),
        'message' => $result['message'] ?? 'Teste concluído.',
        'code' => $result['code'] ?? null,
        'vendor_confirmed' => $result['vendor_confirmed'] ?? null,
        'fingerprint' => $result['fingerprint'] ?? null,
        'identity' => $result['identity'] ?? null,
    ], !empty($result['success']) ? 200 : 422);
} catch (InvalidArgumentException $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Falha ao testar o concentrador.'], 500);
}
