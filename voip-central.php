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
/* Estilos restritos a esta pagina; o layout global do ACS permanece intacto. */
.voip-page{display:block;width:100%;max-width:none;margin:0;padding:8px 0 24px;background:transparent;color:#f1f3f5;font-size:16px;line-height:1.6}
.voip-page *{box-sizing:border-box}
.voip-page .voip-heading{display:flex;align-items:center;justify-content:space-between;gap:24px;margin:0 0 28px;flex-wrap:wrap}
.voip-page .voip-heading h2{color:#f5f6f7;font-size:28px;font-weight:700;line-height:1.25;margin:0 0 8px}
.voip-page .voip-muted{color:#a6adb8;font-size:16px;margin:0}
.voip-page .voip-tag{display:inline-flex;align-items:center;gap:8px;border:1px solid #4a3923;background:#251f17;color:#e9c080;border-radius:8px;padding:8px 14px;font-size:14px;font-weight:600;white-space:nowrap}
.voip-page .voip-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;margin:0 0 28px}
.voip-page .voip-card{min-width:0;background:#14171c;border:1px solid #2a2f37;border-radius:12px;padding:26px;box-shadow:0 4px 16px #00000012}
.voip-page .voip-grid .voip-card{display:flex;flex-direction:column;border-top:3px solid #d72a2a}
.voip-page .voip-card>i{display:grid;place-items:center;width:44px;height:44px;border-radius:10px;background:#2c1a1e;color:#ff9393;font-size:22px}
.voip-page .voip-card h3{color:#f1f3f5;font-size:18px;font-weight:650;line-height:1.4;margin:18px 0 8px}
.voip-page .voip-card p{font-size:15px;color:#a6adb8;line-height:1.65;margin:0}
.voip-page .voip-number{font-size:36px;font-weight:650;line-height:1;margin:24px 0 12px;color:#d8dde5}
.voip-page .voip-grid .voip-card p:last-child{margin-top:auto;padding-top:16px;border-top:1px solid #2a2f37;font-size:14px;color:#969faa}
.voip-page .voip-card .voip-section-title{font-size:20px;margin:0 0 24px}
.voip-page .voip-workflow{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:28px}
.voip-page .voip-step{display:flex;align-items:flex-start;gap:14px;min-width:0}
.voip-page .voip-step strong{display:block;color:#e9edf1;font-size:16px;margin-bottom:6px}
.voip-page .voip-step span{font-size:15px;color:#a6adb8;line-height:1.65}
.voip-page .voip-step-icon{display:grid;place-items:center;flex:none;background:#22262e;border:1px solid #343b46;border-radius:10px;width:44px;height:44px}
.voip-page .voip-step-icon i{color:#c3cbd6;font-size:20px}
.voip-page .voip-note{display:flex;align-items:center;gap:10px;border:1px solid #2a2f37;background:#111419;border-radius:10px;padding:16px 20px;color:#a6adb8;font-size:14px;line-height:1.65;margin-top:24px}
.voip-page .voip-note i{color:#b4bdca;flex:none}
@media(max-width:1200px){.voip-page .voip-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.voip-page .voip-workflow{grid-template-columns:1fr;gap:22px}}
@media(max-width:600px){.voip-page .voip-grid{grid-template-columns:1fr;gap:16px}.voip-page .voip-card{padding:22px}.voip-page .voip-heading h2{font-size:24px}.voip-page .voip-heading{gap:16px}.voip-page .voip-note{align-items:flex-start}}
</style>
<section class="voip-page" aria-labelledby="voip-title">
    <div class="voip-heading">
        <div>
            <h2 id="voip-title"><i class="bi bi-telephone me-2" aria-hidden="true"></i>Central VoIP</h2>
            <p class="voip-muted mb-0">Gerencie as linhas, os vínculos e as solicitações de telefonia.</p>
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
    </section>

    <div class="voip-note" role="status">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>Central em preparação. Os indicadores e as operações serão disponibilizados após a integração dos dados.</span>
    </div>
</section>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
