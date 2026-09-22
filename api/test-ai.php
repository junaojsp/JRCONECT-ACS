<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/AIConfig.php';

header('Content-Type: application/json; charset=utf-8');
securityRequireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método não permitido.'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    jsonResponse(['success' => false, 'message' => 'Dados inválidos.'], 400);
}

function jrAITestRequest(string $url, array $headers, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('Falha ao preparar o teste da IA.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Não foi possível iniciar a conexão com o provedor de IA.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('Falha de conexão com o provedor de IA: ' . $curlError);
    }

    return [
        'http_code' => $httpCode,
        'json' => json_decode($responseBody, true),
        'raw' => $responseBody,
    ];
}

try {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extensão PHP cURL não está disponível.');
    }

    $provider = normalizeAIProvider((string)($data['provider'] ?? 'openai'));
    $model = trim((string)($data['model'] ?? defaultAIModel($provider)));

    if ($model === '') {
        jsonResponse(['success' => false, 'message' => 'Informe o modelo da IA.'], 400);
    }

    $conn = getDBConnection();
    $apiKey = resolveAIKey($conn, $provider, (string)($data['api_key'] ?? ''));

    if ($apiKey === '') {
        $name = $provider === 'anthropic' ? 'Anthropic' : 'OpenAI';
        jsonResponse(['success' => false, 'message' => 'Informe a API key da ' . $name . '.'], 400);
    }

    if ($provider === 'anthropic') {
        $result = jrAITestRequest(
            'https://api.anthropic.com/v1/messages',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            [
                'model' => $model,
                'max_tokens' => 128,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => 'Responda apenas com OK.',
                    ],
                ],
            ]
        );

        $providerLabel = 'Claude / Anthropic';
        $errorMessage = is_array($result['json'])
            ? (string)($result['json']['error']['message'] ?? '')
            : '';
    } else {
        $result = jrAITestRequest(
            'https://api.openai.com/v1/responses',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            [
                'model' => $model,
                'input' => 'Responda apenas com OK.',
                'max_output_tokens' => 32,
            ]
        );

        $providerLabel = 'OpenAI';
        $errorMessage = is_array($result['json'])
            ? (string)($result['json']['error']['message'] ?? '')
            : '';
    }

    if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
        jsonResponse([
            'success' => false,
            'message' => $errorMessage !== ''
                ? $errorMessage
                : ($providerLabel . ' retornou HTTP ' . $result['http_code'] . '.'),
            'http_status' => $result['http_code'],
            'provider' => $provider,
        ], 502);
    }

    saveAIConfig($conn, $provider, $apiKey, $model, true, true);

    jsonResponse([
        'success' => true,
        'message' => 'Conectado ao ' . $providerLabel . ' com sucesso / Modelo [' . $model . ']',
        'provider' => $provider,
        'model' => $model,
    ]);

} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => $e->getMessage(),
    ], 500);
}
