<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/Security.php';

use App\RateLimiter;

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$error = '';
$conn = getDBConnection();
$csrf = securityCsrfToken();

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $givenCsrf = (string)($_POST['csrf_token'] ?? '');

    if (!securityVerifyCsrf($givenCsrf)) {
        $error = 'Sessão de login expirada. Atualize a página e tente novamente.';
    } elseif ($username === '' || $password === '') {
        $error = 'Usuário e senha são obrigatórios.';
    } else {
        $identifier = strtolower($username) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        try {
            $limiter = new RateLimiter($conn);
            $limit = $limiter->check($identifier, 'web-login', 8, 300);
            if (!$limit['allowed']) {
                $retry = max(1, (int)($limit['retry_after'] ?? 60));
                $error = 'Muitas tentativas de login. Tente novamente em ' . $retry . ' segundos.';
            }
        } catch (Throwable $rateError) {
            error_log('[SECURITY] Falha no rate limiter do login: ' . $rateError->getMessage());
        }

        if ($error === '') {
            $securityReady = securityColumnsReady($conn);
            $extraFields = $securityReady
                ? ', two_factor_secret, two_factor_enabled, two_factor_recovery, two_factor_confirmed_at'
                : '';

            $stmt = $conn->prepare(
                'SELECT id, name, username, password, role, active' . $extraFields . '
                 FROM users
                 WHERE username = ?
                 LIMIT 1'
            );
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user || (int)($user['active'] ?? 0) !== 1 || !password_verify($password, (string)$user['password'])) {
                $error = 'Usuário ou senha inválidos.';
                usleep(250000);
            } else {
                $role = strtolower(trim((string)($user['role'] ?? '')));
                $twoFactorEnabled = $securityReady && (int)($user['two_factor_enabled'] ?? 0) === 1;
                $mustUseTwoFactor = $securityReady && (securityTwoFactorRequired($role) || $twoFactorEnabled);

                if ($mustUseTwoFactor) {
                    session_regenerate_id(true);
                    $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
                    $_SESSION['pending_2fa_started_at'] = time();
                    $_SESSION['pending_2fa_setup'] = !$twoFactorEnabled;
                    redirect('/two-factor.php');
                }

                securityLoginComplete($conn, $user);
                redirect('/dashboard.php');
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
    <title>Login - <?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=20260921">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .login-container { padding-bottom:80px; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="/assets/img/jrconect-logo.svg?v=20260921" alt="JR CONECT Telecom" style="width:180px;height:auto;margin-bottom:1.5rem;">
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                    <label for="username"><i class="bi bi-person"></i> Usuário</label>
                    <input type="text" name="username" id="username" class="form-control"
                           autocomplete="username" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password"><i class="bi bi-lock"></i> Senha</label>
                    <input type="password" name="password" id="password" class="form-control"
                           autocomplete="current-password" required>
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right"></i> Entrar
                </button>
            </form>
        </div>
    </div>

    <div class="footer" style="position:fixed;bottom:0;width:100%;background:transparent;color:white;">
        JR CONECT ACS
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
