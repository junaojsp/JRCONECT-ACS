<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/Security.php';

use App\RateLimiter;

if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$error = '';
$conn = getDBConnection();
$csrf = securityCsrfToken();

if (!securityColumnsReady($conn)) {
    http_response_code(503);
    $error = 'A recuperação do 2FA ainda não está disponível neste servidor.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $givenCsrf = (string)($_POST['csrf_token'] ?? '');

    if (!securityVerifyCsrf($givenCsrf)) {
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif ($username === '' || $password === '') {
        $error = 'Usuário e senha são obrigatórios.';
    } else {
        $identifier = strtolower($username) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        try {
            $limiter = new RateLimiter($conn);
            $limit = $limiter->check($identifier, 'two-factor-reset', 5, 600);
            if (!$limit['allowed']) {
                $retry = max(1, (int)($limit['retry_after'] ?? 60));
                $error = 'Muitas tentativas de recuperação. Tente novamente em ' . $retry . ' segundos.';
            }
        } catch (Throwable $rateError) {
            error_log('[SECURITY] Falha no rate limiter do reset 2FA: ' . $rateError->getMessage());
        }

        if ($error === '') {
            $stmt = $conn->prepare(
                'SELECT id, name, username, password, role, active
                 FROM users
                 WHERE username = ?
                 LIMIT 1'
            );
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (
                !$user ||
                (int)($user['active'] ?? 0) !== 1 ||
                !password_verify($password, (string)($user['password'] ?? ''))
            ) {
                $error = 'Usuário ou senha inválidos.';
                usleep(250000);
            } else {
                $uid = (int)$user['id'];

                $update = $conn->prepare(
                    'UPDATE users
                     SET two_factor_secret = NULL,
                         two_factor_enabled = 0,
                         two_factor_recovery = NULL,
                         two_factor_confirmed_at = NULL
                     WHERE id = ?'
                );
                $update->bind_param('i', $uid);
                $update->execute();
                $update->close();

                session_regenerate_id(true);
                unset(
                    $_SESSION['pending_2fa_secret'],
                    $_SESSION['pending_2fa_attempts'],
                    $_SESSION['new_2fa_recovery_codes']
                );

                $_SESSION['pending_2fa_user_id'] = $uid;
                $_SESSION['pending_2fa_started_at'] = time();
                $_SESSION['pending_2fa_setup'] = true;

                error_log(
                    '[SECURITY] Reset 2FA solicitado com senha válida para usuário ID ' .
                    $uid . ' IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                );

                redirect('/two-factor.php');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar 2FA - <?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=20260921">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .recover-card{max-width:460px;margin:8vh auto;background:#102b3d;border:1px solid #21485e;border-radius:14px;padding:28px;color:#edf6fa}
        .recover-card h1{font-size:21px;margin-bottom:8px}
        .recover-card p{color:#9bb6c5}
        .recover-back{display:block;text-align:center;margin-top:16px;color:#7ee4ff;text-decoration:none}
        .recover-back:hover{color:#a8eeff}
    </style>
</head>
<body>
<div class="container">
    <div class="recover-card">
        <h1><i class="bi bi-shield-lock"></i> Recuperar acesso ao 2FA</h1>
        <p>
            Confirme seu usuário e sua senha de acesso. O autenticador atual será
            invalidado e você cadastrará um novo 2FA.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="mb-3">
                <label for="username" class="form-label">Usuário</label>
                <input
                    type="text"
                    name="username"
                    id="username"
                    class="form-control"
                    autocomplete="username"
                    required
                    autofocus
                >
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Senha</label>
                <input
                    type="password"
                    name="password"
                    id="password"
                    class="form-control"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-arrow-repeat"></i> Resetar e cadastrar novo 2FA
            </button>
        </form>

        <a class="recover-back" href="/login.php">
            <i class="bi bi-arrow-left"></i> Voltar ao login
        </a>
    </div>
</div>
</body>
</html>
