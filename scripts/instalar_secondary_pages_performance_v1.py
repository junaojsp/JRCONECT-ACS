#!/usr/bin/env python3
"""Optimize secondary ACS pages without touching Dashboard or Devices."""
import argparse
import datetime
import fcntl
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

PROVISION_FILE = "assets/js/provisionamento-onu.js"
LOGS_FILE = "view-logs.php"

HELPERS = r"""
/* JRC_SECONDARY_PERF_V1 */
function jrcProvisionNormalizeLocal(d) {
    return {
        acs: {
            device_id: d.device_id || '',
            serial_number: d.serial_number || '',
            product_class: d.product_class || '',
            last_inform: d.last_inform || '',
            status: d.status || ''
        },
        ixc: {
            nome: '',
            pon_id: '',
            onu_numero: '',
            onu_tipo: ''
        }
    };
}

async function jrcProvisionLoadLocalInventory() {
    const out = [];
    let skip = 0;
    const limit = 500;

    while (true) {
        const response = await fetch(
            '/api/get-devices.php?limit=' + limit + '&skip=' + skip,
            {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }
        );

        const data = await response.json();

        if (!response.ok || !data || !data.success) {
            throw new Error((data && data.message) || 'Inventario local indisponivel');
        }

        const chunk = Array.isArray(data.devices) ? data.devices : [];
        out.push(...chunk.map(jrcProvisionNormalizeLocal));

        if (!data.hasMore || chunk.length === 0) break;

        skip += chunk.length;

        if (skip > 50000) {
            throw new Error('Limite de seguranca do inventario atingido');
        }
    }

    return out;
}

function jrcProvisionApplyDiscovery(data) {
    if (!data || !data.success) return false;

    const list = Array.isArray(data.devices) ? data.devices : [];
    if (!list.length) return false;

    devices = list;
    page = 1;
    render();
    return true;
}

function jrcProvisionReadDiscoveryCache() {
    try {
        const raw = sessionStorage.getItem('jrc.provision.discovery.v1');
        if (!raw) return null;

        const cached = JSON.parse(raw);
        if (!cached || !cached.savedAt || !cached.data) return null;

        if ((Date.now() - Number(cached.savedAt)) > (5 * 60 * 1000)) {
            sessionStorage.removeItem('jrc.provision.discovery.v1');
            return null;
        }

        return cached.data;
    } catch (e) {
        return null;
    }
}

function jrcProvisionSaveDiscoveryCache(data) {
    try {
        sessionStorage.setItem(
            'jrc.provision.discovery.v1',
            JSON.stringify({
                savedAt: Date.now(),
                data: data
            })
        );
    } catch (e) {
        console.debug('[PROVISIONAMENTO] Cache IXC indisponivel:', e);
    }
}

async function jrcProvisionLoadDiscovery(force) {
    if (!force) {
        const cached = jrcProvisionReadDiscoveryCache();
        if (cached && jrcProvisionApplyDiscovery(cached)) {
            return;
        }
    }

    const response = await fetch(
        '/api/discovery-ixc-tr069.php',
        {
            cache: force ? 'no-store' : 'default',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }
    );

    const data = await response.json();

    if (!response.ok || !data || !data.success) {
        throw new Error((data && data.message) || 'Falha na consulta IXC/ACS');
    }

    jrcProvisionSaveDiscoveryCache(data);
    jrcProvisionApplyDiscovery(data);
}

function jrcProvisionScheduleDiscovery() {
    const run = () => {
        jrcProvisionLoadDiscovery(false).catch(error => {
            console.warn('[PROVISIONAMENTO] Complemento IXC em segundo plano:', error);
        });
    };

    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(run, { timeout: 1500 });
    } else {
        window.setTimeout(run, 700);
    }
}
"""

NEW_LOAD = r"""async function load(forceDiscovery) {
    const force = forceDiscovery === true;
    const refresh = $('onu-refresh');

    if (refresh) refresh.disabled = true;

    const rows = $('onu-rows');
    if (rows) {
        rows.innerHTML =
            '<tr><td colspan="8" class="onu-empty">Carregando inventario local...</td></tr>';
    }

    let localLoaded = false;

    try {
        const localDevices = await jrcProvisionLoadLocalInventory();

        devices = localDevices;
        page = 1;
        render();
        localLoaded = true;
    } catch (error) {
        console.warn('[PROVISIONAMENTO] Inventario local indisponivel:', error);
    }

    try {
        if (force) {
            await jrcProvisionLoadDiscovery(true);
        } else if (localLoaded) {
            jrcProvisionScheduleDiscovery();
        } else {
            await jrcProvisionLoadDiscovery(false);
        }
    } catch (error) {
        if (!localLoaded && rows) {
            rows.innerHTML =
                '<tr><td colspan="8" class="onu-empty">Falha ao carregar inventario.</td></tr>';
        }
        console.error('[PROVISIONAMENTO] Falha IXC/ACS:', error);
    } finally {
        if (refresh) refresh.disabled = false;
    }
}"""

def run(cmd):
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout or '')[:3000])
    return p

def node_check(data: str, label: str):
    node = shutil.which("node")
    if not node:
        raise RuntimeError("Node.js nao encontrado para validar " + label)

    with tempfile.NamedTemporaryFile("w", suffix=".js", delete=False, encoding="utf-8") as f:
        f.write(data)
        tmp = f.name

    try:
        run([node, "--check", tmp])
    finally:
        os.unlink(tmp)

def php_check(data: str, label: str):
    php = shutil.which("php")
    if not php:
        raise RuntimeError("PHP CLI nao encontrado para validar " + label)

    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as f:
        f.write(data)
        tmp = f.name

    try:
        run([php, "-l", tmp])
    finally:
        os.unlink(tmp)

def find_function(text: str, name: str):
    m = re.search(r"\basync\s+function\s+" + re.escape(name) + r"\s*\([^)]*\)\s*\{", text)
    if not m:
        raise RuntimeError("Funcao async " + name + "() nao encontrada.")

    brace_start = text.find("{", m.start())
    if brace_start < 0:
        raise RuntimeError("Inicio da funcao " + name + "() invalido.")

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

def patch_provision(text: str):
    if "JRC_SECONDARY_PERF_V1" in text:
        return text, 0

    required = [
        "/api/discovery-ixc-tr069.php",
        "async function load",
        "onu-refresh",
    ]
    missing = [x for x in required if x not in text]
    if missing:
        raise RuntimeError(
            "Estrutura inesperada em provisionamento-onu.js: " + ", ".join(missing)
        )

    start, end = find_function(text, "load")
    patched = text[:start] + HELPERS + "\n" + NEW_LOAD + text[end:]

    patterns = [
        r"\$\(\s*['\"]onu-refresh['\"]\s*\)\.addEventListener\(\s*['\"]click['\"]\s*,\s*load\s*\)",
        r"document\.getElementById\(\s*['\"]onu-refresh['\"]\s*\)\.addEventListener\(\s*['\"]click['\"]\s*,\s*load\s*\)",
    ]

    replaced = False
    for pattern in patterns:
        new_patched, count = re.subn(
            pattern,
            "$('onu-refresh').addEventListener('click', () => load(true))",
            patched,
            count=1,
        )
        if count:
            patched = new_patched
            replaced = True
            break

    if not replaced:
        raise RuntimeError(
            "Listener do botao Atualizar nao encontrado no provisionamento."
        )

    return patched, 1

def patch_logs(text: str):
    changes = 0

    pattern = re.compile(
        r'^[ \t]*<script\s+src=["\']/assets/js/jrc-sinal\.js[^"\']*["\']\s*></script>\s*\r?\n?',
        flags=re.IGNORECASE | re.MULTILINE,
    )
    text, count = pattern.subn("", text, count=1)
    changes += count

    old = "autoRefreshInterval = setInterval(loadLogs, 3000);"
    new = (
        "autoRefreshInterval = setInterval(() => { "
        "if (!document.hidden) loadLogs(); "
        "}, 10000);"
    )

    if old in text:
        text = text.replace(old, new, 1)
        changes += 1
    elif "10000" not in text or "document.hidden" not in text:
        raise RuntimeError("Intervalo esperado de logs nao encontrado.")

    return text, changes

def atomic_write(path: Path, data: bytes, meta):
    fd, tmp = tempfile.mkstemp(prefix=".jrc-secondary-", dir=str(path.parent))
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

    provision = root / PROVISION_FILE
    logs = root / LOGS_FILE

    for p in (provision, logs):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError("Arquivo nao encontrado ou invalido: " + str(p))

    lock_path = backup_parent / ".jrc-secondary-pages-performance.lock"

    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        provision_before = provision.read_text(encoding="utf-8")
        logs_before = logs.read_text(encoding="utf-8")

        provision_after, p_changes = patch_provision(provision_before)
        logs_after, l_changes = patch_logs(logs_before)

        node_check(provision_after, PROVISION_FILE)
        php_check(logs_after, LOGS_FILE)

        print("VALIDADO: performance das paginas secundarias JR CONECT ACS.")
        print("Dashboard e Dispositivos NAO serao alterados.")
        print("Provisionamento: inventario local primeiro; IXC/ACS em segundo plano.")
        print("Provisionamento: cache IXC de sessao por 5 minutos.")
        print("Logs: include duplicado de jrc-sinal removido.")
        print("Logs: auto-refresh passa de 3s para 10s e pausa em aba oculta.")
        print(f"Alteracoes previstas: provisionamento={p_changes}, logs={l_changes}.")

        if args.dry_run:
            print("SIMULACAO OK: nenhum arquivo alterado.")
            return

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-secondary-pages-" +
                   datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent),
        ))
        os.chmod(backup, 0o700)

        (backup / "assets/js").mkdir(parents=True, exist_ok=True)
        shutil.copy2(provision, backup / PROVISION_FILE)
        shutil.copy2(logs, backup / LOGS_FILE)
        shutil.copy2(Path(__file__), backup / "install.py")

        if provision.read_text(encoding="utf-8") != provision_before:
            raise RuntimeError("provisionamento-onu.js mudou durante a instalacao.")
        if logs.read_text(encoding="utf-8") != logs_before:
            raise RuntimeError("view-logs.php mudou durante a instalacao.")

        atomic_write(provision, provision_after.encode("utf-8"), provision.stat())
        atomic_write(logs, logs_after.encode("utf-8"), logs.stat())

        node_check(provision.read_text(encoding="utf-8"), PROVISION_FILE)
        php_check(logs.read_text(encoding="utf-8"), LOGS_FILE)

        print("INSTALADO: paginas secundarias otimizadas.")
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
