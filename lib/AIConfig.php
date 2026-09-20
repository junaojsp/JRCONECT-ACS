<?php

declare(strict_types=1);

function normalizeAIProvider(string $provider): string
{
    $provider = strtolower(trim($provider));
    if (!in_array($provider, ['openai', 'anthropic'], true)) {
        throw new InvalidArgumentException('Provedor de IA não suportado.');
    }
    return $provider;
}

function defaultAIModel(string $provider): string
{
    return normalizeAIProvider($provider) === 'anthropic'
        ? 'claude-sonnet-5'
        : 'gpt-5.6-terra';
}

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

    // Migração segura: passa a manter uma credencial independente por provedor.
    $indexResult = $conn->query("SHOW INDEX FROM ai_config WHERE Key_name = 'uq_ai_provider'");
    $hasIndex = $indexResult && $indexResult->num_rows > 0;

    if (!$hasIndex) {
        // Em instalações antigas havia apenas uma linha, então a criação do índice é direta.
        // Se houver duplicidade manual, preserva o registro mais recente de cada provedor.
        $duplicates = $conn->query("
            SELECT provider, COUNT(*) AS qty
            FROM ai_config
            GROUP BY provider
            HAVING COUNT(*) > 1
        ");

        if ($duplicates && $duplicates->num_rows > 0) {
            $conn->query("
                DELETE a1 FROM ai_config a1
                INNER JOIN ai_config a2
                    ON a1.provider = a2.provider
                   AND a1.id < a2.id
            ");
        }

        if (!$conn->query("ALTER TABLE ai_config ADD UNIQUE KEY uq_ai_provider (provider)")) {
            throw new RuntimeException('Não foi possível preparar múltiplos provedores de IA.');
        }
    }
}

function getAIConfig(mysqli $conn, ?string $provider = null): ?array
{
    ensureAIConfigTable($conn);

    if ($provider !== null) {
        $provider = normalizeAIProvider($provider);
        $stmt = $conn->prepare("SELECT * FROM ai_config WHERE provider = ? LIMIT 1");
        if (!$stmt) throw new RuntimeException('Não foi possível ler a configuração de IA.');
        $stmt->bind_param('s', $provider);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query("SELECT * FROM ai_config ORDER BY updated_at DESC, id DESC LIMIT 1");
    }

    if (!$result) {
        throw new RuntimeException('Não foi possível ler a configuração de IA.');
    }

    $row = $result->fetch_assoc();
    return $row ?: null;
}

function getAIProviderStatus(mysqli $conn): array
{
    ensureAIConfigTable($conn);

    $status = [];
    $result = $conn->query("
        SELECT provider, model, is_connected, last_test, updated_at
        FROM ai_config
        ORDER BY provider ASC
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $provider = (string)$row['provider'];
            $status[$provider] = [
                'configured' => true,
                'model' => (string)$row['model'],
                'is_connected' => !empty($row['is_connected']),
                'last_test' => $row['last_test'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }
    }

    foreach (['openai', 'anthropic'] as $provider) {
        if (!isset($status[$provider])) {
            $status[$provider] = [
                'configured' => false,
                'model' => defaultAIModel($provider),
                'is_connected' => false,
                'last_test' => null,
                'updated_at' => null,
            ];
        }
    }

    return $status;
}

function resolveAIKey(mysqli $conn, string $provider, ?string $submittedKey): string
{
    $provider = normalizeAIProvider($provider);
    $submittedKey = trim((string)$submittedKey);

    if ($submittedKey !== '') {
        return $submittedKey;
    }

    $existing = getAIConfig($conn, $provider);
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

    $provider = normalizeAIProvider($provider);
    $apiKey = trim($apiKey);
    $model = trim($model);

    if ($apiKey === '' || $model === '') {
        throw new InvalidArgumentException('API key e modelo são obrigatórios.');
    }

    $existing = getAIConfig($conn, $provider);
    $connectedInt = $connected ? 1 : 0;

    if ($existing) {
        $lastTestSql = $touchLastTest ? 'NOW()' : 'last_test';
        $sql = "UPDATE ai_config
                SET api_key = ?, model = ?, is_connected = ?, last_test = {$lastTestSql}, updated_at = NOW()
                WHERE provider = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Não foi possível preparar a gravação da IA.');
        $stmt->bind_param('ssis', $apiKey, $model, $connectedInt, $provider);
    } else {
        $lastTestSql = $touchLastTest ? 'NOW()' : 'NULL';
        $sql = "INSERT INTO ai_config (provider, api_key, model, is_connected, last_test)
                VALUES (?, ?, ?, ?, {$lastTestSql})";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Não foi possível preparar a gravação da IA.');
        $stmt->bind_param('sssi', $provider, $apiKey, $model, $connectedInt);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Não foi possível salvar a configuração de IA.');
    }
}


function getActiveAIConfig(mysqli $conn): ?array
{
    ensureAIConfigTable($conn);

    $result = $conn->query("
        SELECT *
        FROM ai_config
        WHERE is_connected = 1
        ORDER BY COALESCE(last_test, updated_at) DESC, updated_at DESC, id DESC
        LIMIT 1
    ");

    if ($result) {
        $row = $result->fetch_assoc();
        if ($row) return $row;
    }

    return getAIConfig($conn);
}
