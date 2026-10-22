<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
requireLogin();
$pageTitle = 'Provisionamento de ONUs';
$currentPage = 'provisioning';
include __DIR__ . '/views/layouts/header.php';
?>
<link rel="stylesheet" href="/assets/css/provisionamento-onu.css?v=1">
<section class="onu-page">
  <header class="onu-title"><div><h1>Provisionamento de ONUs</h1><p>Inventário IXC × GenieACS. Autorização na OLT ainda não integrada.</p></div><button id="onu-refresh" class="onu-primary" type="button"><i class="bi bi-arrow-clockwise"></i> Atualizar</button></header>
  <div class="onu-stats">
    <article><i class="bi bi-clock-history"></i><strong id="onu-pending">—</strong><span>Sem registro no ACS</span></article>
    <article><i class="bi bi-check-circle"></i><strong id="onu-active">—</strong><span>TR-069 ativo</span></article>
    <article><i class="bi bi-arrow-repeat"></i><strong id="onu-stale">—</strong><span>Sem comunicação recente</span></article>
    <article><i class="bi bi-exclamation-triangle"></i><strong id="onu-fail">—</strong><span>Falhas na consulta</span></article>
    <article><i class="bi bi-hdd-network"></i><strong id="onu-total">—</strong><span>Equipamentos consultados</span></article>
  </div>
  <section class="onu-panel"><h2><i class="bi bi-funnel"></i> Filtros de busca</h2>
    <div class="onu-filters">
      <label>Status<select id="onu-status"><option value="ALL">Todos</option><option value="NOT_IN_ACS">Não no ACS</option><option value="TR069_ACTIVE">TR-069 ativo</option><option value="TR069_STALE">Sem Inform</option></select></label>
      <label>Serial Number<input id="onu-serial" placeholder="Buscar serial"></label>
      <label>Modelo<input id="onu-model" placeholder="Modelo da ONU"></label>
      <label>Cliente (IXC)<input id="onu-client" placeholder="Nome do cliente"></label>
      <button class="onu-primary" id="onu-search" type="button"><i class="bi bi-search"></i> Buscar ONUs</button>
      <button class="onu-secondary" id="onu-clear" type="button">Limpar filtros</button>
    </div>
  </section>
  <section class="onu-panel onu-results">
    <nav class="onu-tabs" aria-label="Filtros de inventário">
      <button type="button" data-status="NOT_IN_ACS">Não no ACS</button>
      <button type="button" data-status="TR069_ACTIVE">TR-069 ativo</button>
      <button type="button" data-status="ALL" class="selected">Todas as ONUs</button>
    </nav>
    <p class="onu-note"><i class="bi bi-info-circle"></i> “Não no ACS” não significa necessariamente ONU não autorizada na OLT. Esta tela é somente consulta até existir integração de autorização validada.</p>
    <div class="onu-scroll"><table><thead><tr><th>Status</th><th>Serial Number</th><th>Modelo</th><th>Cliente (IXC)</th><th>PON</th><th>Nº ONU</th><th>Último Inform</th><th>Ações</th></tr></thead><tbody id="onu-rows"><tr><td colspan="8" class="onu-empty">Carregando inventário...</td></tr></tbody></table></div>
    <footer class="onu-footer"><span id="onu-count">—</span><div><button type="button" id="onu-prev" class="onu-secondary">Anterior</button><span id="onu-page-label">Página 1</span><button type="button" id="onu-next" class="onu-secondary">Próxima</button></div></footer>
  </section>
</section>
<script src="/assets/js/provisionamento-onu.js?v=1"></script>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
