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
    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Dados inválidos.');
    }

    $conn = getDBConnection();
    $id = saveConcentratorConfig($conn, $data);
    $saved = getConcentratorById($conn, $id, false);

    jsonResponse([
        'success' => true,
        'message' => 'Concentrador salvo com sucesso.',
        'concentrator' => $saved,
    ]);
} catch (InvalidArgumentException $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Falha ao salvar o concentrador.'], 500);
}
