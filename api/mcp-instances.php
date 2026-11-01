<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

requireLogin();

$storeDir = __DIR__ . '/../storage/mcp';
$storeFile = $storeDir . '/instances.json';

function jrcMcpReadInstances(string $file): array
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

    $rows = $data['instances'] ?? [];
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function jrcMcpWriteInstances(string $dir, string $file, array $instances): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Diretório de armazenamento MCP indisponível.');
    }

    $payload = json_encode([
        'version' => 1,
        'updated_at' => gmdate('c'),
        'instances' => array_values($instances),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        throw new RuntimeException('Falha ao serializar instâncias MCP.');
    }

    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $payload . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Falha ao gravar cadastro MCP.');
    }

    @chmod($tmp, 0660);

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Falha ao finalizar cadastro MCP.');
    }
}

function jrcMcpRequireCsrf(): void
{
    $expected = $_SESSION['mcp_instance_csrf'] ?? '';
    $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        !is_string($expected) ||
        $expected === '' ||
        !is_string($received) ||
        $received === '' ||
        !hash_equals($expected, $received)
    ) {
        jsonResponse([
            'success' => false,
            'message' => 'Token de segurança inválido. Atualize a página.',
        ], 403);
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    jsonResponse([
        'success' => true,
        'instances' => jrcMcpReadInstances($storeFile),
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método não permitido.'], 405);
}

jrcMcpRequireCsrf();

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);

if (!is_array($body)) {
    jsonResponse(['success' => false, 'message' => 'JSON inválido.'], 400);
}

$name = trim((string)($body['name'] ?? ''));
$container = trim((string)($body['container'] ?? ''));
$port = trim((string)($body['port'] ?? ''));
$endpoint = trim((string)($body['endpoint'] ?? ''));
$description = trim((string)($body['description'] ?? ''));

if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    jsonResponse(['success' => false, 'message' => 'Informe um nome entre 2 e 100 caracteres.'], 422);
}

if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{1,79}$/', $container)) {
    jsonResponse(['success' => false, 'message' => 'Nome do container inválido.'], 422);
}

if (!preg_match('/^[0-9]{2,5}$/', $port)) {
    jsonResponse(['success' => false, 'message' => 'Porta local inválida.'], 422);
}

$portNumber = (int)$port;
if ($portNumber < 1024 || $portNumber > 65535) {
    jsonResponse(['success' => false, 'message' => 'Use uma porta entre 1024 e 65535.'], 422);
}

if ($endpoint === '' || mb_strlen($endpoint) > 500) {
    jsonResponse(['success' => false, 'message' => 'Informe o endpoint MCP.'], 422);
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

$instances = jrcMcpReadInstances($storeFile);

foreach ($instances as $instance) {
    if (strcasecmp((string)($instance['container'] ?? ''), $container) === 0) {
        jsonResponse(['success' => false, 'message' => 'Esse container já está cadastrado.'], 409);
    }

    if ((int)($instance['port'] ?? 0) === $portNumber) {
        jsonResponse(['success' => false, 'message' => 'Essa porta local já está cadastrada.'], 409);
    }

    if (strcasecmp((string)($instance['endpoint'] ?? ''), $endpoint) === 0) {
        jsonResponse(['success' => false, 'message' => 'Esse endpoint já está cadastrado.'], 409);
    }
}

$newInstance = [
    'id' => 'mcp-' . bin2hex(random_bytes(6)),
    'name' => $name,
    'container' => $container,
    'port' => $portNumber,
    'local_address' => '127.0.0.1:' . $portNumber,
    'endpoint' => $endpoint,
    'description' => $description,
    'status' => 'registered',
    'created_at' => gmdate('c'),
];

$instances[] = $newInstance;

try {
    jrcMcpWriteInstances($storeDir, $storeFile, $instances);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Falha ao salvar instância MCP.',
        'detail' => $e->getMessage(),
    ], 500);
}

jsonResponse([
    'success' => true,
    'instance' => $newInstance,
], 201);
