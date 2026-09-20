<?php

require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

// Verificar se o GenieACS está configurado
$genieacsConfigured = isGenieACSConfigured();

include __DIR__ . '/views/layouts/header.php';

?>

<style>

/* ==========================================================
   JR CONECT ACS
   DASHBOARD V2
   ========================================================== */

.jrc-dashboard {
    width: 100%;
}


/* ----------------------------------------------------------
   CABEÇALHO
   ---------------------------------------------------------- */

.jrc-dashboard-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 16px;
}

.jrc-dashboard-title h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}

.jrc-dashboard-title p {
    margin: 4px 0 0;
    color: #708da0;
    font-size: 11px;
}

.jrc-live-status {
    display: inline-flex;
    align-items: center;
    gap: 7px;

    padding: 7px 11px;

    border:
        1px solid
        rgba(32, 213, 155, .15);

    border-radius: 8px;

    background:
        rgba(32, 213, 155, .045);

    color: #60dfb5;

    font-size: 10px;
    font-weight: 700;

    letter-spacing: .04em;
}

.jrc-live-dot {
    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: #20d59b;

    box-shadow:
        0 0 9px
        rgba(32, 213, 155, .65);
}


/* ----------------------------------------------------------
   GRID PRINCIPAL
   ---------------------------------------------------------- */

.jrc-dashboard-grid {
    display: grid;

    grid-template-columns:
        minmax(360px, 1.15fr)
        minmax(320px, .9fr)
        minmax(300px, .9fr);

    gap: 14px;

    align-items: stretch;

    margin-bottom: 14px;
}


/* ----------------------------------------------------------
   PAINÉIS
   ---------------------------------------------------------- */

.jrc-panel {
    position: relative;

    background:
        linear-gradient(
            145deg,
            rgba(11, 27, 41, .99),
            rgba(6, 17, 28, .99)
        );

    border:
        1px solid
        rgba(106, 159, 187, .13);

    border-radius: 13px;

    overflow: hidden;

    box-shadow:
        0 12px 36px
        rgba(0, 0, 0, .18);
}

.jrc-panel::before {
    content: "";

    position: absolute;

    top: 0;
    left: 0;
    right: 0;

    height: 1px;

    background:
        linear-gradient(
            90deg,
            transparent,
            rgba(0, 189, 227, .45),
            transparent
        );
}

.jrc-panel-header {
    min-height: 60px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    padding: 13px 16px;

    border-bottom:
        1px solid
        rgba(106, 159, 187, .09);
}

.jrc-panel-title {
    display: flex;
    align-items: center;
    gap: 9px;
}

.jrc-panel-title i {
    color: #35c5e4;
    font-size: 14px;
}

.jrc-panel-title strong {
    display: block;

    color: #e8f5fa;

    font-size: 12px;
    font-weight: 700;
}

.jrc-panel-title small {
    display: block;

    margin-top: 2px;

    color: #648397;

    font-size: 9px;
}

.jrc-panel-body {
    padding: 18px;
}


/* ----------------------------------------------------------
   BOTÕES DOS PAINÉIS
   ---------------------------------------------------------- */

.jrc-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    padding: 6px 9px;

    border:
        1px solid
        rgba(0, 189, 227, .14);

    border-radius: 7px;

    background:
        rgba(0, 189, 227, .05);

    color: #6dd3ea;

    font-size: 10px;

    cursor: pointer;

    text-decoration: none;
}

.jrc-action:hover {
    color: #b6effa;

    background:
        rgba(0, 189, 227, .1);
}


/* ----------------------------------------------------------
   STATUS DOS DISPOSITIVOS
   ---------------------------------------------------------- */

.jrc-availability {
    padding: 2px 2px 20px;
}

.jrc-availability-top {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
}

.jrc-availability-value {
    font-size: 42px;
    font-weight: 700;

    line-height: 1;

    letter-spacing: -.04em;

    color: #effaff;
}

.jrc-availability-label {
    display: block;

    margin-top: 7px;

    color: #718da0;

    font-size: 10px;
}

.jrc-total-mini {
    text-align: right;
}

.jrc-total-mini strong {
    display: block;

    font-size: 22px;

    color: #dbeaf2;
}

.jrc-total-mini span {
    color: #648397;

    font-size: 9px;
}


/* barra */

.jrc-progress {
    width: 100%;
    height: 7px;

    margin-top: 18px;

    overflow: hidden;

    border-radius: 999px;

    background:
        rgba(115, 148, 167, .12);
}

.jrc-progress-bar {
    width: 0%;
    height: 100%;

    border-radius: inherit;

    background:
        linear-gradient(
            90deg,
            #00b5dc,
            #20d59b
        );

    box-shadow:
        0 0 13px
        rgba(32, 213, 155, .28);

    transition:
        width .5s ease;
}


/* ----------------------------------------------------------
   CARDS ONLINE / OFFLINE
   ---------------------------------------------------------- */

.jrc-status-grid {
    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 10px;

    margin-top: 12px;
}

.jrc-status-card {
    display: flex;
    align-items: center;

    gap: 11px;

    min-height: 72px;

    padding: 12px;

    border:
        1px solid
        rgba(105, 159, 187, .09);

    border-radius: 10px;

    background:
        rgba(255, 255, 255, .014);

    text-decoration: none;

    transition: .17s ease;
}

.jrc-status-card:hover {
    transform: translateY(-2px);

    border-color:
        rgba(0, 189, 227, .19);

    background:
        rgba(0, 189, 227, .035);
}

.jrc-status-icon {
    width: 35px;
    height: 35px;

    display: grid;
    place-items: center;

    flex-shrink: 0;

    border-radius: 9px;

    font-size: 15px;
}

.jrc-status-card.online .jrc-status-icon {
    color: #37dfa9;

    background:
        rgba(32, 213, 155, .08);
}

.jrc-status-card.offline .jrc-status-icon {
    color: #ff818d;

    background:
        rgba(240, 100, 114, .08);
}

.jrc-status-value {
    display: block;

    font-size: 22px;
    font-weight: 700;

    line-height: 1;

    color: #eaf5f9;
}

.jrc-status-name {
    display: block;

    margin-top: 6px;

    color: #728ea1;

    font-size: 9px;

    text-transform: uppercase;

    letter-spacing: .06em;
}


/* ----------------------------------------------------------
   GRÁFICOS
   ---------------------------------------------------------- */

.jrc-chart-box {
    position: relative;

    width: 100%;
    max-width: 245px;

    margin: 0 auto;
}

.jrc-chart-box canvas {
    width: 100% !important;
}


/* ----------------------------------------------------------
   RESUMO ACS / IA
   ---------------------------------------------------------- */

.jrc-ai-panel {
    border-color:
        rgba(161, 84, 255, .18);
}

.jrc-ai-panel::before {
    background:
        linear-gradient(
            90deg,
            transparent,
            rgba(174, 78, 255, .6),
            transparent
        );
}

.jrc-ai-icon {
    color: #ad6cff !important;
}

.jrc-ai-content {
    min-height: 232px;

    display: flex;
    flex-direction: column;

    justify-content: space-between;
}

.jrc-ai-intro {
    color: #8ba2b1;

    font-size: 11px;

    line-height: 1.7;
}

.jrc-ai-list {
    display: grid;
    gap: 9px;

    margin-top: 16px;
}

.jrc-ai-item {
    display: flex;
    align-items: center;

    gap: 9px;

    padding: 9px 10px;

    border:
        1px solid
        rgba(115, 148, 168, .08);

    border-radius: 8px;

    color: #9cb3c0;

    font-size: 10px;

    background:
        rgba(255, 255, 255, .012);
}

.jrc-ai-item i {
    color: #a871ff;
}

.jrc-ai-footer {
    margin-top: 17px;

    padding-top: 13px;

    border-top:
        1px solid
        rgba(115, 148, 168, .08);

    color: #657f91;

    font-size: 9px;
}


/* ----------------------------------------------------------
   LINHA SECUNDÁRIA
   ---------------------------------------------------------- */

.jrc-secondary-grid {
    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 14px;

    margin-bottom: 14px;
}


/* ----------------------------------------------------------
   ATIVIDADE
   ---------------------------------------------------------- */

.jrc-recent-panel {
    margin-bottom: 0;
}

.jrc-recent-body {
    padding: 4px 15px 14px;
}

#recent-devices {
    min-height: 90px;
}


/* ----------------------------------------------------------
   TABELA V2
   ---------------------------------------------------------- */

#recent-devices .table-responsive {
    border-radius: 8px;

    overflow-x: auto;
}

#recent-devices .table {
    margin-bottom: 0;
}

#recent-devices .table thead th {
    background:
        rgba(5, 17, 27, .3);

    color: #647f91 !important;

    font-size: 8px !important;

    letter-spacing: .07em;
}

#recent-devices .table tbody td {
    font-size: 10px;

    color: #a9bfcc !important;
}

#recent-devices .table tbody tr:hover {
    background:
        rgba(0, 189, 227, .035) !important;
}


/* ----------------------------------------------------------
   RODAPÉ INFORMATIVO DO STATUS
   ---------------------------------------------------------- */

.jrc-status-footer {
    display: flex;
    align-items: center;

    gap: 16px;

    margin-top: 16px;

    padding-top: 13px;

    border-top:
        1px solid
        rgba(105, 159, 187, .08);

    color: #688596;

    font-size: 9px;
}

.jrc-status-footer span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.jrc-dot {
    width: 6px;
    height: 6px;

    border-radius: 50%;
}

.jrc-dot.green {
    background: #20d59b;

    box-shadow:
        0 0 7px
        rgba(32, 213, 155, .4);
}

.jrc-dot.red {
    background: #f06472;

    box-shadow:
        0 0 7px
        rgba(240, 100, 114, .4);
}


/* ----------------------------------------------------------
   RESPONSIVO
   ---------------------------------------------------------- */

@media (max-width: 1250px) {

    .jrc-dashboard-grid {
        grid-template-columns:
            1fr 1fr;
    }

    .jrc-ai-panel {
        grid-column:
            1 / -1;
    }

}

@media (max-width: 850px) {

    .jrc-dashboard-grid,
    .jrc-secondary-grid {
        grid-template-columns:
            1fr;
    }

}

@media (max-width: 560px) {

    .jrc-status-grid {
        grid-template-columns:
            1fr;
    }

    .jrc-dashboard-title {
        align-items: flex-start;
        flex-direction: column;
    }

}



/* ===== JR CONECT DASHBOARD MODERN V3 ===== */
.jrc-dashboard{
    --dash-bg:#101827;
    --dash-card:#182235;
    --dash-card-2:#1b2638;
    --dash-card-soft:#141f30;
    --dash-border:#2a3850;
    --dash-border-soft:#223148;
    --dash-text:#e7edf6;
    --dash-muted:#8592a8;
    --dash-cyan:#35cfe3;
    --dash-green:#27d39f;
    --dash-red:#ef6876;
    --dash-purple:#9f7aea;
    width:100%;
}

/* Cabeçalho como barra operacional */
.jrc-dashboard-title{
    min-height:72px;
    margin:0 0 14px;
    padding:14px 16px;
    border:1px solid var(--dash-border);
    border-radius:12px;
    background:linear-gradient(180deg,#182235,#151f30);
}
.jrc-dashboard-title h2{
    color:var(--dash-text);
    font-size:20px;
    letter-spacing:-.02em;
}
.jrc-dashboard-title p{
    color:var(--dash-muted);
    font-size:10px;
}
.jrc-live-status{
    color:#83f0c8;
    border-color:rgba(39,211,159,.32);
    background:rgba(39,211,159,.07);
    border-radius:999px;
    padding:7px 12px;
}

/* Grid principal mais equilibrado */
.jrc-dashboard-grid{
    grid-template-columns:1.08fr .92fr .92fr;
    gap:12px;
    margin-bottom:12px;
}
.jrc-secondary-grid{
    grid-template-columns:1fr 1fr;
    gap:12px;
    margin-bottom:12px;
}

/* Cards */
.jrc-panel{
    background:linear-gradient(180deg,var(--dash-card-2),var(--dash-card)) !important;
    border:1px solid var(--dash-border) !important;
    border-radius:12px;
    box-shadow:0 8px 20px rgba(0,0,0,.12);
}
.jrc-panel::before{
    height:2px;
    background:linear-gradient(90deg,transparent,rgba(53,207,227,.42),transparent);
}
.jrc-panel-header{
    min-height:52px;
    padding:11px 14px;
    border-bottom:1px solid var(--dash-border-soft);
}
.jrc-panel-title{
    gap:10px;
}
.jrc-panel-title i{
    width:28px;
    height:28px;
    display:grid;
    place-items:center;
    border-radius:8px;
    background:rgba(53,207,227,.07);
    color:var(--dash-cyan);
    font-size:13px;
}
.jrc-panel-title strong{
    color:var(--dash-text);
    font-size:11px;
}
.jrc-panel-title small{
    color:var(--dash-muted);
    font-size:8px;
}
.jrc-panel-body{
    padding:14px;
}

/* Botões discretos */
.jrc-action{
    min-height:30px;
    padding:0 10px;
    border-radius:8px;
    border:1px solid #2d4960;
    background:#142336;
    color:#a9d8e3;
    font-size:9px;
    font-weight:700;
}
.jrc-action:hover{
    background:#192a40;
    color:#eaf7fb;
    border-color:#3a6079;
}

/* KPI principal */
.jrc-availability{
    padding:4px 2px 16px;
}
.jrc-availability-value{
    font-size:46px;
    color:#f2f7fb;
}
.jrc-availability-label,
.jrc-total-mini span{
    color:var(--dash-muted);
}
.jrc-total-mini strong{
    color:#eaf1f7;
    font-size:24px;
}
.jrc-progress{
    height:8px;
    margin-top:16px;
    background:#111b2a;
    border:1px solid #223148;
}
.jrc-progress-bar{
    background:linear-gradient(90deg,#24bddd,#27d39f);
    box-shadow:none;
}

/* Online / offline */
.jrc-status-grid{
    gap:8px;
    margin-top:10px;
}
.jrc-status-card{
    min-height:68px;
    padding:11px 12px;
    border-color:var(--dash-border-soft);
    background:#151f30;
}
.jrc-status-card:hover{
    transform:none;
    background:#19263a;
    border-color:#33465f;
}
.jrc-status-icon{
    width:36px;
    height:36px;
    border-radius:9px;
}
.jrc-status-value{
    font-size:21px;
}
.jrc-status-name{
    font-size:8px;
}
.jrc-status-footer{
    margin-top:12px;
    padding-top:10px;
    border-top-color:var(--dash-border-soft);
    color:var(--dash-muted);
}

/* Gráficos mais compactos */
.jrc-chart-box{
    max-width:225px;
}
.jrc-dashboard-grid .jrc-chart-box{
    padding:4px 0;
}
.jrc-secondary-grid .jrc-chart-box{
    max-width:210px;
}

/* IA no mesmo padrão, sem roxo excessivo */
.jrc-ai-panel{
    border-color:var(--dash-border) !important;
}
.jrc-ai-panel::before{
    background:linear-gradient(90deg,transparent,rgba(159,122,234,.5),transparent);
}
.jrc-ai-icon{
    color:#ae91ef !important;
    background:rgba(159,122,234,.08) !important;
}
.jrc-ai-content{
    min-height:218px;
}
.jrc-ai-intro{
    color:#9aa8ba;
    font-size:10px;
    line-height:1.55;
}
.jrc-ai-list{
    gap:7px;
    margin-top:12px;
}
.jrc-ai-item{
    min-height:36px;
    padding:8px 10px;
    border:1px solid var(--dash-border-soft);
    background:#151f30;
    color:#aab7c7;
    font-size:9px;
}
.jrc-ai-item:hover{
    background:#19263a;
    border-color:#33465f;
}
.jrc-ai-item i{
    width:24px;
    color:#9f7aea;
}
.jrc-ai-footer{
    color:#6f7f94;
    border-top-color:var(--dash-border-soft);
}

/* Acessos rápidos */
.jrc-secondary-grid .jrc-panel:nth-child(2) .jrc-panel-body>div{
    gap:8px !important;
}
.jrc-secondary-grid .jrc-panel:nth-child(2) .jrc-ai-item{
    min-height:46px;
    border-radius:9px;
    font-size:10px;
}
.jrc-secondary-grid .jrc-panel:nth-child(2) .jrc-ai-item i{
    width:28px;
    height:28px;
    display:grid;
    place-items:center;
    border-radius:7px;
    background:rgba(53,207,227,.06);
    color:#66d7e8;
}

/* Atividade recente */
.jrc-recent-panel{
    border-radius:12px;
}
.jrc-recent-body{
    padding:0 12px 12px;
}
#recent-devices .table-responsive{
    border:1px solid var(--dash-border-soft);
    border-radius:9px;
    overflow:auto;
}
#recent-devices .table thead th{
    background:#141f30 !important;
    color:#8998ad !important;
    padding:10px 9px !important;
    font-size:8px !important;
}
#recent-devices .table tbody td{
    background:#182235 !important;
    border-color:#223148 !important;
    color:#c2ccd8 !important;
    padding:10px 9px !important;
    font-size:9px !important;
}
#recent-devices .table tbody tr:hover td{
    background:#1d2a3f !important;
}

/* Ajuste do espaço vertical geral */
.jrc-dashboard .spinner{
    width:32px;
    height:32px;
}
@media(max-width:1250px){
    .jrc-dashboard-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:850px){
    .jrc-dashboard-grid,.jrc-secondary-grid{grid-template-columns:1fr}
    .jrc-dashboard-title{align-items:flex-start;flex-direction:column}
}


/* ===== ACS NOC DASHBOARD V4 ===== */
.acs-noc-head{
    display:flex;align-items:center;justify-content:space-between;gap:18px;
    padding:14px 16px;margin-bottom:12px;border:1px solid #2a3850;border-radius:12px;
    background:linear-gradient(180deg,#182235,#151f30);
}
.acs-noc-head-left{display:flex;align-items:center;gap:12px}
.acs-noc-head-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:10px;background:#15364a;color:#35cfe3;font-size:19px}
.acs-noc-head h2{margin:0;color:#eef4f9;font-size:20px;font-weight:800}
.acs-noc-head p{margin:3px 0 0;color:#8592a8;font-size:10px}
.acs-noc-head-right{display:flex;align-items:center;gap:9px}

.acs-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.acs-kpi-card{
    min-height:104px;display:flex;align-items:center;gap:12px;padding:14px 15px;
    border:1px solid #2a3850;border-radius:11px;background:linear-gradient(180deg,#1b2638,#182235);
}
.acs-kpi-icon{width:43px;height:43px;display:grid;place-items:center;flex:0 0 auto;border-radius:10px;font-size:18px}
.acs-kpi-icon.cyan{background:rgba(53,207,227,.10);color:#35cfe3}
.acs-kpi-icon.green{background:rgba(39,211,159,.10);color:#27d39f}
.acs-kpi-icon.red{background:rgba(239,104,118,.10);color:#ef6876}
.acs-kpi-icon.blue{background:rgba(66,153,225,.10);color:#63b3ed}
.acs-kpi-card span{display:block;color:#aab5c4;font-size:9px;text-transform:uppercase;letter-spacing:.04em}
.acs-kpi-card strong{display:block;margin-top:3px;color:#f0f5fa;font-size:28px;line-height:1}
.acs-kpi-card small{display:block;margin-top:5px;color:#718096;font-size:8px}

.acs-noc-grid{
    display:grid;grid-template-columns:1.15fr .95fr;gap:12px;margin-bottom:12px;align-items:stretch;
}
.acs-network-body{display:grid;grid-template-columns:1.15fr .85fr;gap:12px;align-items:center;min-height:240px}
.acs-network-chart{display:flex;align-items:center;justify-content:center}
.acs-network-chart .jrc-chart-box{max-width:220px}
.acs-network-summary{display:flex;flex-direction:column;gap:7px}
.acs-network-summary-row{
    min-height:38px;display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:8px 10px;border:1px solid #223148;border-radius:8px;background:#151f30;
    color:#9caabd;font-size:9px;
}
.acs-network-summary-row span{display:flex;align-items:center;gap:7px}
.acs-network-summary-row span i{width:8px;height:8px;border-radius:50%;background:#718096}
.acs-network-summary-row.online span i{background:#27d39f}
.acs-network-summary-row.offline span i{background:#ef6876}
.acs-network-summary-row strong{color:#e7edf6;font-size:11px}
.acs-optical-chart{max-width:230px}
.acs-recent-modern{margin-bottom:0}

@media(max-width:1250px){
    .acs-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .acs-noc-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:760px){
    .acs-noc-head{align-items:flex-start;flex-direction:column}
    .acs-noc-head-right{width:100%;flex-wrap:wrap}
    .acs-kpi-grid,.acs-noc-grid{grid-template-columns:1fr}
    .acs-network-body{grid-template-columns:1fr}
}

</style>


<div class="jrc-dashboard">


<?php if (!$genieacsConfigured): ?>


    <div class="alert alert-warning">

        <i class="bi bi-exclamation-triangle"></i>

        O GenieACS ainda não foi configurado.

        Configure primeiro em

        <a href="/configuration.php">
            Configurações
        </a>.

    </div>


<?php else: ?>


    <!-- =====================================================
         DASHBOARD ACS - MODELO NOC
         ===================================================== -->

    <div class="acs-noc-head">
        <div class="acs-noc-head-left">
            <div class="acs-noc-head-icon"><i class="bi bi-activity"></i></div>
            <div>
                <h2>Visão Geral</h2>
                <p>Saúde da rede e equipamentos gerenciados pelo JR CONECT ACS</p>
            </div>
        </div>
        <div class="acs-noc-head-right">
            <span class="jrc-live-status"><span class="jrc-live-dot"></span> ACS OPERACIONAL</span>
            <button type="button" class="jrc-action" onclick="loadDashboardData();loadUplinkData();loadRecentDevices();">
                <i class="bi bi-arrow-clockwise"></i> Atualizar dados
            </button>
        </div>
    </div>

    <div class="acs-kpi-grid">
        <section class="acs-kpi-card">
            <div class="acs-kpi-icon cyan"><i class="bi bi-hdd-network"></i></div>
            <div><span>Total de equipamentos</span><strong id="stat-total">-</strong><small>Cadastrados no ACS</small></div>
        </section>

        <section class="acs-kpi-card">
            <div class="acs-kpi-icon green"><i class="bi bi-wifi"></i></div>
            <div><span>Equipamentos online</span><strong id="stat-online">-</strong><small>Conectados agora</small></div>
        </section>

        <section class="acs-kpi-card">
            <div class="acs-kpi-icon red"><i class="bi bi-wifi-off"></i></div>
            <div><span>Equipamentos offline</span><strong id="stat-offline">-</strong><small>Sem comunicação</small></div>
        </section>

        <section class="acs-kpi-card">
            <div class="acs-kpi-icon blue"><i class="bi bi-activity"></i></div>
            <div><span>Disponibilidade</span><strong id="stat-uptime">-</strong><small>Percentual online</small></div>
        </section>
    </div>

    <div class="acs-noc-grid">
        <section class="jrc-panel acs-network-status">
            <div class="jrc-panel-header">
                <div class="jrc-panel-title">
                    <i class="bi bi-diagram-3"></i>
                    <div><strong>Status da Rede</strong><small>Visão geral da disponibilidade</small></div>
                </div>
                <a href="/devices.php" class="jrc-action">Equipamentos <i class="bi bi-arrow-up-right"></i></a>
            </div>
            <div class="jrc-panel-body acs-network-body">
                <div class="acs-network-chart">
                    <div class="jrc-chart-box"><canvas id="deviceChart"></canvas></div>
                </div>
                <div class="acs-network-summary">
                    <div class="acs-network-summary-row online">
                        <span><i></i> Online</span><strong id="summary-online">-</strong>
                    </div>
                    <div class="acs-network-summary-row offline">
                        <span><i></i> Offline</span><strong id="summary-offline">-</strong>
                    </div>
                    <div class="acs-network-summary-row">
                        <span><i></i> Total</span><strong id="summary-total">-</strong>
                    </div>
                    <div class="acs-network-summary-row">
                        <span><i></i> Disponibilidade</span><strong id="summary-availability">-</strong>
                    </div>
                </div>
            </div>
        </section>

        <section class="jrc-panel acs-optical-health">
            <div class="jrc-panel-header">
                <div class="jrc-panel-title">
                    <i class="bi bi-reception-4"></i>
                    <div><strong>Saúde da rede óptica</strong><small>Distribuição do sinal PON</small></div>
                </div>
                <button type="button" class="jrc-action" onclick="loadUplinkData()"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>
            </div>
            <div class="jrc-panel-body">
                <div class="jrc-chart-box acs-optical-chart"><canvas id="uplinkChart"></canvas></div>
            </div>
        </section>


    </div>

    <section class="jrc-panel jrc-recent-panel acs-recent-modern">
        <div class="jrc-panel-header">
            <div class="jrc-panel-title">
                <i class="bi bi-pc-display"></i>
                <div><strong>Equipamentos recentes</strong><small>Últimos equipamentos visualizados ou com alteração de status</small></div>
            </div>
            <a href="/devices.php" class="jrc-action">Ver todos <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="jrc-recent-body">
            <div id="recent-devices"><div class="spinner"></div></div>
        </div>
    </section>

<?php endif; ?>


</div>



<!-- =========================================================
     MODAL - SOLICITAR COMUNICAÇÃO
     ========================================================= -->

<div
    class="modal fade"
    id="summonModal"
    tabindex="-1"
>


    <div class="modal-dialog modal-dialog-centered">


        <div class="modal-content">


            <div class="modal-header">


                <h5 class="modal-title">

                    <i class="bi bi-lightning-charge"></i>

                    Confirmar solicitação ao equipamento

                </h5>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>


            </div>


            <div class="modal-body text-center py-4">


                <i
                    class="bi bi-exclamation-triangle"
                    style="
                        font-size:3rem;
                        color:var(--warning-color);
                    "
                ></i>


                <h5 class="mt-3">

                    Solicitar comunicação com o equipamento?

                </h5>


                <p class="text-muted mb-0">

                    Deseja realmente enviar uma solicitação
                    de conexão para este equipamento?

                </p>


                <p class="text-muted mb-0">

                    <small>

                        ID do equipamento:

                        <strong id="summon-device-id"></strong>

                    </small>

                </p>


            </div>


            <div class="modal-footer">


                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >

                    <i class="bi bi-x-lg"></i>

                    Cancelar

                </button>


                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="confirmSummon()"
                >

                    <i class="bi bi-lightning-charge"></i>

                    Sim, solicitar

                </button>


            </div>


        </div>


    </div>


</div>



<!-- =========================================================
     MODAL - NÃO CADASTRADO NO MAPA
     ========================================================= -->

<div
    class="modal fade"
    id="notInMapModal"
    tabindex="-1"
>


    <div class="modal-dialog modal-dialog-centered">


        <div class="modal-content">


            <div class="modal-header">


                <h5 class="modal-title">

                    <i class="bi bi-exclamation-circle"></i>

                    ONU não cadastrada

                </h5>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>


            </div>


            <div class="modal-body text-center py-4">


                <i
                    class="bi bi-map"
                    style="
                        font-size:3rem;
                        color:var(--secondary-color);
                    "
                ></i>


                <h5 class="mt-3">

                    ONU não cadastrada no mapa

                </h5>


                <p class="text-muted mb-2">

                    O equipamento com número de série

                    <strong id="not-in-map-serial"></strong>

                    ainda não está cadastrado no mapa da rede.

                </p>


                <p class="text-muted mb-0">

                    <small>

                        Adicione esta ONU ao mapa para
                        visualizar sua localização na topologia.

                    </small>

                </p>


            </div>


            <div class="modal-footer">


                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >

                    <i class="bi bi-x-lg"></i>

                    Fechar

                </button>


                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="window.open('/map.php', '_blank')"
                >

                    <i class="bi bi-map"></i>

                    Abrir mapa da rede

                </button>


            </div>


        </div>


    </div>


</div>



<script
    src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
></script>


<script>

let deviceChart = null;
let uplinkChart = null;

let dashboardFetchInProgress = false;
let uplinkFetchInProgress = false;
let recentDevicesFetchInProgress = false;


/* ==========================================================
   ESTATÍSTICAS
   ========================================================== */

async function loadDashboardData() {

    if (dashboardFetchInProgress) {

        console.debug(
            '[DASHBOARD] Consulta de estatísticas já em andamento...'
        );

        return;
    }

    dashboardFetchInProgress = true;

    try {

        const result = await fetchAPI(
            '/api/dashboard-stats.php',
            {
                timeout: 25000
            }
        );


        if (
            result &&
            result.success
        ) {

            const stats = result.stats;


            document
                .getElementById('stat-total')
                .textContent =
                stats.total;


            document
                .getElementById('stat-online')
                .textContent =
                stats.online;


            document
                .getElementById('stat-offline')
                .textContent =
                stats.offline;


            const onlinePercentage =
                stats.total > 0
                    ? Math.round(
                        (
                            stats.online /
                            stats.total
                        ) * 100
                    )
                    : 0;


            document
                .getElementById('stat-uptime')
                .textContent =
                onlinePercentage + '%';

            const summaryTotal = document.getElementById('summary-total');
            const summaryOnline = document.getElementById('summary-online');
            const summaryOffline = document.getElementById('summary-offline');
            const summaryAvailability = document.getElementById('summary-availability');
            if (summaryTotal) summaryTotal.textContent = stats.total;
            if (summaryOnline) summaryOnline.textContent = stats.online;
            if (summaryOffline) summaryOffline.textContent = stats.offline;
            if (summaryAvailability) summaryAvailability.textContent = onlinePercentage + '%';


            /*
             * Atualizar barra da disponibilidade.
             */

            const progress =
                document.getElementById(
                    'availability-progress'
                );


            if (progress) {

                progress.style.width =
                    onlinePercentage + '%';

            }


            updateChart(stats);


        } else {


            if (
                result &&
                result.error !== 'timeout'
            ) {

                showToast(
                    'Falha ao carregar os dados do dashboard',
                    'danger'
                );

            }


        }


    } catch (error) {


        if (
            window.location.hostname === 'localhost' ||
            window.location.hostname === '127.0.0.1'
        ) {

            console.error(
                'Erro ao carregar o dashboard:',
                error
            );

        }


    } finally {

        dashboardFetchInProgress =
            false;

    }

}


/* ==========================================================
   GRÁFICO DOS EQUIPAMENTOS
   ========================================================== */

function updateChart(stats) {


    const canvas =
        document.getElementById(
            'deviceChart'
        );


    if (!canvas) {
        return;
    }


    const ctx =
        canvas.getContext('2d');


    if (deviceChart) {

        deviceChart.destroy();

    }


    deviceChart =
        new Chart(
            ctx,
            {

                type:
                    'doughnut',

                data: {

                    labels: [
                        'Online',
                        'Offline'
                    ],

                    datasets: [{

                        data: [
                            stats.online,
                            stats.offline
                        ],

                        backgroundColor: [
                            'rgba(32, 213, 155, 0.88)',
                            'rgba(240, 100, 114, 0.88)'
                        ],

                        borderColor: [
                            'rgba(32, 213, 155, 1)',
                            'rgba(240, 100, 114, 1)'
                        ],

                        borderWidth:
                            1

                    }]

                },


                options: {

                    responsive:
                        true,

                    maintainAspectRatio:
                        true,

                    cutout:
                        '72%',

                    plugins: {


                        legend: {

                            position:
                                'bottom',

                            labels: {

                                boxWidth:
                                    8,

                                padding:
                                    14,

                                color:
                                    '#7994a6',

                                font: {

                                    size:
                                        9

                                }

                            }

                        },


                        title: {

                            display:
                                false

                        }


                    }

                }

            }
        );

}


/* ==========================================================
   SINAL ÓPTICO
   ========================================================== */

async function loadUplinkData() {


    if (uplinkFetchInProgress) {

        console.debug(
            '[DASHBOARD] Consulta de sinal já em andamento...'
        );

        return;

    }


    uplinkFetchInProgress =
        true;


    try {


        const result =
            await fetchAPI(
                '/api/uplink-stats.php',
                {
                    timeout:
                        25000
                }
            );


        if (
            result &&
            result.success
        ) {


            updateUplinkChart(
                result.data
            );


        } else {


            if (
                result &&
                result.error !== 'timeout'
            ) {

                showToast(
                    'Falha ao carregar os dados de sinal',
                    'danger'
                );

            }


        }


    } catch (error) {


        if (
            window.location.hostname === 'localhost' ||
            window.location.hostname === '127.0.0.1'
        ) {


            console.error(
                'Erro ao carregar dados de sinal:',
                error
            );


        }


    } finally {


        uplinkFetchInProgress =
            false;


    }

}


/* ==========================================================
   GRÁFICO DO SINAL
   ========================================================== */

function updateUplinkChart(data) {


    const canvas =
        document.getElementById(
            'uplinkChart'
        );


    if (!canvas) {
        return;
    }


    const ctx =
        canvas.getContext('2d');


    if (uplinkChart) {

        uplinkChart.destroy();

    }


    uplinkChart =
        new Chart(
            ctx,
            {

                type:
                    'doughnut',

                data: {

                    labels: [
                        'Excelente',
                        'Bom',
                        'Regular',
                        'Ruim',
                        'Sem sinal'
                    ],


                    datasets: [{

                        data: [
                            data.excellent,
                            data.good,
                            data.fair,
                            data.poor,
                            data.no_signal
                        ],


                        backgroundColor: [
                            'rgba(32, 213, 155, .88)',
                            'rgba(0, 174, 220, .88)',
                            'rgba(246, 185, 74, .88)',
                            'rgba(240, 100, 114, .88)',
                            'rgba(117, 139, 152, .75)'
                        ],


                        borderColor: [
                            'rgba(32, 213, 155, 1)',
                            'rgba(0, 174, 220, 1)',
                            'rgba(246, 185, 74, 1)',
                            'rgba(240, 100, 114, 1)',
                            'rgba(117, 139, 152, 1)'
                        ],


                        borderWidth:
                            1

                    }]

                },


                options: {

                    responsive:
                        true,

                    maintainAspectRatio:
                        true,

                    cutout:
                        '72%',


                    plugins: {


                        legend: {


                            position:
                                'bottom',


                            labels: {


                                boxWidth:
                                    7,

                                padding:
                                    8,

                                color:
                                    '#7894a5',


                                font: {

                                    size:
                                        8

                                }


                            }


                        },


                        title: {

                            display:
                                false

                        }


                    }


                }


            }
        );

}


/* ==========================================================
   EXTRAIR IP
   ========================================================== */

function extractIP(ipString) {


    if (
        !ipString ||
        ipString === 'N/A'
    ) {

        return 'N/A';

    }


    const match =
        ipString.match(
            /(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/
        );


    return match
        ? match[1]
        : 'N/A';

}


/* ==========================================================
   EQUIPAMENTOS RECENTES
   ========================================================== */

async function loadRecentDevices() {


    if (
        recentDevicesFetchInProgress
    ) {

        console.debug(
            '[DASHBOARD] Consulta de equipamentos recentes já em andamento...'
        );

        return;

    }


    const container =
        document.getElementById(
            'recent-devices'
        );


    if (!container) {
        return;
    }


    container.innerHTML =
        '<div class="spinner"></div>';


    recentDevicesFetchInProgress =
        true;


    try {


        const result =
            await fetchAPI(
                '/api/recent-devices.php',
                {
                    timeout:
                        25000
                }
            );


        if (
            result &&
            result.success
        ) {


            const devices =
                result.devices;


            if (
                devices.length === 0
            ) {


                container.innerHTML =
                    '<p class="text-center text-muted">Nenhuma atividade recente encontrada</p>';


                return;

            }


            /*
             * Verificar cadastro no mapa.
             */

            const mapStatusPromises =
                devices.map(
                    device =>


                        fetchAPI(

                            '/api/get-onu-location.php?serial_number=' +

                            encodeURIComponent(
                                device.serial_number
                            )

                        )


                        .then(
                            result => ({


                                serial:
                                    device.serial_number,


                                inMap:
                                    result &&
                                    result.success &&
                                    result.location &&
                                    result.location.found,


                                itemType:
                                    result?.location?.item_type ||
                                    'onu',


                                itemId:
                                    result?.location?.onu?.id ||
                                    result?.location?.server?.id ||
                                    null


                            })
                        )


                        .catch(
                            () => ({


                                serial:
                                    device.serial_number,


                                inMap:
                                    false,


                                itemType:
                                    'onu',


                                itemId:
                                    null


                            })
                        )

                );


            const mapStatuses =
                await Promise.all(
                    mapStatusPromises
                );


            const mapStatusMap =
                {};


            mapStatuses.forEach(
                status => {


                    mapStatusMap[
                        status.serial
                    ] = {


                        inMap:
                            status.inMap,


                        itemType:
                            status.itemType,


                        itemId:
                            status.itemId


                    };


                }
            );


            /*
             * Criar tabela.
             */

            let html =
                '<div class="table-responsive">' +
                '<table class="table table-hover">' +
                '<thead><tr>';


            html += '<th>Serial</th>';
            html += '<th>MAC</th>';
            html += '<th>Modelo</th>';
            html += '<th>IP</th>';
            html += '<th>SSID</th>';
            html += '<th>PPPoE</th>';
            html += '<th>RX</th>';
            html += '<th>Temperatura</th>';
            html += '<th>Clientes</th>';
            html += '<th>Status</th>';
            html += '<th>Ações</th>';


            html +=
                '</tr></thead><tbody>';



            devices.forEach(
                device => {


                    const mapInfo =
                        mapStatusMap[
                            device.serial_number
                        ] || {

                            inMap:
                                false,

                            itemType:
                                'onu',

                            itemId:
                                null

                        };


                    const isInMap =
                        mapInfo.inMap;


                    const ipAddress =
                        extractIP(
                            device.ip_tr069
                        );


                    /*
                     * IP.
                     */

                    let ipDisplay;


                    if (
                        ipAddress !== 'N/A' &&
                        ipAddress !== ''
                    ) {


                        ipDisplay =
                            `<a href="http://${ipAddress}" target="_blank" rel="noopener noreferrer">${ipAddress}</a>`;


                    } else {


                        ipDisplay =
                            ipAddress;


                    }


                    /*
                     * Clientes.
                     */

                    const clientsCount =
                        device.connected_devices_count ||
                        0;


                    let clientsBadge;


                    if (
                        clientsCount > 0
                    ) {


                        clientsBadge =
                            `<span class="badge bg-primary">${clientsCount}</span>`;


                    } else {


                        clientsBadge =
                            '<span class="badge bg-secondary">0</span>';


                    }


                    /*
                     * Potência RX.
                     */

                    const rxPower =
                        parseFloat(
                            device.rx_power
                        );


                    let rxBadgeClass =
                        'bg-secondary';


                    let rxDisplay =
                        device.rx_power;


                    if (
                        !isNaN(rxPower) &&
                        rxPower !== -999
                    ) {


                        if (
                            rxPower > -20
                        ) {


                            rxBadgeClass =
                                'bg-success';


                        } else if (
                            rxPower >= -23
                        ) {


                            rxBadgeClass =
                                'bg-warning';


                        } else {


                            rxBadgeClass =
                                'bg-danger';


                        }


                        rxDisplay =
                            `<span class="badge ${rxBadgeClass}">${device.rx_power} dBm</span>`;


                    } else {


                        rxDisplay =
                            `<span class="badge ${rxBadgeClass}">N/D</span>`;


                    }


                    /*
                     * Status.
                     */

                    let statusBadge;


                    if (
                        device.status === 'online'
                    ) {


                        const ping =
                            device.ping ||
                            '-';


                        statusBadge =
                            `<span class="badge online">ONLINE [${ping}ms]</span>`;


                    } else {


                        statusBadge =
                            '<span class="badge offline">OFFLINE [-]</span>';


                    }


                    /*
                     * Mapa.
                     */

                    let mapButton;


                    if (
                        isInMap
                    ) {


                        let mapUrl;


                        if (
                            mapInfo.itemType ===
                            'mikrotik'
                        ) {


                            mapUrl =
                                `/map.php?focus_type=server&focus_id=${mapInfo.itemId}`;


                        } else {


                            mapUrl =
                                `/map.php?focus_type=onu&focus_serial=${encodeURIComponent(device.serial_number)}`;


                        }


                        mapButton =
                            `<button class="btn btn-sm btn-success me-1" onclick="window.open('${mapUrl}', '_blank')" title="Visualizar no mapa"><i class="bi bi-map"></i></button>`;


                    } else {


                        mapButton =
                            `<button class="btn btn-sm btn-secondary me-1" onclick="showNotInMapAlert('${encodeURIComponent(device.serial_number)}')" title="Não cadastrada no mapa"><i class="bi bi-map"></i></button>`;


                    }


                    /*
                     * Linha.
                     */

                    html += '<tr>';


                    html +=
                        `<td><a href="/device-detail.php?id=${encodeURIComponent(device.device_id)}">${device.serial_number}</a></td>`;


                    html +=
                        `<td>${device.mac_address || 'N/D'}</td>`;


                    html +=
                        `<td>${device.product_class || 'N/D'}</td>`;


                    html +=
                        `<td>${ipDisplay}</td>`;


                    html +=
                        `<td>${device.wifi_ssid || 'N/D'}</td>`;


                    html +=
                        `<td>${device.pppoe_username || 'N/D'}</td>`;


                    html +=
                        `<td>${rxDisplay}</td>`;


                    html +=
                        `<td>${device.temperature || 'N/A'}°C</td>`;


                    html +=
                        `<td class="text-center">${clientsBadge}</td>`;


                    html +=
                        `<td>${statusBadge}</td>`;


                    html +=
                        '<td>';


                    html +=
                        mapButton;


                    html +=
                        `<button class="btn btn-sm btn-primary" onclick="summonDeviceQuick('${device.device_id}')" title="Solicitar comunicação"><i class="bi bi-lightning-charge"></i></button>`;


                    html +=
                        '</td>';


                    html +=
                        '</tr>';


                }
            );


            html +=
                '</tbody></table></div>';


            container.innerHTML =
                html;


        } else {


            if (
                result &&
                result.error !== 'timeout'
            ) {


                container.innerHTML =
                    '<p class="text-center text-danger">Falha ao carregar os equipamentos recentes</p>';


            } else {


                container.innerHTML =
                    '<p class="text-center text-warning">Tempo limite excedido. Atualize a página.</p>';


            }


        }


    } catch (error) {


        console.error(
            'Erro ao carregar equipamentos recentes:',
            error
        );


        container.innerHTML =
            '<p class="text-center text-danger">Erro ao carregar os dados</p>';


    } finally {


        recentDevicesFetchInProgress =
            false;


    }

}


/* ==========================================================
   SUMMON
   ========================================================== */

let currentSummonDeviceId =
    null;


function summonDeviceQuick(
    deviceId
) {


    currentSummonDeviceId =
        deviceId;


    document
        .getElementById(
            'summon-device-id'
        )
        .textContent =
        deviceId;


    const modal =
        new bootstrap.Modal(

            document.getElementById(
                'summonModal'
            ),

            {
                backdrop:
                    false
            }

        );


    modal.show();

}


/* ==========================================================
   ALERTA MAPA
   ========================================================== */

function showNotInMapAlert(
    serialNumber
) {


    document
        .getElementById(
            'not-in-map-serial'
        )
        .textContent =
        decodeURIComponent(
            serialNumber
        );


    const modal =
        new bootstrap.Modal(

            document.getElementById(
                'notInMapModal'
            ),

            {
                backdrop:
                    false
            }

        );


    modal.show();

}


/* ==========================================================
   CONFIRMAR SUMMON
   ========================================================== */

async function confirmSummon() {


    if (
        !currentSummonDeviceId
    ) {

        return;

    }


    const modal =
        bootstrap.Modal.getInstance(

            document.getElementById(
                'summonModal'
            )

        );


    modal.hide();


    showLoading();


    const result =
        await fetchAPI(

            '/api/summon-device.php',

            {

                method:
                    'POST',

                body:
                    JSON.stringify({

                        device_id:
                            currentSummonDeviceId

                    })

            }

        );


    hideLoading();


    if (
        result &&
        result.success
    ) {


        showToast(
            'Solicitação enviada com sucesso!',
            'success'
        );


    } else {


        showToast(

            result.message ||

            'Falha ao solicitar comunicação com o equipamento',

            'danger'

        );


    }


    currentSummonDeviceId =
        null;

}


/* ==========================================================
   INICIALIZAÇÃO
   ========================================================== */

document.addEventListener(

    'DOMContentLoaded',

    function() {


        <?php if ($genieacsConfigured): ?>


            loadDashboardData();

            loadUplinkData();

            loadRecentDevices();


            /*
             * Atualização automática a cada 30 segundos.
             */

            setInterval(

                () => {


                    loadDashboardData();

                    loadUplinkData();

                    loadRecentDevices();


                },

                30000

            );


        <?php endif; ?>


    }

);

</script>


<?php

include __DIR__ . '/views/layouts/footer.php';

?>