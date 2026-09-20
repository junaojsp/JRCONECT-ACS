<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/AIConfig.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

function jrDeviceAIOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jrDeviceAIRequest(string $url, array $headers, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('Não foi possível preparar a solicitação para a IA.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Não foi possível iniciar a conexão com a IA.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('Falha de conexão com a IA: ' . $curlError);
    }

    return [
        'http_code' => $httpCode,
        'json' => json_decode($responseBody, true),
        'raw' => $responseBody,
    ];
}

function jrOpenAIText(?array $response): string
{
    if (!$response) return '';

    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return trim($response['output_text']);
    }

    $parts = [];
    foreach (($response['output'] ?? []) as $item) {
        if (!is_array($item)) continue;
        foreach (($item['content'] ?? []) as $content) {
            if (!is_array($content)) continue;
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                $parts[] = (string)$content['text'];
            }
        }
    }

    return trim(implode("\n", $parts));
}

function jrAnthropicText(?array $response): string
{
    if (!$response) return '';

    $parts = [];
    foreach (($response['content'] ?? []) as $content) {
        if (!is_array($content)) continue;
        if (($content['type'] ?? '') === 'text' && isset($content['text'])) {
            $parts[] = (string)$content['text'];
        }
    }

    return trim(implode("\n", $parts));
}

function jrContextValue(mixed $value): mixed
{
    if (is_string($value)) {
        $value = trim($value);
        return strlen($value) > 500 ? substr($value, 0, 500) : $value;
    }

    if (is_array($value)) {
        $clean = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count++ >= 20) break;
            $clean[(string)$key] = jrContextValue($item);
        }
        return $clean;
    }

    if (is_scalar($value) || $value === null) return $value;
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jrDeviceAIOut(['success' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extensão PHP cURL não está disponível.');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jrDeviceAIOut(['success' => false, 'message' => 'Dados inválidos.'], 400);
    }

    $question = trim((string)($data['question'] ?? ''));
    if ($question === '') {
        jrDeviceAIOut(['success' => false, 'message' => 'Digite uma pergunta para a IA.'], 400);
    }
    if (strlen($question) > 1500) {
        jrDeviceAIOut(['success' => false, 'message' => 'Pergunta muito longa.'], 400);
    }

    $deviceId = trim((string)($data['device_id'] ?? ''));
    $context = jrContextValue(is_array($data['context'] ?? null) ? $data['context'] : []);

    $conn = getDBConnection();
    $ai = getActiveAIConfig($conn);

    if (!$ai || empty($ai['api_key'])) {
        jrDeviceAIOut([
            'success' => false,
            'message' => 'Configure um provedor de IA em Configurações > Configuração IA.'
        ], 400);
    }

    if (empty($ai['is_connected'])) {
        jrDeviceAIOut([
            'success' => false,
            'message' => 'O provedor de IA ainda não foi testado com sucesso em Configurações.'
        ], 400);
    }

    $provider = normalizeAIProvider((string)$ai['provider']);
    $model = trim((string)$ai['model']);
    $apiKey = trim((string)$ai['api_key']);

    $system = implode("\n", [
        'Você é o assistente técnico interno da JR CONECT TELECOM.',
        'Responda em português do Brasil, de forma objetiva e útil para um técnico de provedor FTTH.',
        'Use somente os dados técnicos fornecidos no contexto.',
        'Não invente medições, parâmetros, diagnósticos ou causas.',
        'Se algum dado estiver ausente, diga claramente que não está disponível.',
        'Ao analisar sinal óptico, diferencie RX e TX e explique o valor observado sem tratar uma faixa de referência como regra universal quando o fabricante/modelo puder variar.',
        'Não peça nem revele senhas, tokens ou credenciais administrativas.',
        'Quando houver indícios de problema, indique verificações seguras e práticas antes de qualquer alteração de configuração.'
    ]);

    $contextJson = json_encode([
        'device_id' => $deviceId,
        'equipment_context' => $context,
        'technician_question' => $question,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    if ($contextJson === false) {
        throw new RuntimeException('Não foi possível preparar o contexto do equipamento.');
    }

    if ($provider === 'anthropic') {
        $result = jrDeviceAIRequest(
            'https://api.anthropic.com/v1/messages',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            [
                'model' => $model,
                'max_tokens' => 900,
                'system' => $system,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $contextJson,
                    ],
                ],
            ]
        );

        $answer = jrAnthropicText(is_array($result['json']) ? $result['json'] : null);
        $error = is_array($result['json'])
            ? (string)($result['json']['error']['message'] ?? '')
            : '';
    } else {
        $result = jrDeviceAIRequest(
            'https://api.openai.com/v1/responses',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            [
                'model' => $model,
                'instructions' => $system,
                'input' => $contextJson,
                'max_output_tokens' => 900,
            ]
        );

        $answer = jrOpenAIText(is_array($result['json']) ? $result['json'] : null);
        $error = is_array($result['json'])
            ? (string)($result['json']['error']['message'] ?? '')
            : '';
    }

    if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
        jrDeviceAIOut([
            'success' => false,
            'message' => $error !== '' ? $error : 'O provedor de IA retornou HTTP ' . $result['http_code'] . '.',
            'provider' => $provider,
            'model' => $model,
        ], 502);
    }

    if ($answer === '') {
        jrDeviceAIOut([
            'success' => false,
            'message' => 'A IA respondeu, mas não retornou texto utilizável.',
            'provider' => $provider,
            'model' => $model,
        ], 502);
    }

    jrDeviceAIOut([
        'success' => true,
        'answer' => $answer,
        'provider' => $provider,
        'model' => $model,
    ]);

} catch (Throwable $e) {
    jrDeviceAIOut([
        'success' => false,
        'message' => $e->getMessage(),
    ], 500);
}
