#!/usr/bin/env python3
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

BRANCH = "origin/feat/mcp-unified-cards-v9-20261101"

def git_show(repo_root, rel):
    p = subprocess.run(
        ["git", "-c", "safe.directory=" + str(repo_root), "show", BRANCH + ":" + rel],
        cwd=str(repo_root), capture_output=True
    )
    if p.returncode != 0:
        raise RuntimeError("git show falhou para " + rel + ": " + p.stderr.decode(errors="ignore")[:1200])
    return p.stdout

def php_check(text, label):
    php = shutil.which("php")
    if not php:
        raise RuntimeError("PHP CLI ausente para validar " + label)
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as f:
        f.write(text)
        tmp = f.name
    try:
        p = subprocess.run([php, "-l", tmp], capture_output=True, text=True)
        if p.returncode != 0:
            raise RuntimeError("PHP invalido em " + label + ": " + (p.stderr or p.stdout)[:2200])
    finally:
        os.unlink(tmp)

def atomic_write(path, data, meta=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=".jrc-mcp-v9-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
            if meta is not None:
                os.fchmod(f.fileno(), stat.S_IMODE(meta.st_mode))
                if os.geteuid() == 0:
                    os.fchown(f.fileno(), meta.st_uid, meta.st_gid)
            else:
                os.fchmod(f.fileno(), 0o644)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def backup_file(src, root, rel):
    if not src.exists():
        return
    dst = root / rel
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dst)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default="/var/www/gacs")
    ap.add_argument("--backup-dir", default="/root")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    backup_parent = Path(args.backup_dir).resolve()

    page = root / "mcp-server.php"
    css = root / "assets/css/mcp-connections.css"
    js = root / "assets/js/mcp-connections-panel.js"

    for p in (page, css, js):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError("Arquivo invalido ou ausente: " + str(p))

    page_payload = git_show(root, "mcp-server.php")
    css_payload = git_show(root, "assets/css/mcp-connections.css")
    js_payload = git_show(root, "assets/js/mcp-connections-panel.js")

    page_text = page_payload.decode("utf-8")
    css_text = css_payload.decode("utf-8")
    js_text = js_payload.decode("utf-8")

    required = [
        "MCPs e integrações conectadas",
        "Cliente MCP externo",
        "Token MCP",
        "OAuth / Keycloak",
        "MCP unified cards V9",
        "mcp-connections-panel.js?v=20261101-9",
        "mcp-connections.css?v=20261101-9",
    ]
    merged = page_text + "\n" + css_text + "\n" + js_text
    missing = [x for x in required if x not in merged]
    if missing:
        raise RuntimeError("Marcadores V9 ausentes: " + ", ".join(missing))

    php_check(page_text, "mcp-server.php")

    print("VALIDADO: MCP Unified Cards V9.")
    print("JR Conect MCPv2, Wiki JR Conect, Opa Suite e ChatGPT ficarao no mesmo grid.")
    print("Opa/ChatGPT exibirao autenticacao, ultima comunicacao e requisicoes em chips.")
    print("A tabela separada de clientes sera removida do frontend.")
    print("Backend MCP e APIs NAO serao alterados.")

    if args.dry_run:
        print("SIMULACAO OK: nenhum arquivo alterado.")
        return

    lock_path = backup_parent / ".jrc-mcp-unified-v9.lock"
    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-mcp-unified-v9-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent)
        ))
        os.chmod(backup, 0o700)

        backup_file(page, backup, "mcp-server.php")
        backup_file(css, backup, "assets/css/mcp-connections.css")
        backup_file(js, backup, "assets/js/mcp-connections-panel.js")
        shutil.copy2(Path(__file__), backup / "install.py")

        atomic_write(page, page_payload, page.stat())
        atomic_write(css, css_payload, css.stat())
        atomic_write(js, js_payload, js.stat())

        php_check(page.read_text(encoding="utf-8"), "mcp-server.php")

        print("INSTALADO: MCP Unified Cards V9 aplicado.")
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
