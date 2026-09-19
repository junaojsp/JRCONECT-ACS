<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Teste de Velocidade';
$currentPage = 'speedtest';

include __DIR__ . '/views/layouts/header.php';
?>

<div class="card">

    <div class="card-header">
        <i class="bi bi-speedometer"></i>
        Teste de Velocidade JR CONECT
    </div>

    <div class="card-body p-0" style="overflow: hidden;">

    <iframe
        src="https://teste.jrconect.com/"
        style="
            display: block;
            width: 100%;
            height: 850px;
            border: 0;
            overflow: hidden;
            background: #071522;
        "
        scrolling="no"
        allow="clipboard-write"
        loading="eager"
    ></iframe>

</div>

</div>

<?php
include __DIR__ . '/views/layouts/footer.php';
?>
