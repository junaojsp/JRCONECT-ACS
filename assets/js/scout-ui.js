function scoutEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

function scoutBlank(value) {
    const text = String(value ?? "").trim();
    if (!text || text === "N/A" || text === "N/D" || text === "null") {
        return "—";
    }
    return text;
}

function scoutRxBand(value) {
    const number = parseFloat(value);
    if (!Number.isFinite(number)) {
        return { key: "nodata", label: "Sem dado", pill: "is-muted" };
    }
    if (number >= -25) {
        return { key: "good", label: "Bom", pill: "is-good" };
    }
    if (number >= -28) {
        return { key: "weak", label: "Fraco", pill: "is-warn" };
    }
    return { key: "critical", label: "Crítico", pill: "is-bad" };
}

function scoutSignalHtml(value) {
    const band = scoutRxBand(value);
    const text = band.key === "nodata" ? "Sem dado" : numberText(value) + " dBm";
    return '<span class="fb-signal ' + band.pill + '"><i class="bi bi-circle-fill"></i>' + scoutEscape(text) + "</span>";
}

function numberText(value) {
    const number = parseFloat(value);
    if (!Number.isFinite(number)) return "—";
    return number.toFixed(2);
}

function scoutFormatUptime(seconds) {
    seconds = Number(seconds);
    if (!Number.isFinite(seconds) || seconds <= 0) return "—";
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    if (days > 0) return days + "d " + hours + "h";
    if (hours > 0) return hours + "h " + minutes + "min";
    return Math.max(1, minutes) + " min";
}

function scoutSeen(timestamp) {
    const value = Number(timestamp);
    if (!value) return "—";
    const diff = Math.max(0, Date.now() / 1000 - value);
    const days = Math.floor(diff / 86400);
    if (days >= 1) return days + " d";
    const hours = Math.floor(diff / 3600);
    if (hours >= 1) return hours + " h";
    return Math.max(1, Math.floor(diff / 60)) + " min";
}

function scoutDeviceTitle(device) {
    const parts = [device.manufacturer, device.product_class].filter(function (part) {
        return part && part !== "N/A" && part !== "N/D";
    });
    return parts.join(" ") || "Equipamento";
}

function scoutIpHtml(device) {
    const raw = device.ip_address && device.ip_address !== "N/A"
        ? device.ip_address
        : (typeof extractIP === "function" ? extractIP(device.ip_tr069) : device.ip_tr069);
    const ip = scoutBlank(raw);
    if (ip === "—") return "—";
    return '<a href="http://' + scoutEscape(ip) + '" target="_blank" rel="noopener noreferrer">' + scoutEscape(ip) + "</a>";
}

function scoutSetText(id, value) {
    const element = document.getElementById(id);
    if (element) element.textContent = value ?? "—";
}

function scoutApplyFleetSummary(summary) {
    summary = summary || {};
    scoutSetText("stat-total", summary.total);
    scoutSetText("stat-alerts", summary.alerts);
    scoutSetText("stat-weak", summary.weak);
    scoutSetText("stat-critical", summary.critical);
    scoutSetText("stat-nodata", summary.nodata);
    scoutSetText("opt-good", summary.good);
    scoutSetText("opt-weak", summary.weak);
    scoutSetText("opt-critical", summary.critical);
    scoutSetText("opt-nodata", summary.nodata);
    const badge = document.getElementById("nav-alert-count");
    if (!badge) return;
    const count = Number(summary.alerts || 0);
    badge.textContent = count > 99 ? "99+" : String(count);
    badge.hidden = count <= 0;
}

function scoutFleetTable(devices) {
    if (!devices || !devices.length) {
        return '<div class="fb-empty">Nenhum equipamento informado pelo ACS.</div>';
    }
    const rows = devices.map(function (device) {
        const href = "/device-detail.php?id=" + encodeURIComponent(device.device_id || "");
        return "<tr>" +
            '<td><a class="fb-equip" href="' + href + '"><span class="fb-equip-icon"><i class="bi bi-router"></i></span><span><strong>' +
            scoutEscape(scoutDeviceTitle(device)) + "</strong><small>" + scoutEscape(scoutBlank(device.serial_number)) +
            "</small></span></a></td>" +
            '<td class="fb-muted">' + scoutEscape(scoutBlank(device.pppoe_username)) + "</td>" +
            "<td>" + scoutIpHtml(device) + "</td>" +
            "<td>" + scoutSignalHtml(device.rx_power) + "</td>" +
            "<td>" + scoutEscape(scoutFormatUptime(device.uptime)) + "</td>" +
            '<td class="fb-muted">' + scoutEscape(scoutSeen(device.last_inform_timestamp)) + "</td>" +
            "</tr>";
    }).join("");

    return '<table class="table fb-table"><thead><tr>' +
        "<th>Equipamento</th><th>Cliente (PPPoE)</th><th>IP WAN</th><th>Sinal RX</th><th>Uptime</th><th>Visto</th>" +
        "</tr></thead><tbody>" + rows + "</tbody></table>";
}

function scoutCountSignals(devices) {
    const summary = { total: devices.length, weak: 0, critical: 0, good: 0, nodata: 0, offline: 0 };
    devices.forEach(function (device) {
        const band = scoutRxBand(device.rx_power).key;
        summary[band] += 1;
        if (device.status !== "online") summary.offline += 1;
    });
    return summary;
}

document.addEventListener("DOMContentLoaded", function () {
    const menu = document.getElementById("menuToggle");
    const sidebar = document.getElementById("sidebar");
    if (menu && sidebar) {
        menu.addEventListener("click", function () {
            sidebar.classList.toggle("active");
        });
    }
});
