<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/ConcentratorConfig.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método não permitido.'], 405);
}
securityRequireRole(['admin']);

try {
    $data = json_decode((string)file_get_contents('php://input'), true);
    $id = is_array($data) && isset($data['id']) && is_numeric($data['id'])
        ? (int)$data['id']
        : 0;

    if ($id <= 0) {
        throw new InvalidArgumentException('Concentrador inválido.');
    }

    $conn = getDBConnection();
    deleteConcentratorConfig($conn, $id);

    jsonResponse([
        'success' => true,
        'message' => 'Concentrador excluído.',
    ]);
} catch (InvalidArgumentException $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Falha ao excluir o concentrador.'], 500);
}
