<?php
require_once __DIR__ . '/config/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$error = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Informe o usuário e a senha.';
    } else {
        $conn = getDBConnection();
       $stmt = $conn->prepare("
    SELECT id, name, username, password, role, active
    FROM users
    WHERE username = ?
    LIMIT 1
");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

      if ($user = $result->fetch_assoc()) {

    if ((int)$user['active'] !== 1) {
        $error = 'Usuário desativado. Entre em contato com o administrador.';
    }
    elseif (password_verify($password, $user['password'])) {

        $_SESSION['user_id']  = (int)$user['id'];
        $_SESSION['name']     = $user['name'] ?: $user['username'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];

        $update = $conn->prepare("
            UPDATE users
            SET last_login = NOW()
            WHERE id = ?
        ");

        $update->bind_param("i", $user['id']);
        $update->execute();
        $update->close();

        redirect('/dashboard.php');

    } else {
        $error = 'Usuário ou senha inválidos.';
    }

} else {
    $error = 'Usuário ou senha inválidos.';
}
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JRC-ACS • Acesso</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg?v=20260921">
<link rel="stylesheet" href="/assets/css/login-acs.css?v=20261008"></head>
<body><main class="login" id="login">
<section class="visual" aria-label="JRC-ACS, plataforma de gestão de equipamentos">
<img class="art" src="/assets/img/acs-login-hero.webp" alt="Roteador com globo holográfico ciano e conexões digitais">
<div class="specks" aria-hidden="true"></div><div class="scan" aria-hidden="true"></div><div class="signal" aria-hidden="true"></div><div class="signal second" aria-hidden="true"></div><div class="shade"></div>
<div class="brand"><img src="/assets/img/jrconect-logo.svg?v=20260921" alt="JR CONECT Telecom"><div>JR CONECT<span>.</span><small>TELECOM</small></div></div>
<div class="hero"><div class="eyebrow">GESTÃO CENTRALIZADA DE EQUIPAMENTOS</div><h1>JRC-ACS</h1><h2>Sua rede conectada.<br>Seu controle centralizado.</h2><p>Provisionamento, diagnóstico e gestão de dispositivos em um único ambiente.</p></div>
<button class="pause" type="button" aria-pressed="false">Pausar animação</button>
</section>
<section class="form-panel"><div class="form-wrap"><div class="access">ACESSO RESTRITO</div><h2>Bem-vindo ao<br>JRC-ACS</h2><p class="intro">Entre com suas credenciais para acessar<br>a plataforma de gestão de equipamentos.</p>
<?php if ($error): ?>
<div class="login-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<form id="login-form" method="POST" action=""><label class="field" for="username">Usuário</label><input class="text" id="username" name="username" autocomplete="username" autofocus value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Seu usuário" required>
<label class="field" for="password">Senha</label><div class="password"><input class="text" id="password" name="password" type="password" autocomplete="current-password" placeholder="Digite sua senha" required><button class="show" type="button" aria-controls="password" aria-pressed="false">Mostrar</button></div>
<button class="submit" type="submit">Acessar plataforma <span aria-hidden="true">→</span></button></form>
<div class="foot">JR CONECT Telecom · Uso interno<br>Gestão centralizada de equipamentos</div></div></section></main>
<script src="/assets/js/login-acs.js?v=20261008" defer></script></body></html>
