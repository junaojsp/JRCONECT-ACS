<?php
/**
 * Central VoIP - primeira etapa visual.
 * Sem consultas IXC, jobs, operacoes de estoque ou efeitos colaterais.
 */
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Central VoIP';
$currentPage = 'voip-central';
include __DIR__ . '/views/layouts/header.php';
?>
<style>
.voip-page{color:#dcebf3;max-width:1360px;margin:auto}
.voip-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap}
.voip-heading h2{font-size:22px;font-weight:700;margin:0 0 6px}
.voip-muted{color:#87a0b1;font-size:13px}
.voip-tag{border:1px solid rgba(56,189,248,.28);background:rgba(56,189,248,.08);color:#7dd3fc;border-radius:24px;padding:7px 12px;font-size:12px;font-weight:600}
.voip-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:0 0 18px}
.voip-card{background:linear-gradient(145deg,rgba(11,27,41,.98),rgba(6,17,28,.98));border:1px solid rgba(106,159,187,.17);border-radius:13px;padding:18px}
.voip-card i{color:#35c5e4;font-size:20px}
.voip-card h3{font-size:13px;font-weight:700;margin:14px 0 5px}
.voip-card p{font-size:12px;color:#8da7b7;line-height:1.6;margin:0}
.voip-number{font-size:27px;font-weight:700;margin-top:13px}
.voip-section-title{font-size:16px;font-weight:700;margin:4px 0 15px}
.voip-workflow{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.voip-step{display:flex;align-items:flex-start;gap:10px}
.voip-step strong{display:block;color:#e7f4fa;font-size:13px;margin-bottom:5px}
.voip-step span{font-size:12px;color:#8da7b7;line-height:1.6}
.voip-step-icon{display:flex;align-items:center;justify-content:center;flex:none;background:rgba(53,197,228,.12);border-radius:9px;width:35px;height:35px}
.voip-note{border-left:3px solid #35c5e4;background:rgba(53,197,228,.05);border-radius:5px;padding:15px 17px;color:#9db7c7;font-size:13px;line-height:1.6;margin-top:20px}
.voip-empty{margin-top:16px;color:#8da7b7;font-size:13px}
@media(max-width:1080px){.voip-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.voip-workflow{grid-template-columns:1fr}}
@media(max-width:560px){.voip-grid{grid-template-columns:1fr}}
</style>
<main class="voip-page" aria-labelledby="voip-title">
    <div class="voip-heading">
        <div>
            <h2 id="voip-title"><i class="bi bi-telephone me-2" aria-hidden="true"></i>Central VoIP</h2>
            <p class="voip-muted mb-0">Gestão de números VoiceCopy vinculados aos contratos IXC com produto 74.</p>
        </div>
        <span class="voip-tag"><i class="bi bi-tools me-1" aria-hidden="true"></i> Em preparação</span>
    </div>

    <div class="voip-grid" aria-label="Módulos planejados">
        <section class="voip-card">
            <i class="bi bi-bell" aria-hidden="true"></i>
            <h3>Solicitações pendentes</h3>
            <p>Contratos com produto 74 que ainda precisam de número.</p>
            <div class="voip-number" aria-label="Quantidade ainda indisponível">—</div>
            <p>Aguardando integração individual do IXC.</p>
        </section>
        <section class="voip-card">
            <i class="bi bi-sim" aria-hidden="true"></i>
            <h3>Estoque VoiceCopy</h3>
            <p>Números disponíveis, reservados e ativos.</p>
            <div class="voip-number" aria-label="Quantidade ainda indisponível">—</div>
            <p>Importação e conciliação ainda não realizadas.</p>
        </section>
        <section class="voip-card">
            <i class="bi bi-link-45deg" aria-hidden="true"></i>
            <h3>Números vinculados</h3>
            <p>Associação manual de DID ao contrato do cliente.</p>
            <div class="voip-number" aria-label="Quantidade ainda indisponível">—</div>
            <p>Vinculação será habilitada numa próxima etapa.</p>
        </section>
        <section class="voip-card">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <h3>Cancelamentos e baixas</h3>
            <p>Revisão dos números antes de retornarem ao estoque.</p>
            <div class="voip-number" aria-label="Quantidade ainda indisponível">—</div>
            <p>Monitoramento automático ainda desativado.</p>
        </section>
    </div>

    <section class="voip-card">
        <h3 class="voip-section-title">Como funcionará a Central VoIP</h3>
        <div class="voip-workflow">
            <div class="voip-step">
                <div class="voip-step-icon"><i class="bi bi-cart-check" aria-hidden="true"></i></div>
                <div><strong>1. Contratação no IXC</strong><span>O suporte N1 cadastra o produto 74 no contrato do cliente.</span></div>
            </div>
            <div class="voip-step">
                <div class="voip-step-icon"><i class="bi bi-bell" aria-hidden="true"></i></div>
                <div><strong>2. Alerta de nova linha</strong><span>O MCP identificará registros sem número e abrirá uma pendência.</span></div>
            </div>
            <div class="voip-step">
                <div class="voip-step-icon"><i class="bi bi-telephone-plus" aria-hidden="true"></i></div>
                <div><strong>3. Vínculo supervisionado</strong><span>A equipe escolherá um DID e confirmará a ativação na VoiceCopy.</span></div>
            </div>
        </div>
        <p class="voip-empty mb-0">Histórico, alertas reais e ações de estoque serão liberados após validação da integração e do banco isolado.</p>
    </section>

    <div class="voip-note" role="status">
        <i class="bi bi-shield-check me-2" aria-hidden="true"></i>
        <strong>Ambiente protegido:</strong> esta é somente a estrutura visual inicial.
        Nenhum contrato, número, banco de dados, servidor MCP, equipamento ACS ou serviço VoiceCopy é alterado por esta página.
    </div>
</main>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
