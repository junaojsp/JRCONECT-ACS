<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo $pageTitle ?? 'JR CONECT ACS'; ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=20260921">

    <!-- Google Fonts - Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <!-- CSS Libraries -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

    <link
        rel="stylesheet"
        href="/assets/css/style.css?v=<?php echo time(); ?>"
    >

    <link rel="stylesheet" href="/assets/css/sidebar.css?v=20261005-toggle">
    <link rel="stylesheet" href="/assets/css/scout-nav.css?v=20261022-menu2">
    <?php if (($currentPage ?? '') === 'dashboard' || ($pageTitle ?? '') === 'Equipamentos'): ?>
    <link rel="stylesheet" href="/assets/css/page-readability.css?v=20261005-1">
    <script src="/assets/js/page-readability.js?v=20261005-1"></script>
    <?php endif; ?>

    <!-- Logger -->
    <script
        src="/assets/js/client-logger.js?v=<?php echo time(); ?>"
    ></script>
</head>

<body>

    <!-- Menu lateral -->
    <?php
    $nav = $currentPage ?? '';
    $navUser = $_SESSION['username'] ?? 'operador';
    $navRole = trim((string) ($_SESSION['role'] ?? ''));
    if ($navRole === '') {
        $navRole = 'Operador';
    }
    $navInitials = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $navUser), 0, 2) ?: 'JR');
    $firmwareDeviceId = (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'device-detail.php' && !empty($_GET['id']))
        ? (string) $_GET['id']
        : '';
    $firmwareHref = $firmwareDeviceId !== ''
        ? '/device-detail.php?id=' . rawurlencode($firmwareDeviceId) . '&aba=firmware'
        : '/devices.php';
    ?>
    <div class="sidebar" id="sidebar">

        <div class="fb-brand">
            <span class="fb-mark">J</span>
            <span class="fb-brand-text">
                <strong>JRC</strong>
                <small>ACS</small>
            </span>
        </div>

        <ul class="fb-nav" id="sidebar-menu">
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
                    <i class="bi bi-diagram-3"></i>
                    <span>Provisionamento</span>
                </a>
            </li>
            <li>
                <a href="/configuration.php" class="<?php echo $nav === 'configuration' ? 'active' : ''; ?>">
                    <i class="bi bi-gear"></i>
                    <span>Configuração</span>
                </a>
            </li>
            <li>
                <a id="nav-firmware" href="<?php echo htmlspecialchars($firmwareHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $nav === 'firmware' ? 'active' : ''; ?>">
                    <i class="bi bi-cpu"></i>
                    <span>Firmware</span>
                </a>
            </li>
            <li>
                <a href="/speedtest.php" class="<?php echo $nav === 'speedtest' ? 'active' : ''; ?>">
                    <i class="bi bi-activity"></i>
                    <span>Diagnóstico</span>
                </a>
            </li>
            <li>
                <a href="/alerts.php" class="<?php echo $nav === 'alerts' ? 'active' : ''; ?>">
                    <i class="bi bi-bell"></i>
                    <span>Alertas</span>
                </a>
            </li>
            <li>
                <a href="/view-logs.php" class="<?php echo $nav === 'logs' ? 'active' : ''; ?>">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>Relatórios</span>
                </a>
            </li>
            <li>
                <a href="/users.php" class="<?php echo $nav === 'users' ? 'active' : ''; ?>">
                    <i class="bi bi-person-gear"></i>
                    <span>Administração</span>
                </a>
            </li>
        </ul>

        <div class="fb-user">
            <span class="fb-avatar"><?php echo htmlspecialchars($navInitials, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="fb-user-text">
                <strong><?php echo htmlspecialchars($navUser, ENT_QUOTES, 'UTF-8'); ?></strong>
                <small><?php echo htmlspecialchars($navRole, ENT_QUOTES, 'UTF-8'); ?></small>
            </span>
            <a class="fb-logout" href="/logout.php" title="Sair">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>

        <button
            class="sidebar-toggle"
            id="sidebarToggle"
            type="button"
            hidden
            title="Recolher menu"
            aria-label="Recolher menu"
            aria-controls="sidebar-menu"
            aria-expanded="true"
        >
            <i class="bi bi-chevron-left"></i>
        </button>

    </div>


    <!-- Conteúdo principal -->
    <div
        class="main-content"
        id="mainContent"
    >

        <!-- Barra superior -->
        <div class="topbar">

            <h4>
                <?php echo $pageTitle ?? 'JR CONECT ACS'; ?>
            </h4>

            <?php if (($currentPage ?? '') === 'devices' && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'devices.php'): ?>
            <label class="fb-top-search-wrap">
                <i class="bi bi-search"></i>
                <input
                    id="fleet-search"
                    class="fb-top-search"
                    type="search"
                    placeholder="Buscar serial, cliente, IP..."
                    oninput="var box=document.getElementById('search-input'); if(box) box.value=this.value; if(window.filterDevices) filterDevices();"
                >
            </label>
            <?php endif; ?>


            <div class="user-info">

                <span>
                    <i class="bi bi-person-circle"></i>
                    <?php echo $_SESSION['username'] ?? 'Usuário'; ?>
                </span>

            </div>

        </div>


        <!-- Conteúdo -->
        <div class="content-wrapper">
