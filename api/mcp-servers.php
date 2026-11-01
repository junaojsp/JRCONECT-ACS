<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

requireLogin();

$storeDir = __DIR__ . '/../storage';
$storeFile = $storeDir . '/mcp-servers.json';

function mcpServerReadAll(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    $rows = $data['servers'] ?? $data;
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function mcpServerWriteAll(string $dir, string $file, array $servers): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar o diretório de armazenamento MCP.');
    }

    $payload = json_encode([
        'version' => 1,
        'updated_at' => gmdate('c'),
        'servers' => array_values($servers),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        throw new RuntimeException('Falha ao serializar servidores MCP.');
    }

    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $payload . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar o cadastro MCP.');
    }

    @chmod($tmp, 0660);

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível finalizar o cadastro MCP.');
    }
}

function mcpServerRequireCsrf(): void
{
    $expected = $_SESSION['mcp_server_csrf'] ?? '';
    $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($expected) || $expected === '' || !is_string($received) || !hash_equals($expected, $received)) {
        jsonResponse(['success' => false, 'message' => 'Token de segurança inválido. Atualize a página.'], 403);
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    jsonResponse([
        'success' => true,
        'servers' => mcpServerReadAll($storeFile),
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método não permitido.'], 405);
}

mcpServerRequireCsrf();

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) {
    jsonResponse(['success' => false, 'message' => 'JSON inválido.'], 400);
}

$name = trim((string)($body['name'] ?? ''));
$endpoint = trim((string)($body['endpoint'] ?? ''));
$description = trim((string)($body['description'] ?? ''));

if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    jsonResponse(['success' => false, 'message' => 'Informe um nome entre 2 e 100 caracteres.'], 422);
}

if ($endpoint === '' || mb_strlen($endpoint) > 500) {
    jsonResponse(['success' => false, 'message' => 'Informe um endpoint MCP válido.'], 422);
}

$parts = parse_url($endpoint);
$scheme = strtolower((string)($parts['scheme'] ?? ''));
$host = (string)($parts['host'] ?? '');

if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
    jsonResponse(['success' => false, 'message' => 'Endpoint deve ser uma URL http/https sem credenciais embutidas.'], 422);
}

if (mb_strlen($description) > 240) {
    jsonResponse(['success' => false, 'message' => 'Descrição deve ter no máximo 240 caracteres.'], 422);
}

$servers = mcpServerReadAll($storeFile);

foreach ($servers as $server) {
    if (strcasecmp((string)($server['endpoint'] ?? ''), $endpoint) === 0) {
        jsonResponse(['success' => false, 'message' => 'Esse endpoint já está cadastrado.'], 409);
    }
}

$newServer = [
    'id' => 'manual-' . bin2hex(random_bytes(6)),
    'name' => $name,
    'endpoint' => $endpoint,
    'description' => $description,
    'type' => 'Servidor MCP cadastrado',
    'status' => 'registered',
    'created_at' => gmdate('c'),
];

$servers[] = $newServer;

try {
    mcpServerWriteAll($storeDir, $storeFile, $servers);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Falha ao salvar servidor MCP.',
        'detail' => $e->getMessage(),
    ], 500);
}

jsonResponse([
    'success' => true,
    'server' => $newServer,
], 201);
