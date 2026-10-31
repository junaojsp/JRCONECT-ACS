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

BRANCH = "origin/feat/mcp-labels-v7-20261031"

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
    fd, tmp = tempfile.mkstemp(prefix=".jrc-mcp-v7-", dir=str(path.parent))
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
    js = root / "assets/js/mcp-connections-panel.js"

    if not page.is_file() or page.is_symlink():
        raise RuntimeError("mcp-server.php invalido ou ausente")
    if not js.is_file() or js.is_symlink():
        raise RuntimeError("mcp-connections-panel.js invalido ou ausente")

    page_payload = git_show(root, "mcp-server.php")
    js_payload = git_show(root, "assets/js/mcp-connections-panel.js")

    page_text = page_payload.decode("utf-8")
    js_text = js_payload.decode("utf-8")

    required = [
        "Componentes e integrações MCP",
        "Clientes externos autenticados",
        "Integração interna",
        "statusLabel = isActive ? 'Ativo' : 'Inativo'",
    ]
    missing = [x for x in required if x not in js_text]
    if missing:
        raise RuntimeError("Marcadores V7 ausentes: " + ", ".join(missing))

    if "mcp-connections-panel.js?v=20261031-7" not in page_text:
        raise RuntimeError("Cache V7 nao encontrado em mcp-server.php")

    php_check(page_text, "mcp-server.php")

    print("VALIDADO: MCP Server Labels V7.")
    print("Wiki sera exibida como Integracao interna.")
    print("Secao superior: Componentes e integracoes MCP.")
    print("Secao inferior: Clientes externos autenticados.")
    print("ChatGPT/Opa continuam com status Ativo/Inativo.")
    print("Backend MCP nao sera alterado.")

    if args.dry_run:
        print("SIMULACAO OK: nenhum arquivo alterado.")
        return

    lock_path = backup_parent / ".jrc-mcp-labels-v7.lock"
    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-mcp-labels-v7-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent)
        ))
        os.chmod(backup, 0o700)

        backup_file(page, backup, "mcp-server.php")
        backup_file(js, backup, "assets/js/mcp-connections-panel.js")
        shutil.copy2(Path(__file__), backup / "install.py")

        atomic_write(page, page_payload, page.stat())
        atomic_write(js, js_payload, js.stat())

        php_check(page.read_text(encoding="utf-8"), "mcp-server.php")

        print("INSTALADO: MCP Server Labels V7 aplicado.")
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
