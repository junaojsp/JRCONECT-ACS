<?php
/**
 * JRC-ACS NOC 2.0 - previa segura, separada da dashboard principal.
 * Acesso: /dashboard-noc.php (autenticacao existente).
 */
require_once __DIR__ . '/config/config.php';
requireLogin();
$pageTitle = 'NOC 2.0 | Visão Geral';
$currentPage = 'dashboard';
include __DIR__ . '/views/layouts/header.php';
?>
<link rel="stylesheet" href="/assets/css/dashboard-noc.css?v=1">
<section class="noc" aria-label="Centro de operações da rede">
  <div class="noc-heading">
    <div>
      <p class="noc-eyebrow"><i class="bi bi-diagram-3"></i> JR CONECT TELECOM · ACS / TR-069</p>
      <h1>Visão Geral da Rede <span class="noc-pill" id="noc-health">Consultando ACS…</span></h1>
      <p>Operação dos equipamentos registrados no GenieACS · Indicadores do último snapshot</p>
    </div>
    <div class="noc-actions">
      <span id="noc-captured" class="noc-captured">Aguardando leitura</span>
      <button type="button" class="noc-button" id="noc-refresh"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>
      <a class="noc-button noc-secondary" href="/dashboard.php">Dashboard atual</a>
    </div>
  </div>

  <div class="noc-alert" id="noc-error" role="alert" hidden></div>

  <div class="noc-kpis" aria-label="Indicadores operacionais">
    <div class="noc-kpi kpi-blue"><span class="noc-kpi-icon"><i class="bi bi-router"></i></span><span class="noc-kpi-label">ONUs / CPEs monitorados</span><strong id="noc-total">—</strong><small>Dispositivos no GenieACS</small></div>
    <div class="noc-kpi kpi-green"><span class="noc-kpi-icon"><i class="bi bi-wifi"></i></span><span class="noc-kpi-label">Online</span><strong id="noc-online">—</strong><small>Inform recebido há menos de 5 min</small></div>
    <div class="noc-kpi kpi-red"><span class="noc-kpi-icon"><i class="bi bi-wifi-off"></i></span><span class="noc-kpi-label">Offline TR-069</span><strong id="noc-offline">—</strong><small>Sem Inform recente</small></div>
    <div class="noc-kpi kpi-amber"><span class="noc-kpi-icon"><i class="bi bi-exclamation-triangle"></i></span><span class="noc-kpi-label">RX óptico crítico</span><strong id="noc-critical">—</strong><small>Leituras abaixo de -28 dBm</small></div>
    <div class="noc-kpi kpi-teal"><span class="noc-kpi-icon"><i class="bi bi-bar-chart"></i></span><span class="noc-kpi-label">Disponibilidade atual</span><strong id="noc-availability">—</strong><small>Online / total (não é SLA)</small></div>
  </div>

  <div class="noc-grid">
    <article class="noc-panel noc-availability">
      <header><div><h2><i class="bi bi-activity"></i> Disponibilidade da rede</h2><span>Snapshot atual · fonte GenieACS</span></div><a href="/devices.php">Ver equipamentos <i class="bi bi-arrow-up-right"></i></a></header>
      <div class="noc-availability-content">
        <div class="noc-big-value" id="noc-availability-large">—</div>
        <p>Proporção de dispositivos com Inform recente</p>
        <div class="noc-progress" role="progressbar" aria-label="Disponibilidade atual" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="noc-progress"><div id="noc-progress-bar"></div></div>
        <div class="noc-legend"><span><i class="dot green"></i> Online <b id="noc-legend-online">—</b></span><span><i class="dot red"></i> Offline <b id="noc-legend-offline">—</b></span></div>
      </div>
      <footer><i class="bi bi-info-circle"></i> Histórico de 24h ainda não integrado. Não são exibidos pontos fictícios.</footer>
    </article>
    <article class="noc-panel noc-optics">
      <header><div><h2><i class="bi bi-broadcast"></i> Saúde do sinal óptico</h2><span>RX reportado pelo TR-069</span></div><a href="/discovery.php">Discovery <i class="bi bi-arrow-up-right"></i></a></header>
      <div class="noc-optical-content">
        <div class="noc-donut" id="noc-donut"><div><strong id="noc-donut-total">—</strong><span>Leituras</span></div></div>
        <div class="noc-optical-legend">
          <div><span><i class="dot green"></i> Normal (&gt; -25 dBm)</span><b id="noc-optical-good">—</b></div>
          <div><span><i class="dot amber"></i> Atenção (-28 a -25 dBm)</span><b id="noc-optical-attention">—</b></div>
          <div><span><i class="dot red"></i> Crítico (&lt; -28 dBm)</span><b id="noc-optical-critical">—</b></div>
          <div><span><i class="dot gray"></i> Sem leitura</span><b id="noc-optical-unknown">—</b></div>
        </div>
      </div>
      <footer>Limiares iniciais de triagem; ajustar por modelo, OLT e parâmetros da rede.</footer>
    </article>
    <article class="noc-panel">
      <header><div><h2><i class="bi bi-bell"></i> Ocorrências atuais</h2><span>Prioridades calculadas por snapshot, não são alarmes históricos</span></div><a href="/devices.php">Ver todos <i class="bi bi-arrow-up-right"></i></a></header>
      <div class="noc-list" id="noc-issues"><p class="noc-empty">Consultando dispositivos…</p></div>
    </article>
    <article class="noc-panel">
      <header><div><h2><i class="bi bi-hdd-network"></i> OLTs e portas PON</h2><span>Integração planejada · sem números simulados</span></div><a href="/map.php">Mapa da rede <i class="bi bi-arrow-up-right"></i></a></header>
      <div class="noc-placeholder"><i class="bi bi-diagram-3"></i><h3>Próxima etapa: capacidade e alarmes por PON</h3><p>A ocupação por porta, queda massiva e telemetria SNMP exigem conexão validada às OLTs / Zabbix e correlação com IXC.</p><span>Sem integração ativa nesta prévia</span></div>
    </article>
  </div>

  <article class="noc-panel noc-full">
    <header><div><h2><i class="bi bi-list-ul"></i> Equipamentos que exigem atenção</h2><span>Até 8 dispositivos prioritários · sem exibição de credenciais</span></div><a href="/devices.php">Lista completa <i class="bi bi-arrow-up-right"></i></a></header>
    <div class="noc-table-wrap"><table class="noc-table"><thead><tr><th>Dispositivo</th><th>Modelo</th><th>RX óptico</th><th>Situação</th><th>Último Inform</th><th>Ação</th></tr></thead><tbody id="noc-table-body"><tr><td colspan="6" class="noc-empty">Aguardando dados…</td></tr></tbody></table></div>
  </article>
  <p class="noc-disclaimer">JRC-ACS NOC 2.0 · Prévia em desenvolvimento · Leitura somente · Fonte: GenieACS TR-069. O status online mede a atualização no ACS, não confirma acesso à Internet do assinante.</p>
</section>
<script src="/assets/js/dashboard-noc.js?v=1" defer></script>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
