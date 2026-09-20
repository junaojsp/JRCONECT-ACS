<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/AIConfig.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método não permitido.'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    jsonResponse(['success' => false, 'message' => 'Dados inválidos.'], 400);
}

try {
    $provider = trim((string)($data['provider'] ?? 'openai'));
    $model = trim((string)($data['model'] ?? 'gpt-5.6-terra'));

    if ($provider !== 'openai') {
        jsonResponse(['success' => false, 'message' => 'Provedor não suportado nesta versão.'], 400);
    }

    $conn = getDBConnection();
    $existing = getAIConfig($conn);
    $submittedKey = trim((string)($data['api_key'] ?? ''));
    $apiKey = $submittedKey !== '' ? $submittedKey : trim((string)($existing['api_key'] ?? ''));

    if ($apiKey === '') {
        jsonResponse(['success' => false, 'message' => 'Informe a API key da OpenAI.'], 400);
    }

    $sameCredentials = $existing
        && hash_equals((string)$existing['api_key'], $apiKey)
        && (string)$existing['provider'] === $provider
        && (string)$existing['model'] === $model;

    $connected = $sameCredentials && !empty($existing['is_connected']);

    saveAIConfig($conn, $provider, $apiKey, $model, $connected, false);

    jsonResponse([
        'success' => true,
        'message' => 'Configuração da IA salva com segurança no servidor.',
        'connected' => $connected,
        'model' => $model,
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => $e->getMessage(),
    ], 500);
}
