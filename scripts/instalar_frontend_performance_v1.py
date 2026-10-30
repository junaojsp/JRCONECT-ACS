#!/usr/bin/env python3
"""Patch JR CONECT ACS frontend loading without changing page layout."""
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

DASHBOARD_ONLY = [
    "jrc-sinal.js",
    "dashboard-chart-fix.js",
    "dashboard-source-policy.js",
]

DISCOVERY_OLD = """const response = await fetch('/api/discovery-ixc-tr069.php', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Discovery indisponivel');"""

DISCOVERY_NEW = """const discoveryCacheKey = 'jrc.dashboard.discovery.v1';
        const discoveryCacheTtlMs = 5 * 60 * 1000;
        let data = null;

        try {
            const cachedRaw = sessionStorage.getItem(discoveryCacheKey);
            if (cachedRaw) {
                const cached = JSON.parse(cachedRaw);
                if (
                    cached &&
                    cached.savedAt &&
                    (Date.now() - Number(cached.savedAt)) < discoveryCacheTtlMs &&
                    cached.data &&
                    cached.data.success
                ) {
                    data = cached.data;
                }
            }
        } catch (cacheError) {
            console.debug('[DASHBOARD] Cache local da discovery indisponivel:', cacheError);
        }

        if (!data) {
            const response = await fetch('/api/discovery-ixc-tr069.php', { cache: 'default' });
            data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Discovery indisponivel');
            }

            try {
                sessionStorage.setItem(
                    discoveryCacheKey,
                    JSON.stringify({
                        savedAt: Date.now(),
                        data: data
                    })
                );
            } catch (cacheError) {
                console.debug('[DASHBOARD] Nao foi possivel salvar cache da discovery:', cacheError);
            }
        }

        if (!data.success) throw new Error(data.message || 'Discovery indisponivel');"""

def php_lint(path: Path) -> None:
    php = shutil.which("php")
    if not php:
        raise RuntimeError("PHP CLI nao encontrado.")
    p = subprocess.run([php, "-l", str(path)], capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout)[:2000])

def atomic_write(path: Path, data: bytes, meta) -> None:
    fd, tmp = tempfile.mkstemp(prefix=".jrc-front-", dir=str(path.parent))
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

def patch_footer(text: str) -> tuple[str, int]:
    changed = 0

    if "$jrcFooterScriptName" not in text:
        marker = "<script"
        pos = text.find(marker)
        if pos < 0:
            raise RuntimeError("footer.php sem tags <script>.")
        prefix = """<?php
$jrcFooterScriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
$jrcIsDashboardPage = ($jrcFooterScriptName === 'dashboard.php');
?>

"""
        text = text[:pos] + prefix + text[pos:]
        changed += 1

    for name in DASHBOARD_ONLY:
        if f"JRC_DASHBOARD_ONLY:{name}" in text:
            continue

        pattern = re.compile(
            r'(?P<indent>^[ \t]*)'
            r'(?P<tag><script\b[^>]*src=["\'][^"\']*'
            + re.escape(name) +
            r'[^"\']*["\'][^>]*></script>)',
            flags=re.IGNORECASE | re.MULTILINE,
        )
        match = pattern.search(text)
        if not match:
            raise RuntimeError(f"Script esperado nao encontrado no footer: {name}")

        indent = match.group("indent")
        tag = match.group("tag")
        replacement = (
            f"{indent}<?php if ($jrcIsDashboardPage): /* JRC_DASHBOARD_ONLY:{name} */ ?>\n"
            f"{indent}{tag}\n"
            f"{indent}<?php endif; ?>"
        )
        text = text[:match.start()] + replacement + text[match.end():]
        changed += 1

    return text, changed

def patch_dashboard(text: str) -> tuple[str, int]:
    if "jrc.dashboard.discovery.v1" in text:
        return text, 0

    original_newline = "\r\n" if "\r\n" in text else "\n"
    normalized = text.replace("\r\n", "\n")

    # Find the production function instead of relying on exact indentation.
    fn_match = re.search(
        r"async\s+function\s+loadFinalDiscoveryData\s*\(\s*\)\s*\{",
        normalized,
        flags=re.IGNORECASE,
    )
    if not fn_match:
        raise RuntimeError(
            "Funcao loadFinalDiscoveryData() nao encontrada; cancelado por seguranca."
        )

    fetch_match = re.search(
        r"(?P<indent>^[ \t]*)const\s+response\s*=\s*await\s+fetch\(\s*"
        r"['\"]/api/discovery-ixc-tr069\.php['\"]\s*,\s*"
        r"\{\s*cache\s*:\s*['\"]no-store['\"]\s*\}\s*\)\s*;\s*\n"
        r"(?P=indent)const\s+data\s*=\s*await\s+response\.json\(\)\s*;\s*\n"
        r"(?P=indent)if\s*\(\s*!response\.ok\s*\|\|\s*!data\.success\s*\)\s*"
        r"throw\s+new\s+Error\([^;]+?\)\s*;",
        normalized[fn_match.start():],
        flags=re.IGNORECASE | re.MULTILINE,
    )

    if not fetch_match:
        # Fallback: tolerate comments/blank lines between the three statements.
        fetch_match = re.search(
            r"(?P<indent>^[ \t]*)const\s+response\s*=\s*await\s+fetch\(\s*"
            r"['\"]/api/discovery-ixc-tr069\.php['\"]\s*,\s*"
            r"\{\s*cache\s*:\s*['\"]no-store['\"]\s*\}\s*\)\s*;"
            r"[\s\S]{0,300}?"
            r"const\s+data\s*=\s*await\s+response\.json\(\)\s*;"
            r"[\s\S]{0,300}?"
            r"if\s*\(\s*!response\.ok\s*\|\|\s*!data\.success\s*\)\s*"
            r"throw\s+new\s+Error\([^;]+?\)\s*;",
            normalized[fn_match.start():],
            flags=re.IGNORECASE | re.MULTILINE,
        )

    if not fetch_match:
        raise RuntimeError(
            "Chamada /api/discovery-ixc-tr069.php nao encontrada em loadFinalDiscoveryData(); "
            "cancelado por seguranca."
        )

    indent = fetch_match.group("indent")
    block = (
        indent + "const discoveryCacheKey = 'jrc.dashboard.discovery.v1';\n"
        + indent + "const discoveryCacheTtlMs = 5 * 60 * 1000;\n"
        + indent + "let data = null;\n\n"
        + indent + "try {\n"
        + indent + "    const cachedRaw = sessionStorage.getItem(discoveryCacheKey);\n"
        + indent + "    if (cachedRaw) {\n"
        + indent + "        const cached = JSON.parse(cachedRaw);\n"
        + indent + "        if (cached && cached.savedAt &&\n"
        + indent + "            (Date.now() - Number(cached.savedAt)) < discoveryCacheTtlMs &&\n"
        + indent + "            cached.data && cached.data.success) {\n"
        + indent + "            data = cached.data;\n"
        + indent + "        }\n"
        + indent + "    }\n"
        + indent + "} catch (cacheError) {\n"
        + indent + "    console.debug('[DASHBOARD] Cache discovery indisponivel:', cacheError);\n"
        + indent + "}\n\n"
        + indent + "if (!data) {\n"
        + indent + "    const response = await fetch('/api/discovery-ixc-tr069.php', { cache: 'default' });\n"
        + indent + "    data = await response.json();\n"
        + indent + "    if (!response.ok || !data.success) {\n"
        + indent + "        throw new Error(data.message || 'Discovery indisponivel');\n"
        + indent + "    }\n"
        + indent + "    try {\n"
        + indent + "        sessionStorage.setItem(discoveryCacheKey, JSON.stringify({ savedAt: Date.now(), data }));\n"
        + indent + "    } catch (cacheError) {\n"
        + indent + "        console.debug('[DASHBOARD] Falha ao salvar cache discovery:', cacheError);\n"
        + indent + "    }\n"
        + indent + "}\n"
    )

    absolute_start = fn_match.start() + fetch_match.start()
    absolute_end = fn_match.start() + fetch_match.end()
    normalized = normalized[:absolute_start] + block + normalized[absolute_end:]

    if original_newline == "\r\n":
        normalized = normalized.replace("\n", "\r\n")

    return normalized, 1

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default="/var/www/gacs")
    ap.add_argument("--backup-dir", default="/root")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    backup_parent = Path(args.backup_dir).resolve()

    footer = root / "views/layouts/footer.php"
    dashboard = root / "dashboard.php"

    for p in [footer, dashboard]:
        if not p.is_file() or p.is_symlink():
            raise RuntimeError(f"Arquivo invalido: {p}")

    lock_path = backup_parent / ".jrc-frontend-performance.lock"
    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        footer_before = footer.read_text(encoding="utf-8")
        dashboard_before = dashboard.read_text(encoding="utf-8")

        footer_after, footer_changes = patch_footer(footer_before)
        dashboard_after, dashboard_changes = patch_dashboard(dashboard_before)

        # Validate PHP after patching using temp files.
        with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as f:
            f.write(footer_after)
            footer_tmp = Path(f.name)
        with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as f:
            f.write(dashboard_after)
            dashboard_tmp = Path(f.name)

        try:
            php_lint(footer_tmp)
            php_lint(dashboard_tmp)
        finally:
            footer_tmp.unlink(missing_ok=True)
            dashboard_tmp.unlink(missing_ok=True)

        print("VALIDADO: frontend performance JR CONECT ACS.")
        print("Scripts exclusivos da Dashboard deixarao de carregar nas demais paginas.")
        print("Discovery IXC/TR-069 usara cache de sessao por 5 minutos.")
        print("Nenhum HTML visual, CSS ou endpoint sera removido.")
        print(f"Alteracoes previstas: footer={footer_changes}, dashboard={dashboard_changes}.")

        if args.dry_run:
            print("SIMULACAO OK: nenhum arquivo alterado.")
            return

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-frontend-performance-" +
                   datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent),
        ))
        os.chmod(backup, 0o700)

        (backup / "views/layouts").mkdir(parents=True, exist_ok=True)
        shutil.copy2(footer, backup / "views/layouts/footer.php")
        shutil.copy2(dashboard, backup / "dashboard.php")
        shutil.copy2(Path(__file__), backup / "install.py")

        if footer.read_text(encoding="utf-8") != footer_before:
            raise RuntimeError("footer.php mudou durante a instalacao.")
        if dashboard.read_text(encoding="utf-8") != dashboard_before:
            raise RuntimeError("dashboard.php mudou durante a instalacao.")

        atomic_write(footer, footer_after.encode("utf-8"), footer.stat())
        atomic_write(dashboard, dashboard_after.encode("utf-8"), dashboard.stat())

        php_lint(footer)
        php_lint(dashboard)

        print("INSTALADO: carregamento frontend otimizado.")
        print("Dashboard-only: jrc-sinal, dashboard-chart-fix, dashboard-source-policy.")
        print("Cache discovery: 5 minutos por sessao do navegador.")
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
