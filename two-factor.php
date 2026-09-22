<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/Security.php';

$error = '';
$recoveryCodes = $_SESSION['new_2fa_recovery_codes'] ?? null;

if (isLoggedIn() && is_array($recoveryCodes)) {
    unset($_SESSION['new_2fa_recovery_codes']);
} elseif (isLoggedIn()) {
    redirect('/dashboard.php');
}

if (!is_array($recoveryCodes)) {
    $pendingId = $_SESSION['pending_2fa_user_id'] ?? null;
    $pendingAt = (int)($_SESSION['pending_2fa_started_at'] ?? 0);

    if (!is_scalar($pendingId) || !ctype_digit((string)$pendingId) || $pendingAt < time() - 600) {
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started_at'], $_SESSION['pending_2fa_setup']);
        redirect('/login.php');
    }

    $conn = getDBConnection();
    if (!securityColumnsReady($conn)) {
        redirect('/login.php');
    }

    $uid = (int)$pendingId;
    $stmt = $conn->prepare(
        'SELECT id, name, username, password, role, active,
                two_factor_secret, two_factor_enabled, two_factor_recovery, two_factor_confirmed_at
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int)$user['active'] !== 1) {
        session_unset();
        session_destroy();
        redirect('/login.php');
    }

    $setup = (int)$user['two_factor_enabled'] !== 1;
    $csrf = securityCsrfToken();

    if ($setup) {
        if (empty($_SESSION['pending_2fa_secret']) || !is_string($_SESSION['pending_2fa_secret'])) {
            $_SESSION['pending_2fa_secret'] = securityGenerateTotpSecret();
        }
        $secret = $_SESSION['pending_2fa_secret'];
    } else {
        try {
            $secret = securityDecryptSecret((string)$user['two_factor_secret']);
        } catch (Throwable $e) {
            error_log('[SECURITY] Falha ao abrir segredo 2FA do usuário ' . $uid . ': ' . $e->getMessage());
            $error = 'Não foi possível validar o segundo fator. Contate o administrador.';
            $secret = '';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
        if (!securityVerifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
            $error = 'Sessão expirada. Atualize a página.';
        } else {
            $code = trim((string)($_POST['code'] ?? ''));
            $valid = $secret !== '' && securityVerifyTotp($secret, $code);

            if (!$valid && !$setup) {
                $valid = securityConsumeRecoveryCode(
                    $conn,
                    (int)$user['id'],
                    $code,
                    $user['two_factor_recovery'] ?? null
                );
            }

            if (!$valid) {
                $_SESSION['pending_2fa_attempts'] = (int)($_SESSION['pending_2fa_attempts'] ?? 0) + 1;
                if ($_SESSION['pending_2fa_attempts'] >= 10) {
                    unset(
                        $_SESSION['pending_2fa_user_id'],
                        $_SESSION['pending_2fa_started_at'],
                        $_SESSION['pending_2fa_setup'],
                        $_SESSION['pending_2fa_secret'],
                        $_SESSION['pending_2fa_attempts']
                    );
                    redirect('/login.php');
                }
                $error = $setup
                    ? 'Código inválido. Confira o horário do celular e tente novamente.'
                    : 'Código de autenticação ou recuperação inválido.';
            } elseif ($setup) {
                unset($_SESSION['pending_2fa_attempts']);
                $codes = securityGenerateRecoveryCodes(8);
                $encrypted = securityEncryptSecret($secret);
                $hashedCodes = securityHashRecoveryCodes($codes);

                $update = $conn->prepare(
                    'UPDATE users
                     SET two_factor_secret = ?,
                         two_factor_enabled = 1,
                         two_factor_recovery = ?,
                         two_factor_confirmed_at = NOW()
                     WHERE id = ?'
                );
                $update->bind_param('ssi', $encrypted, $hashedCodes, $uid);
                $update->execute();
                $update->close();

                unset($_SESSION['pending_2fa_secret']);
                $_SESSION['new_2fa_recovery_codes'] = $codes;
                securityLoginComplete($conn, $user);
                $recoveryCodes = $codes;
            } else {
                unset($_SESSION['pending_2fa_attempts']);
                securityLoginComplete($conn, $user);
                redirect('/dashboard.php');
            }
        }
    }

    $otpUri = $setup && $secret !== ''
        ? securityOtpAuthUri('JR CONECT ACS', (string)$user['username'], $secret)
        : '';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autenticação em duas etapas - <?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=20260921">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .twofa-card{max-width:520px;margin:8vh auto;background:#102b3d;border:1px solid #21485e;border-radius:14px;padding:28px;color:#edf6fa}
        .twofa-card h1{font-size:20px;margin-bottom:8px}
        .twofa-card p,.twofa-card small{color:#9bb6c5}
        .twofa-secret{padding:12px;border-radius:8px;background:#071d2a;border:1px solid #315569;font-family:monospace;letter-spacing:.12em;word-break:break-all}
        .recovery-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin:18px 0}
        .recovery-grid code{display:block;padding:9px;border-radius:6px;background:#071d2a;color:#7ee4ff;text-align:center}
        .twofa-uri{font-size:10px;word-break:break-all;color:#6f8fa3}
    </style>
</head>
<body>
<div class="container">
    <div class="twofa-card">
        <?php if (is_array($recoveryCodes)): ?>
            <h1>2FA ativado com sucesso</h1>
            <p>Guarde estes códigos de recuperação em local seguro. Cada código funciona apenas uma vez.</p>
            <div class="recovery-grid">
                <?php foreach ($recoveryCodes as $recovery): ?>
                    <code><?php echo htmlspecialchars($recovery, ENT_QUOTES, 'UTF-8'); ?></code>
                <?php endforeach; ?>
            </div>
            <a href="/dashboard.php" class="btn btn-primary w-100">Continuar para o ACS</a>
        <?php elseif ($setup): ?>
            <h1>Ativar autenticação em duas etapas</h1>
            <p>Seu perfil exige 2FA. No aplicativo autenticador, adicione uma conta TOTP usando a chave abaixo.</p>

            <label class="form-label">Chave secreta</label>
            <div class="twofa-secret"><?php echo htmlspecialchars($secret, ENT_QUOTES, 'UTF-8'); ?></div>
            <small class="d-block mt-2">Conta: <?php echo htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8'); ?> • Emissor: JR CONECT ACS</small>

            <details class="mt-3">
                <summary>Configuração avançada</summary>
                <div class="twofa-uri mt-2"><?php echo htmlspecialchars($otpUri, ENT_QUOTES, 'UTF-8'); ?></div>
            </details>

            <?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

            <form method="POST" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <label for="code" class="form-label">Código de 6 dígitos</label>
                <input id="code" name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required autofocus>
                <button type="submit" class="btn btn-primary w-100 mt-3">Confirmar e ativar 2FA</button>
            </form>
        <?php else: ?>
            <h1>Confirme o segundo fator</h1>
            <p>Digite o código atual do seu aplicativo autenticador. Um código de recuperação também pode ser usado.</p>

            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <label for="code" class="form-label">Código</label>
                <input id="code" name="code" class="form-control" autocomplete="one-time-code" required autofocus>
                <button type="submit" class="btn btn-primary w-100 mt-3">Validar acesso</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
