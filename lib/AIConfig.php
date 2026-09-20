<?php

declare(strict_types=1);

function ensureAIConfigTable(mysqli $conn): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS ai_config (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider VARCHAR(32) NOT NULL DEFAULT 'openai',
            api_key TEXT NOT NULL,
            model VARCHAR(120) NOT NULL DEFAULT 'gpt-5.6-terra',
            is_connected TINYINT(1) NOT NULL DEFAULT 0,
            last_test DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Não foi possível preparar a configuração de IA.');
    }
}

function getAIConfig(mysqli $conn): ?array
{
    ensureAIConfigTable($conn);

    $result = $conn->query("SELECT * FROM ai_config ORDER BY id ASC LIMIT 1");
    if (!$result) {
        throw new RuntimeException('Não foi possível ler a configuração de IA.');
    }

    $row = $result->fetch_assoc();
    return $row ?: null;
}

function resolveAIKey(mysqli $conn, ?string $submittedKey): string
{
    $submittedKey = trim((string)$submittedKey);
    if ($submittedKey !== '') {
        return $submittedKey;
    }

    $existing = getAIConfig($conn);
    return trim((string)($existing['api_key'] ?? ''));
}

function saveAIConfig(
    mysqli $conn,
    string $provider,
    string $apiKey,
    string $model,
    bool $connected = false,
    bool $touchLastTest = false
): void {
    ensureAIConfigTable($conn);

    $provider = trim($provider);
    $apiKey = trim($apiKey);
    $model = trim($model);

    if ($provider === '' || $apiKey === '' || $model === '') {
        throw new InvalidArgumentException('Provedor, API key e modelo são obrigatórios.');
    }

    $existing = getAIConfig($conn);

    if ($existing) {
        $lastTestSql = $touchLastTest ? 'NOW()' : 'last_test';
        $sql = "UPDATE ai_config
                SET provider = ?, api_key = ?, model = ?, is_connected = ?, last_test = {$lastTestSql}, updated_at = NOW()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Não foi possível preparar a gravação da IA.');

        $connectedInt = $connected ? 1 : 0;
        $id = (int)$existing['id'];
        $stmt->bind_param('sssii', $provider, $apiKey, $model, $connectedInt, $id);
    } else {
        $lastTestSql = $touchLastTest ? 'NOW()' : 'NULL';
        $sql = "INSERT INTO ai_config (provider, api_key, model, is_connected, last_test)
                VALUES (?, ?, ?, ?, {$lastTestSql})";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Não foi possível preparar a gravação da IA.');

        $connectedInt = $connected ? 1 : 0;
        $stmt->bind_param('sssi', $provider, $apiKey, $model, $connectedInt);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Não foi possível salvar a configuração de IA.');
    }
}
