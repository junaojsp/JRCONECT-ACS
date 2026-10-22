<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Alertas';
$currentPage = 'alerts';
$genieacsConfigured = isGenieACSConfigured();

include __DIR__ . '/views/layouts/header.php';
?>

<style>
.fb-page {
    color: #f3f5f7 !important;
    max-width: 1180px;
}
.fb-page * { box-sizing: border-box !important; }
.fb-page h1 {
    margin: 0 !important;
    color: #f7f8fa !important;
    font-size: 28px !important;
    font-weight: 650 !important;
    letter-spacing: -0.03em !important;
    line-height: 1.15 !important;
}
.fb-page .fb-page-head p {
    margin: 6px 0 0 !important;
    color: #8d939c !important;
    font-size: 14px !important;
}
.fb-stats {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 12px !important;
    margin: 18px 0 16px !important;
}
.fb-stat {
    display: block !important;
    background: #14161b !important;
    border: 1px solid #2a2d34 !important;
    border-radius: 16px !important;
    padding: 16px !important;
    min-height: 118px !important;
    box-shadow: none !important;
}
.fb-stat-icon {
    width: 34px !important;
    height: 34px !important;
    max-width: 34px !important;
    border-radius: 10px !important;
    display: grid !important;
    place-items: center !important;
    margin: 0 0 16px !important;
    background: #1c2030 !important;
    color: #8eb0ff !important;
    overflow: hidden !important;
}
.fb-stat-icon.is-red { background: #2a1518 !important; color: #ff5d5d !important; }
.fb-stat-icon.is-amber { background: #2c2416 !important; color: #f5b942 !important; }
.fb-stat-icon.is-doc { background: #241820 !important; color: #ff8f8f !important; }
.fb-stat-icon.is-ok { background: #14261c !important; color: #3ddc84 !important; }
.fb-stat-icon i {
    font-size: 16px !important;
    line-height: 1 !important;
    width: auto !important;
    height: auto !important;
    margin: 0 !important;
    background: transparent !important;
    border: 0 !important;
}
.fb-stat strong {
    display: block !important;
    color: #f7f8fa !important;
    font-size: 30px !important;
    font-weight: 650 !important;
    line-height: 1 !important;
    background: transparent !important;
    border: 0 !important;
}
.fb-stat span {
    display: block !important;
    margin-top: 6px !important;
    color: #8d939c !important;
    font-size: 13px !important;
}
.fb-filters {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 8px !important;
    margin: 0 0 14px !important;
    padding: 0 !important;
    background: transparent !important;
    border: 0 !important;
}
.fb-filters button {
    appearance: none !important;
    display: inline-flex !important;
    align-items: center !important;
    border: 1px solid #2c2f36 !important;
    background: #17191e !important;
    color: #c5cad3 !important;
    border-radius: 999px !important;
    padding: 7px 14px !important;
    font-size: 13px !important;
    line-height: 1.2 !important;
    text-decoration: none !important;
    box-shadow: none !important;
    cursor: pointer !important;
}
.fb-filters button.active {
    background: #3a1216 !important;
    border-color: #7a1d24 !important;
    color: #fff !important;
}
.fb-alert {
    display: grid !important;
    grid-template-columns: 36px minmax(0, 1fr) auto !important;
    gap: 14px !important;
    align-items: center !important;
    background: #14161b !important;
    border: 1px solid #2a2d34 !important;
    border-radius: 14px !important;
    padding: 14px 16px !important;
    margin: 0 0 10px !important;
    box-shadow: none !important;
}
.fb-equip-icon {
    width: 36px !important;
    height: 36px !important;
    max-width: 36px !important;
    border-radius: 10px !important;
    display: grid !important;
    place-items: center !important;
    background: #1c1f27 !important;
    color: #ff8d8d !important;
}
.fb-equip-icon i {
    font-size: 16px !important;
    line-height: 1 !important;
    width: auto !important;
    margin: 0 !important;
    background: transparent !important;
    border: 0 !important;
}
.fb-alert h3 {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 8px !important;
    align-items: center !important;
    margin: 0 0 4px !important;
    font-size: 14px !important;
    font-weight: 650 !important;
    color: #f3f5f7 !important;
}
.fb-alert h3 a {
    color: #f3f5f7 !important;
    text-decoration: none !important;
}
.fb-alert h3 a:hover { color: #fff !important; }
.fb-alert p {
    margin: 0 !important;
    color: #c5cad3 !important;
    font-size: 13px !important;
}
.fb-alert .fb-meta {
    margin-top: 4px !important;
    color: #8d939c !important;
    font-size: 12px !important;
}
.fb-chip {
    display: inline-flex !important;
    align-items: center !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    border-radius: 999px !important;
    padding: 2px 8px !important;
    background: #3a1518 !important;
    color: #ff6b6b !important;
    text-decoration: none !important;
}
.fb-chip.is-warn { background: #3a2e14 !important; color: #f5b942 !important; }
.fb-chip.is-ok { background: #143222 !important; color: #3ddc84 !important; }
.fb-alert .btn {
    background: #1a1d24 !important;
    border: 1px solid #32353d !important;
    color: #e8eaee !important;
    border-radius: 10px !important;
    padding: 6px 12px !important;
    font-size: 12px !important;
    text-decoration: none !important;
    box-shadow: none !important;
}
.fb-empty {
    padding: 28px 8px !important;
    color: #8d939c !important;
}
.fb-page hr { display: none !important; }
@media (max-width: 980px) {
    .fb-stats { grid-template-columns: 1fr 1fr !important; }
    .fb-alert { grid-template-columns: 36px minmax(0, 1fr) !important; }
    .fb-alert .btn { grid-column: 2 !important; justify-self: start !important; }
}
</style>

<div class="fb-page">
    <div class="fb-page-head">
        <div>
            <h1>Alertas & eventos</h1>
            <p>Sinal óptico, disponibilidade e saúde da frota — em tempo real.</p>
        </div>
    </div>

    <?php if (!$genieacsConfigured): ?>
        <div class="fb-banner">
            O GenieACS ainda não foi configurado.
            <a href="/configuration.php">Abrir configuração</a>.
        </div>
    <?php endif; ?>

    <div class="fb-stats">
        <article class="fb-stat">
            <div class="fb-stat-icon is-red"><i class="bi bi-x-circle"></i></div>
            <strong id="alert-critical">—</strong>
            <span>Críticos ativos</span>
        </article>
        <article class="fb-stat">
            <div class="fb-stat-icon is-amber"><i class="bi bi-exclamation-triangle"></i></div>
            <strong id="alert-warning">—</strong>
            <span>Avisos ativos</span>
        </article>
        <article class="fb-stat">
            <div class="fb-stat-icon is-doc"><i class="bi bi-info-circle"></i></div>
            <strong id="alert-info">0</strong>
            <span>Info ativos</span>
        </article>
        <article class="fb-stat">
            <div class="fb-stat-icon is-ok"><i class="bi bi-check-circle"></i></div>
            <strong id="alert-ack">0</strong>
            <span>Reconhecidos</span>
        </article>
    </div>

    <div class="fb-filters" id="alert-filters">
        <button type="button" class="active" data-filter="open">Abertos</button>
        <button type="button" data-filter="active">Ativos</button>
        <button type="button" data-filter="ack">Reconhecidos</button>
        <button type="button" data-filter="resolved">Resolvidos</button>
        <button type="button" data-filter="all">Todos</button>
    </div>

    <div id="alert-list">
        <div class="fb-empty">Carregando alertas…</div>
    </div>
</div>

<script>
const ALERT_KEY = "jrc-alert-ack";
let scoutAlerts = [];
let alertFilter = "open";

function readAck() {
    try {
        return JSON.parse(localStorage.getItem(ALERT_KEY) || "{}");
    } catch (error) {
        return {};
    }
}

function writeAck(map) {
    localStorage.setItem(ALERT_KEY, JSON.stringify(map));
}

function renderAlerts() {
    const ack = readAck();
    const list = document.getElementById("alert-list");
    const visible = scoutAlerts.filter(function (alert) {
        const known = Boolean(ack[alert.id]);
        if (alertFilter === "ack") return known;
        if (alertFilter === "resolved") return false;
        if (alertFilter === "all") return true;
        if (alertFilter === "active") return !known && alert.level === "critical";
        return !known;
    });

    const open = scoutAlerts.filter(alert => !ack[alert.id]);
    document.getElementById("alert-critical").textContent = open.filter(alert => alert.level === "critical").length;
    document.getElementById("alert-warning").textContent = open.filter(alert => alert.level === "warning").length;
    document.getElementById("alert-ack").textContent = scoutAlerts.filter(alert => ack[alert.id]).length;

    if (alertFilter === "resolved") {
        list.innerHTML = '<div class="fb-empty">Nenhum alerta resolvido ainda. Reconhecer tira o item da fila aberta.</div>';
        return;
    }
    if (!visible.length) {
        list.innerHTML = '<div class="fb-empty">Nenhum alerta nesta fila.</div>';
        return;
    }

    list.innerHTML = visible.map(function (alert) {
        const known = Boolean(ack[alert.id]);
        const chip = alert.level === "warning" ? "is-warn" : "is-bad";
        const label = alert.level === "warning" ? "Aviso" : "Crítico";
        const href = "/device-detail.php?id=" + encodeURIComponent(alert.device_id || "");
        const action = known
            ? '<span class="fb-chip is-ok">Reconhecido</span>'
            : '<button class="btn btn-sm btn-secondary" type="button" data-ack="' + scoutEscape(alert.id) + '">Reconhecer</button>';
        return '<article class="fb-alert">' +
            '<span class="fb-equip-icon"><i class="bi ' + (alert.level === "warning" ? "bi-exclamation-triangle" : "bi-x-circle") + '"></i></span>' +
            '<div class="fb-alert-body"><h3><a href="' + href + '">' + scoutEscape(alert.title) + '</a> <span class="fb-chip ' + chip + '">' + label + '</span></h3>' +
            '<p>' + scoutEscape(alert.detail) + '</p><div class="fb-meta">' + scoutEscape(alert.meta || "") + (known ? " · por " + scoutEscape(ack[alert.id]) : "") + '</div></div>' +
            action + '</article>';
    }).join("");
}

document.getElementById("alert-filters").addEventListener("click", function (event) {
    const button = event.target.closest("button[data-filter]");
    if (!button) return;
    alertFilter = button.dataset.filter;
    document.querySelectorAll("#alert-filters button").forEach(item => item.classList.toggle("active", item === button));
    renderAlerts();
});

document.getElementById("alert-list").addEventListener("click", function (event) {
    const button = event.target.closest("[data-ack]");
    if (!button) return;
    const ack = readAck();
    ack[button.dataset.ack] = <?php echo json_encode($_SESSION['username'] ?? 'admin'); ?>;
    writeAck(ack);
    renderAlerts();
});

document.addEventListener("DOMContentLoaded", async function () {
    if (!<?php echo $genieacsConfigured ? 'true' : 'false'; ?>) {
        document.getElementById("alert-list").innerHTML = '<div class="fb-empty">Conecte o GenieACS para montar a fila de alertas.</div>';
        return;
    }

    const devices = [];
    let skip = 0;
    let hasMore = true;
    while (hasMore && skip < 5000) {
        const result = await fetchAPI("/api/get-devices.php?limit=100&skip=" + skip, { timeout: 25000 });
        if (!result || !result.success) {
            document.getElementById("alert-list").innerHTML = '<div class="fb-empty">Não foi possível consultar a frota.</div>';
            return;
        }
        devices.push.apply(devices, result.devices || []);
        hasMore = !!result.hasMore;
        skip += 100;
    }

    scoutAlerts = devices.flatMap(function (device) {
        const band = scoutRxBand(device.rx_power);
        const seen = scoutSeen(device.last_inform_timestamp);
        const name = scoutDeviceTitle(device);
        const serial = device.serial_number || "";
        const meta = [serial, seen].filter(Boolean).join(" · ");
        const items = [];
        if (device.status !== "online") {
            items.push({
                id: (device.device_id || serial) + "|offline",
                level: "critical",
                title: "Equipamento sem contato",
                detail: name + " não reporta há " + seen + ".",
                meta: meta,
                device_id: device.device_id || ""
            });
        }
        if (band.key === "weak" || band.key === "critical") {
            items.push({
                id: (device.device_id || serial) + "|" + band.key,
                level: band.key === "critical" ? "critical" : "warning",
                title: band.key === "critical" ? "Sinal óptico crítico" : "Sinal óptico fraco",
                detail: name + " está com RX em " + device.rx_power + " dBm — abaixo do ideal (-25 dBm).",
                meta: meta,
                device_id: device.device_id || ""
            });
        }
        return items;
    });

    const badge = document.getElementById("nav-alert-count");
    if (badge) {
        badge.textContent = String(scoutAlerts.length);
        badge.hidden = scoutAlerts.length === 0;
    }
    renderAlerts();
});
</script>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
