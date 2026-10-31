<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'MCP Server';
$currentPage = 'mcp-server';

include __DIR__ . '/views/layouts/header.php';
?>

<link rel="stylesheet" href="/assets/css/mcp-connections.css?v=20261031-7">
<script>document.body.classList.add("jrc-mcp-standalone");</script>

<div class="jrc-mcp-page">
    <div class="jrc-mcp-page__header">
        <div>
            <div class="jrc-mcp-page__eyebrow">
                <i class="bi bi-stack"></i>
                JR Conect MCPv2
            </div>
            <h1>MCP Server</h1>
            <p>
                Serviços disponíveis, ferramentas registradas e clientes autenticados
                no servidor MCP da JR Conect.
            </p>
        </div>

        <div class="jrc-mcp-page__meta">
            <div>
                <span>Container</span>
                <strong>jrconect-mcp</strong>
            </div>
            <div>
                <span>Porta local</span>
                <strong>127.0.0.1:3100</strong>
            </div>
            <div>
                <span>Endpoint</span>
                <strong>mcp.jrconect.com/mcp</strong>
            </div>
        </div>
    </div>

    <div id="mcp-server-hub"></div>
</div>

<script src="/assets/js/mcp-connections-panel.js?v=20261031-7" defer></script>

<?php
include __DIR__ . '/views/layouts/footer.php';
?>
