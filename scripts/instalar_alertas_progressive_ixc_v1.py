#!/usr/bin/env python3
"""Optimize alerts IXC enrichment without touching Dashboard or Devices."""
import argparse
import datetime
import fcntl
import os
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

TARGET = "assets/js/jrc-alertas.js"
MARKER = "JRC_ALERTS_PROGRESSIVE_IXC_V1"

NEW_LOAD_SIGNAL = r"""function loadSignal() {
    /* JRC_ALERTS_PROGRESSIVE_IXC_V1 */
    recallIxc();

    // Libera a tela imediatamente com os dados locais/TR-069.
    signalReady = true;
    render();

    var generation = (window.__jrcAlertsIxcGeneration || 0) + 1;
    window.__jrcAlertsIxcGeneration = generation;

    var serials = [];
    var seen = {};

    devices.forEach(function (device) {
        var serial = String(device.serial_number || '').trim();
        var key = normSerial(serial);

        if (!key || seen[key] || ixcFresh(serial)) return;

        seen[key] = 1;
        serials.push(serial);
    });

    if (!serials.length) {
        return Promise.resolve();
    }

    // Menos carga inicial: lotes menores e sequenciais.
    var batches = [];
    for (var offset = 0; offset < serials.length; offset += 50) {
        batches.push(serials.slice(offset, offset + 50));
    }

    var cursor = 0;
    var renderTimer = null;

    function scheduleRender() {
        if (renderTimer) clearTimeout(renderTimer);
        renderTimer = setTimeout(function () {
            if (generation !== window.__jrcAlertsIxcGeneration) return;
            render();
        }, 120);
    }

    function nextBatch() {
        if (generation !== window.__jrcAlertsIxcGeneration) return;
        if (cursor >= batches.length) {
            scheduleRender();
            return;
        }

        var batch = batches[cursor++];

        postJson('/api/get-devices-ixc-batch.php', {
            serial_numbers: batch
        }).then(function (res) {
            if (generation !== window.__jrcAlertsIxcGeneration) return;

            if (res && res.success !== false && res.devices) {
                absorbIxc(res.devices);
                rememberIxc(res.devices);
                scheduleRender();
            }
        }).catch(function (error) {
            console.warn('[ALERTAS] Complemento IXC em background falhou:', error);
        }).then(function () {
            if (generation !== window.__jrcAlertsIxcGeneration) return;

            // Cede CPU/rede entre lotes para manter a interface responsiva.
            setTimeout(nextBatch, 180);
        });
    }

    function startBackground() {
        if (generation !== window.__jrcAlertsIxcGeneration) return;

        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(nextBatch, { timeout: 1200 });
        } else {
            setTimeout(nextBatch, 0);
        }
    }

    // Pequeno atraso para a página terminar de pintar antes do IXC.
    setTimeout(startBackground, 650);

    // Nao bloqueia o boot da tela aguardando todos os lotes IXC.
    return Promise.resolve();
}"""

def run(cmd):
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout or '')[:3000])
    return p

def node_check(text: str):
    node = shutil.which("node")
    if not node:
        raise RuntimeError("Node.js nao encontrado para validar JavaScript.")

    with tempfile.NamedTemporaryFile("w", suffix=".js", delete=False, encoding="utf-8") as f:
        f.write(text)
        tmp = f.name

    try:
        run([node, "--check", tmp])
    finally:
        os.unlink(tmp)

def find_function(text: str, name: str):
    import re
    m = re.search(r"\bfunction\s+" + re.escape(name) + r"\s*\([^)]*\)\s*\{", text)
    if not m:
        raise RuntimeError("Funcao " + name + "() nao encontrada.")

    brace_start = text.find("{", m.start())
    depth = 0
    quote = None
    escape = False
    i = brace_start

    while i < len(text):
        ch = text[i]

        if quote is not None:
            if escape:
                escape = False
            elif ch == "\\":
                escape = True
            elif ch == quote:
                quote = None
            i += 1
            continue

        if ch in ("'", '"') or ch == chr(96):
            quote = ch
            i += 1
            continue

        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                return m.start(), i + 1

        i += 1

    raise RuntimeError("Fim da funcao " + name + "() nao encontrado.")

def patch(text: str):
    if MARKER in text:
        return text, 0

    required = [
        "function loadSignal",
        "/api/get-devices-ixc-batch.php",
        "recallIxc",
        "rememberIxc",
        "ixcFresh",
        "render",
    ]
    missing = [item for item in required if item not in text]
    if missing:
        raise RuntimeError(
            "Estrutura inesperada em jrc-alertas.js: " + ", ".join(missing)
        )

    start, end = find_function(text, "loadSignal")
    return text[:start] + NEW_LOAD_SIGNAL + text[end:], 1

def atomic_write(path: Path, data: bytes, meta):
    fd, tmp = tempfile.mkstemp(prefix=".jrc-alerts-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
            os.fchmod(f.fileno(), stat.S_IMODE(meta.st_mode))
            if os.geteuid() == 0:
                os.fchown(f.fileno(), meta.st_uid, meta.st_gid)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default="/var/www/gacs")
    ap.add_argument("--backup-dir", default="/root")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    backup_parent = Path(args.backup_dir).resolve()
    target = root / TARGET

    if not target.is_file() or target.is_symlink():
        raise RuntimeError("Arquivo nao encontrado ou invalido: " + str(target))

    lock_path = backup_parent / ".jrc-alerts-progressive-ixc.lock"

    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        before = target.read_text(encoding="utf-8")
        after, changes = patch(before)

        node_check(after)

        print("VALIDADO: Alertas com IXC progressivo.")
        print("Dashboard e Dispositivos NAO serao alterados.")
        print("Alertas renderizam imediatamente com inventario local/TR-069.")
        print("IXC passa para background: lotes de 50, 1 por vez.")
        print("Interface nao aguardara o fim de todos os lotes IXC.")
        print("Cache IXC existente em sessionStorage sera preservado.")
        print(f"Alteracoes previstas: {changes}.")

        if args.dry_run:
            print("SIMULACAO OK: nenhum arquivo alterado.")
            return

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-alerts-progressive-" +
                   datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent),
        ))
        os.chmod(backup, 0o700)

        (backup / "assets/js").mkdir(parents=True, exist_ok=True)
        shutil.copy2(target, backup / TARGET)
        shutil.copy2(Path(__file__), backup / "install.py")

        if target.read_text(encoding="utf-8") != before:
            raise RuntimeError("jrc-alertas.js mudou durante a instalacao.")

        atomic_write(target, after.encode("utf-8"), target.stat())
        node_check(target.read_text(encoding="utf-8"))

        print("INSTALADO: Alertas com enriquecimento IXC progressivo.")
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
