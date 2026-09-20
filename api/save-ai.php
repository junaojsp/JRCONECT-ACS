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
    $provider = normalizeAIProvider((string)($data['provider'] ?? 'openai'));
    $model = trim((string)($data['model'] ?? defaultAIModel($provider)));

    $conn = getDBConnection();
    $existing = getAIConfig($conn, $provider);
    $submittedKey = trim((string)($data['api_key'] ?? ''));
    $apiKey = $submittedKey !== '' ? $submittedKey : trim((string)($existing['api_key'] ?? ''));

    if ($apiKey === '') {
        $name = $provider === 'anthropic' ? 'Anthropic' : 'OpenAI';
        jsonResponse(['success' => false, 'message' => 'Informe a API key da ' . $name . '.'], 400);
    }

    if ($model === '') {
        jsonResponse(['success' => false, 'message' => 'Informe o modelo da IA.'], 400);
    }

    $sameCredentials = $existing
        && hash_equals((string)$existing['api_key'], $apiKey)
        && (string)$existing['model'] === $model;

    $connected = $sameCredentials && !empty($existing['is_connected']);

    saveAIConfig($conn, $provider, $apiKey, $model, $connected, false);

    jsonResponse([
        'success' => true,
        'message' => ($provider === 'anthropic' ? 'Claude' : 'OpenAI') . ' salvo com segurança no servidor.',
        'provider' => $provider,
        'connected' => $connected,
        'model' => $model,
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => $e->getMessage(),
    ], 500);
}
