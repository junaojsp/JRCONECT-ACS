<?php
$nav = $currentPage ?? '';
$username = $_SESSION['username'] ?? 'operador';
$roleLabel = trim((string) ($_SESSION['role'] ?? ''));
if ($roleLabel === '') {
    $roleLabel = 'Operador';
}
$initials = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $username), 0, 2) ?: 'JR');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'JRC ACS', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=20260921">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/scout.css?v=20261022">
    <script src="/assets/js/client-logger.js?v=<?php echo time(); ?>"></script>
</head>
<body>
    <aside class="sidebar" id="sidebar">
        <div class="fb-brand">
            <span class="fb-mark">J</span>
            <span class="fb-brand-text">
                <strong>JRC</strong>
                <small>ACS</small>
            </span>
        </div>

        <ul class="fb-nav">
            <li class="fb-nav-label">Gestão</li>
            <li>
                <a href="/dashboard.php" class="<?php echo $nav === 'dashboard' ? 'active' : ''; ?>">
                    <i class="bi bi-grid"></i>
                    <span>Visão geral</span>
                </a>
            </li>
            <li>
                <a href="/devices.php" class="<?php echo $nav === 'devices' ? 'active' : ''; ?>">
                    <i class="bi bi-router"></i>
                    <span>Dispositivos</span>
                </a>
            </li>
            <li>
                <a href="/discovery.php" class="<?php echo $nav === 'discovery' ? 'active' : ''; ?>">
                    <i class="bi bi-key"></i>
                    <span>Discovery</span>
                </a>
            </li>
            <li>
                <a href="/map.php" class="<?php echo $nav === 'map' ? 'active' : ''; ?>">
                    <i class="bi bi-diagram-3"></i>
                    <span>Mapa da rede</span>
                </a>
            </li>
            <li>
                <a href="/alerts.php" class="<?php echo $nav === 'alerts' ? 'active' : ''; ?>">
                    <i class="bi bi-bell"></i>
                    <span>Alertas</span>
                    <em class="fb-count" id="nav-alert-count" hidden>0</em>
                </a>
            </li>
            <li>
                <a href="/configuration.php" class="<?php echo $nav === 'configuration' ? 'active' : ''; ?>">
                    <i class="bi bi-sliders"></i>
                    <span>Configuração</span>
                </a>
            </li>

            <li class="fb-nav-label">Plataforma</li>
            <li>
                <a href="/users.php" class="<?php echo $nav === 'users' ? 'active' : ''; ?>">
                    <i class="bi bi-people"></i>
                    <span>Usuários</span>
                </a>
            </li>
            <li>
                <a href="/view-logs.php" class="<?php echo $nav === 'logs' ? 'active' : ''; ?>">
                    <i class="bi bi-journal-text"></i>
                    <span>Registros</span>
                </a>
            </li>
        </ul>

        <div class="fb-sidebar-foot">
            <span class="fb-avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="fb-user-meta">
                <strong><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></strong>
                <small><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></small>
            </span>
            <a class="fb-logout" href="/logout.php" title="Sair">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </aside>

    <div class="main-content" id="mainContent">
        <div class="topbar">
            <button class="fb-icon-btn" id="menuToggle" type="button" title="Menu">
                <i class="bi bi-list"></i>
            </button>
            <button class="fb-icon-btn" id="sidebarToggle" type="button" title="Recolher menu">
                <i class="bi bi-chevron-left"></i>
            </button>
            <div class="fb-workspace">
                <span class="fb-mark" style="width:26px;height:26px;border-radius:8px;font-size:12px;">J</span>
                <b>JRC ACS</b>
                <span>produção</span>
            </div>
            <form class="fb-search" action="/devices.php" method="get">
                <i class="bi bi-search"></i>
                <input type="search" name="q" placeholder="Buscar serial, cliente, IP..." aria-label="Buscar equipamento">
            </form>
            <div class="fb-top-actions">
                <button class="fb-icon-btn" type="button" title="Atualizar" onclick="location.reload()">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <a class="fb-icon-btn" href="/alerts.php" title="Alertas">
                    <i class="bi bi-bell"></i>
                </a>
                <span class="fb-lang" title="Português">PT</span>
                <a class="fb-icon-btn" href="/configuration.php" title="Configuração">
                    <i class="bi bi-gear"></i>
                </a>
            </div>
        </div>
        <div class="content-wrapper">
