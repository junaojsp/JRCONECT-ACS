<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo $pageTitle ?? 'JR CONECT ACS'; ?></title>

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

    <!-- Logger -->
    <script
        src="/assets/js/client-logger.js?v=<?php echo time(); ?>"
    ></script>
</head>

<body>

    <!-- Menu lateral -->
    <div class="sidebar" id="sidebar">

        <div class="sidebar-header">

            <i
                class="bi bi-hdd-network"
                style="font-size: 2.5rem; margin-bottom: 0.5rem;"
            ></i>

            <h3 style="font-size: 1rem; line-height: 1.3;">
                JR CONECT ACS
            </h3>

        </div>


        <ul class="sidebar-menu">

            <li>

                <a
                    href="/dashboard.php"
                    class="<?php echo ($currentPage ?? '') === 'dashboard' ? 'active' : ''; ?>"
                    data-tooltip="Visão Geral"
                >
                    <i class="bi bi-speedometer2"></i>
                    <span>Visão Geral</span>
                </a>

            </li>


            <li>

                <a
                    href="/devices.php"
                    class="<?php echo ($currentPage ?? '') === 'devices' ? 'active' : ''; ?>"
                    data-tooltip="Equipamentos"
                >
                    <i class="bi bi-router"></i>
                    <span>Equipamentos</span>
                </a>

            </li>


            <li>

                <a
                    href="/map.php"
                    class="<?php echo ($currentPage ?? '') === 'map' ? 'active' : ''; ?>"
                    data-tooltip="Mapa da Rede"
                >
                    <i class="bi bi-diagram-3"></i>
                    <span>Mapa da Rede</span>
                </a>

            </li>
<a href="/discovery.php"
   class="<?= ($pageTitle ?? '') === 'Discovery TR-069' ? 'active' : '' ?>"
   data-tooltip="Discovery TR-069">
    <i class="bi bi-search"></i>
    <span>Discovery TR-069</span>
</a>

            <li>

                <a
                    href="/configuration.php"
                    class="<?php echo ($currentPage ?? '') === 'configuration' ? 'active' : ''; ?>"
                    data-tooltip="Configurações"
                >
                    <i class="bi bi-gear"></i>
                    <span>Configurações</span>
                </a>

            </li>


            <li>



<li>
    <a
        href="/users.php"
        class="<?php echo ($currentPage ?? '') === 'users' ? 'active' : ''; ?>"
        data-tooltip="Usuários"
    >
        <i class="bi bi-people"></i>
        <span>Usuários</span>
    </a>
</li>

<li>
    <a
        href="/logout.php"
        data-tooltip="Sair"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>Sair</span>
    </a>
</li>

              


        <!-- Botão recolher menu -->
        <button
            class="sidebar-toggle"
            id="sidebarToggle"
            type="button"
            title="Recolher menu"
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


            <div class="user-info">

                <span>
                    <i class="bi bi-person-circle"></i>
                    <?php echo $_SESSION['username'] ?? 'Usuário'; ?>
                </span>

            </div>

        </div>


        <!-- Conteúdo -->
        <div class="content-wrapper">