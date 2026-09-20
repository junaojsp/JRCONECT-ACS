<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/AIConfig.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

try {
    $conn = getDBConnection();
    $ai = getActiveAIConfig($conn);

    if (!$ai) {
        echo json_encode([
            'success' => true,
            'configured' => false,
            'connected' => false,
            'provider' => null,
            'model' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'success' => true,
        'configured' => !empty($ai['api_key']) && !empty($ai['model']),
        'connected' => !empty($ai['is_connected']),
        'provider' => $ai['provider'] ?? null,
        'model' => $ai['model'] ?? null,
        'last_test' => $ai['last_test'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Não foi possível consultar o status da IA.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
