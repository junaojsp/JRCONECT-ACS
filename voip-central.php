<?php
/**
 * Central VoIP - primeira etapa visual.
 * Estoque isolado; importacao manual autenticada. Sem escritas IXC.
 */
require_once __DIR__ . '/config/config.php';
requireLogin();

require_once __DIR__ . '/lib/voip-stock.php';
header('Cache-Control: no-store, private');
if (empty($_SESSION['voip_csrf'])) $_SESSION['voip_csrf'] = bin2hex(random_bytes(32));
$voipError = '';
$voipData = null;
$voipStore = new VoipStock();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $received = $_POST['csrf'] ?? null;
        if (!is_string($received) || !hash_equals($_SESSION['voip_csrf'], $received)) {
            throw new RuntimeException('Sessão expirada. Atualize a página.');
        }
        $input = $_POST['inventory'] ?? null;
        if (!is_string($input)) throw new RuntimeException('Importação inválida.');
        $voipStore->import($input, (string)$_SESSION['user_id']);
        $_SESSION['voip_flash'] = 'Estoque importado. Os vínculos com o IXC continuam pendentes.';
        header('Location: /voip-central.php', true, 303);
        exit;
    } catch (Throwable $e) { $voipError = $e instanceof JsonException ? 'Arquivo de estoque inválido; importação interrompida.' : $e->getMessage(); }
}
try { $voipData = $voipStore->read(); } catch (Throwable $e) { $voipError = 'Estoque indisponível. Verifique a instalação antes de importar.'; }
$voipRows = array_values($voipData['numbers'] ?? []);
$voipOccupied = count(array_filter($voipRows, static fn($r) => $r['status'] === 'ocupado'));
$voipReview = count($voipRows) - $voipOccupied;
$voipFlash = $_SESSION['voip_flash'] ?? '';
unset($_SESSION['voip_flash']);
function voipEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

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

.voip-page .voip-table-wrap{overflow-x:auto;margin-top:20px}
.voip-page .voip-table{width:100%;border-collapse:collapse}
.voip-page .voip-table th{background:#1b1f26;color:#bbc4d0;text-align:left;font-size:14px;padding:14px;text-transform:none;letter-spacing:normal}
.voip-page .voip-table td{color:#d8dde5;border-bottom:1px solid #2a2f37;padding:14px;font-size:15px}
.voip-page .voip-phone{white-space:nowrap;font-variant-numeric:tabular-nums}
.voip-page .voip-status{display:inline-block;border:1px solid #4a3923;border-radius:6px;padding:3px 9px;color:#e9c080;white-space:nowrap}
.voip-page .voip-import{margin-top:20px}
.voip-page summary{font-size:18px;font-weight:600;cursor:pointer;color:#f1f3f5}
.voip-page .voip-import p{margin:16px 0}
.voip-page textarea{display:block;width:100%;margin:10px 0 16px;padding:14px;border:1px solid #414955;border-radius:8px;background:#0b0d10;color:#eef2f6;font:15px/1.6 monospace;resize:vertical}
.voip-page button{background:#ca2222;color:white;border:0;border-radius:8px;padding:12px 18px;font-size:16px;cursor:pointer}
.voip-page button:disabled{opacity:.5;cursor:not-allowed}
.voip-page :is(button,textarea,summary):focus-visible{outline:2px solid #efadad;outline-offset:3px}
.voip-page .voip-note{margin:0 0 20px}
</style>
<section class="voip-page" aria-labelledby="voip-title">
    <div class="voip-heading">
        <div>
            <h2 id="voip-title"><i class="bi bi-telephone me-2" aria-hidden="true"></i>Central VoIP</h2>
            <p class="voip-muted mb-0">Gerencie as linhas, os vínculos e as solicitações de telefonia.</p>
        </div>
        <span class="voip-tag">Estoque · vínculo IXC pendente</span>
    </div>

    <?php if ($voipError !== ''): ?><div class="voip-note" role="alert"><?= voipEscape($voipError) ?></div><?php endif; ?>
    <?php if ($voipFlash !== ''): ?><div class="voip-note" role="status"><?= voipEscape($voipFlash) ?></div><?php endif; ?>
    <div class="voip-grid" aria-label="Resumo do estoque">
    <?php foreach ([
        ['Estoque VoiceCopy', count($voipRows), 'Números cadastrados'],
        ['Ocupados', $voipOccupied, 'Informados pela equipe; contrato ainda não vinculado'],
        ['A conferir', $voipReview, 'Não disponíveis para atribuição'],
        ['Disponíveis', 0, 'Liberação ainda não habilitada']
    ] as [$label, $count, $description]): ?>
        <section class="voip-card">
            <h3><?= voipEscape($label) ?></h3>
            <div class="voip-number"><?= $voipData === null ? '—' : (int)$count ?></div>
            <p><?= voipEscape($description) ?></p>
        </section>
    <?php endforeach; ?>
    </div>

    <section class="voip-card">
        <h3 class="voip-section-title">Números e titulares</h3>
        <p>Ocupação informada pela equipe. A validação dos contratos e do produto 74 será feita na próxima etapa.</p>
        <div class="voip-table-wrap">
        <table class="voip-table">
            <thead><tr><th>Número</th><th>Titular informado</th><th>Situação</th><th>Contrato IXC</th></tr></thead>
            <tbody>
            <?php foreach ($voipRows as $row): ?>
            <tr>
                <td class="voip-phone"><?= voipEscape('('.substr($row['number'],0,2).') '.substr($row['number'],2,4).'-'.substr($row['number'],6)) ?></td>
                <td><?= voipEscape($row['holder'] ?: 'Não informado') ?></td>
                <td><span class="voip-status"><?= $row['status'] === 'ocupado' ? 'Ocupado' : 'A conferir' ?></span></td>
                <td>Pendente de vinculação</td>
            </tr>
            <?php endforeach; ?>
            <?php if ($voipData !== null && !$voipRows): ?><tr><td colspan="4">Nenhum número importado. Use a importação abaixo.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>

    <details class="voip-card voip-import">
        <summary>Importar números</summary>
        <p>Uma linha por número: <code>número;ocupado;nome</code> ou <code>número;a_conferir;</code>. Uma nova importação não substitui cadastros existentes.</p>
        <form method="post" action="/voip-central.php">
            <input type="hidden" name="csrf" value="<?= voipEscape($_SESSION['voip_csrf']) ?>">
            <label for="voip-inventory">Lista de números e titulares</label>
            <textarea id="voip-inventory" name="inventory" rows="10" maxlength="100000" required spellcheck="false"></textarea>
            <button type="submit" <?= $voipData === null ? 'disabled' : '' ?>>Importar estoque</button>
        </form>
    </details>

    <details class="voip-card voip-import">
        <summary>Histórico de importação</summary>
        <div class="voip-table-wrap"><table class="voip-table">
        <thead><tr><th>Data (UTC)</th><th>Operador (ID)</th><th>Número</th><th>Ação</th></tr></thead><tbody>
        <?php foreach (array_reverse($voipData['audit'] ?? []) as $event): ?>
        <tr><td><?= voipEscape($event['at']) ?></td><td><?= voipEscape($event['actor']) ?></td><td><?= voipEscape($event['number']) ?></td><td>Importação</td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </details>
</section>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
