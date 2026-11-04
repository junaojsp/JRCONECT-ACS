<?php
declare(strict_types=1);

// Painel isolado de conexao REST. Nao modifica o formulario MCP existente.
require_once __DIR__ . '/config/config.php';
requireLogin();
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Acesso restrito ao administrador.');
}
if (empty($_SESSION['rest_csrf'])) {
    $_SESSION['rest_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['rest_csrf'];
$pageTitle = 'Integracoes API REST';
$currentPage = 'rest-integrations';
$store = '/etc/jrconect-acs/rest-integrations.json';
$target = 'https://pabx.jrconect.com/api/mcp/index.php';
$message = '';
$error = '';
$record = ['name' => 'JR Conect PABX', 'type' => 'rest_bearer', 'url' => $target, 'status' => 'nao_configurado'];
if (is_file($store) && is_readable($store)) {
    $read = json_decode((string)file_get_contents($store), true);
    if (is_array($read) && ($read['url'] ?? '') === $target) {
        $record = array_merge($record, array_intersect_key($read, $record));
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('CSRF invalido');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $error = 'Nome deve conter entre 1 e 100 caracteres.';
        } elseif (!is_dir(dirname($store)) || !is_writable(dirname($store))) {
            $error = 'Configure /etc/jrconect-acs como diretorio privado gravavel pelo PHP antes de salvar.';
        } else {
            $record = ['name' => $name, 'type' => 'rest_bearer', 'url' => $target, 'status' => 'cadastrado'];
            $tmp = tempnam(dirname($store), '.rest-');
            if ($tmp === false) {
                $error = 'Nao foi possivel criar arquivo temporario.';
            } else {
                $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !chmod($tmp, 0600) || !rename($tmp, $store)) {
                    $error = 'Falha ao gravar configuracao.';
                    @unlink($tmp);
                } else {
                    $message = 'Cadastro salvo. A configuracao do token e o vinculo ao MCP ainda sao necessarios.';
                }
            }
        }
    } elseif ($action === 'test') {
        // Token nunca recebido nem armazenado pelo formulario.
        $secretPath = '/etc/jrconect-acs/pabx-read.token';
        if (!is_readable($secretPath)) {
            $error = 'Token de leitura ausente no host ACS: /etc/jrconect-acs/pabx-read.token';
        } elseif (!function_exists('curl_init')) {
            $error = 'Extensao PHP cURL indisponivel.';
        } else {
            $token = trim((string)file_get_contents($secretPath));
            if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
                $error = 'Token armazenado em formato invalido.';
            } else {
                $ch = curl_init($target . '?action=status');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_MAXREDIRS => 0,
                    CURLOPT_USERAGENT => 'JRC-ACS-REST-Connector/1.0',
                ]);
                $body = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                $decoded = is_string($body) ? json_decode($body, true) : null;
                if ($code === 200 && is_array($decoded) && ($decoded['ok'] ?? false) === true && ($decoded['action'] ?? '') === 'status') {
                    $message = 'API do PABX respondeu com sucesso. Ainda nao representa registro de ferramenta no MCP.';
                } else {
                    $error = 'Nao foi possivel validar a API (HTTP ' . $code . '). Confira o token, TLS e acesso via ACS.';
                }
            }
        }
    }
}
$h = static function ($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
include __DIR__ . '/views/layouts/header.php';
?>
<div class="container-fluid">
 <div class="d-flex align-items-center justify-content-between mb-3"><h2>Conectar API REST externa</h2><span class="badge bg-secondary">Cadastro isolado</span></div>
 <p class="text-muted">Integração planejada para o MCP principal. Este formulário não cria uma nova instância MCP nem altera ferramentas existentes.</p>
 <?php if ($message): ?><div class="alert alert-success"><?= $h($message) ?></div><?php endif; ?>
 <?php if ($error): ?><div class="alert alert-danger"><?= $h($error) ?></div><?php endif; ?>
 <div class="card shadow-sm"><div class="card-body">
 <form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
  <div class="mb-3"><label class="form-label">Tipo de conexão</label><input class="form-control" value="API REST HTTPS — Bearer Token" disabled></div>
  <div class="mb-3"><label class="form-label">Nome da integração</label><input class="form-control" name="name" required maxlength="100" value="<?= $h($record['name']) ?>"></div>
  <div class="mb-3"><label class="form-label">Endpoint da API</label><input class="form-control" value="<?= $h($target) ?>" disabled></div>
  <div class="mb-3"><label class="form-label">Autenticação</label><input class="form-control" value="Bearer — credencial protegida no servidor ACS" disabled></div>
  <div class="mb-3"><label class="form-label">Permissão inicial</label><input class="form-control" value="Somente leitura" disabled></div>
  <div class="d-flex gap-2">
    <button type="submit" name="action" value="save" class="btn btn-primary">Salvar conexão</button>
    <button type="submit" name="action" value="test" class="btn btn-outline-secondary">Testar API</button>
  </div>
 </form>
 </div></div>
 <p class="small text-muted mt-3">A integração com o catálogo de ferramentas do MCP depende da publicação do conector REST no backend do servidor MCP. Não armazene token na interface.</p>
</div>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
