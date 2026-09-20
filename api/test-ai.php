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
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extensão PHP cURL não está disponível.');
    }

    $provider = trim((string)($data['provider'] ?? 'openai'));
    $model = trim((string)($data['model'] ?? 'gpt-5.6-terra'));

    if ($provider !== 'openai') {
        jsonResponse(['success' => false, 'message' => 'Provedor não suportado nesta versão.'], 400);
    }
    if ($model === '') {
        jsonResponse(['success' => false, 'message' => 'Informe o modelo da IA.'], 400);
    }

    $conn = getDBConnection();
    $apiKey = resolveAIKey($conn, (string)($data['api_key'] ?? ''));

    if ($apiKey === '') {
        jsonResponse(['success' => false, 'message' => 'Informe a API key da OpenAI.'], 400);
    }

    $payload = json_encode([
        'model' => $model,
        'input' => 'Responda apenas com OK.',
        'max_output_tokens' => 32,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        throw new RuntimeException('Falha ao preparar o teste da IA.');
    }

    $ch = curl_init('https://api.openai.com/v1/responses');
    if ($ch === false) {
        throw new RuntimeException('Não foi possível iniciar a conexão com a OpenAI.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Falha de conexão com a OpenAI: ' . $curlError);
    }

    $response = json_decode($body, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = is_array($response)
            ? (string)($response['error']['message'] ?? ('OpenAI retornou HTTP ' . $httpCode . '.'))
            : ('OpenAI retornou HTTP ' . $httpCode . '.');

        jsonResponse([
            'success' => false,
            'message' => $message,
            'http_status' => $httpCode,
        ], 502);
    }

    saveAIConfig($conn, $provider, $apiKey, $model, true, true);

    jsonResponse([
        'success' => true,
        'message' => 'Conectado à OpenAI com sucesso / Modelo [' . $model . ']',
        'provider' => 'OpenAI',
        'model' => $model,
    ]);

} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => $e->getMessage(),
    ], 500);
}
