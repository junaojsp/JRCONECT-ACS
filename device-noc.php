<?php
require_once __DIR__ . '/config/config.php';
requireLogin();
$inventoryId = $_GET['id'] ?? '';
if (!is_string($inventoryId) || $inventoryId === '' || strlen($inventoryId) > 1024) {
    header('Location: /devices-noc.php'); exit;
}
$inventoryDetail = true;
include __DIR__ . '/views/layouts/noc-inventory-header.php';
?>
<div class="inventory-detail-links"><a href="/devices-noc.php">← Voltar aos equipamentos</a><a class="noc-button" href="/device-detail.php?id=<?php echo rawurlencode($inventoryId); ?>">Abrir tela atual e ações ↗</a></div>
<article class="noc-panel inventory-identity"><i class="bi bi-router"></i><div><h2 id="device-model">Consultando equipamento…</h2><p id="device-identity">—</p></div><span class="noc-chip" id="device-status">Aguardando</span></article>
<nav class="inventory-tabs" aria-label="Seções do equipamento"><a href="#device-overview">Visão geral</a><a href="#device-optical">Óptico</a><a href="#device-wan">WAN</a><a href="#device-wifi">Wi-Fi</a><a href="#device-system">Sistema</a></nav>
<article class="noc-panel" id="device-overview"><header><div><h2><i class="bi bi-activity"></i> Diagnóstico rápido</h2><span>Dados disponíveis nesta consulta · sem executar comandos no equipamento</span></div></header><div class="inventory-diagnostics" id="device-diagnostics"><p>Consultando parâmetros…</p></div></article>
<div class="noc-grid inventory-detail-grid">
  <article class="noc-panel" id="device-optical"><header><div><h2><i class="bi bi-broadcast"></i> Sinal e temperatura</h2><span>Fontes e horários de cada leitura</span></div></header><div class="inventory-metrics" id="device-metrics"></div><footer>Leituras ausentes não são consideradas saudáveis. Limiares RX: normal &gt; -25; atenção -28 &lt; RX ≤ -25; crítico ≤ -28 dBm.</footer></article>
  <article class="noc-panel" id="device-system"><header><div><h2><i class="bi bi-cpu"></i> Sistema</h2><span>Identificação reportada pelo ACS</span></div></header><dl class="inventory-properties" id="device-properties"></dl></article>
  <article class="noc-panel" id="device-wan"><header><div><h2><i class="bi bi-globe"></i> Conexões WAN</h2><span>Estado reportado · não é teste de acesso à Internet</span></div></header><div id="device-wans"></div></article>
  <article class="noc-panel" id="device-wifi"><header><div><h2><i class="bi bi-wifi"></i> Redes Wi-Fi</h2><span>SSIDs reportados · sem exibição de senhas</span></div></header><div id="device-networks"></div></article>
</div>
<p class="noc-disclaimer">Prévia de leitura · Instâncias conhecidas dos modelos TR-069. Para parâmetros adicionais, histórico e operações, use a tela atual do equipamento. Esta consulta não mede velocidade ou tráfego.</p>
</section></main><script src="/assets/js/noc-inventory.js?v=1"></script></body></html>
