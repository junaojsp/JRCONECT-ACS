#!/usr/bin/env python3
import argparse
import datetime
import fcntl
import glob
import grp
import os
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

BRANCH = "origin/feat/mcp-local-instances-v11-20261101"

def git_show(repo_root, rel):
    p = subprocess.run(
        ["git", "-c", "safe.directory=" + str(repo_root), "show", BRANCH + ":" + rel],
        cwd=str(repo_root), capture_output=True
    )
    if p.returncode != 0:
        raise RuntimeError("git show falhou para " + rel + ": " + p.stderr.decode(errors="ignore")[:1400])
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
            raise RuntimeError("PHP invalido em " + label + ": " + (p.stderr or p.stdout)[:2500])
    finally:
        os.unlink(tmp)

def node_check(text, label):
    node = shutil.which("node")
    if not node:
        return False

    with tempfile.NamedTemporaryFile("w", suffix=".js", delete=False, encoding="utf-8") as f:
        f.write(text)
        tmp = f.name
    try:
        p = subprocess.run([node, "--check", tmp], capture_output=True, text=True)
        if p.returncode != 0:
            raise RuntimeError("JavaScript invalido em " + label + ": " + (p.stderr or p.stdout)[:2500])
    finally:
        os.unlink(tmp)
    return True

def atomic_write(path, data, meta=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=".jrc-mcp-v11-", dir=str(path.parent))
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
    if src.is_dir():
        shutil.copytree(src, dst, dirs_exist_ok=True)
    else:
        shutil.copy2(src, dst)

def detect_php_user_group():
    for conf in sorted(glob.glob("/etc/php/*/fpm/pool.d/*.conf")):
        try:
            text = Path(conf).read_text(encoding="utf-8", errors="ignore")
        except Exception:
            continue

        user_match = re.search(r"^\s*user\s*=\s*([^;\s]+)", text, flags=re.MULTILINE)
        group_match = re.search(r"^\s*group\s*=\s*([^;\s]+)", text, flags=re.MULTILINE)

        if not user_match:
            continue

        user_name = user_match.group(1).strip()
        group_name = group_match.group(1).strip() if group_match else user_name

        try:
            uid = pwd.getpwnam(user_name).pw_uid
            gid = grp.getgrnam(group_name).gr_gid
            return user_name, group_name, uid, gid
        except KeyError:
            continue

    try:
        user = pwd.getpwnam("www-data")
        group = grp.getgrnam("www-data")
        return "www-data", "www-data", user.pw_uid, group.gr_gid
    except KeyError:
        return None

def prepare_storage(root):
    storage = root / "storage" / "mcp"
    storage.mkdir(parents=True, exist_ok=True)
    os.chmod(storage, 0o770)

    detected = detect_php_user_group()
    if detected and os.geteuid() == 0:
        user_name, group_name, uid, gid = detected
        os.chown(storage, uid, gid)
        return storage, user_name + ":" + group_name

    return storage, "permissao atual"

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
    api = root / "api/mcp-instances.php"
    storage = root / "storage" / "mcp"

    for p in (page, css, js):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError("Arquivo invalido ou ausente: " + str(p))

    page_payload = git_show(root, "mcp-server.php")
    css_payload = git_show(root, "assets/css/mcp-connections.css")
    js_payload = git_show(root, "assets/js/mcp-connections-panel.js")
    api_payload = git_show(root, "api/mcp-instances.php")

    page_text = page_payload.decode("utf-8")
    css_text = css_payload.decode("utf-8")
    js_text = js_payload.decode("utf-8")
    api_text = api_payload.decode("utf-8")

    required = [
        "Servidor MCP principal",
        "Integrações deste servidor",
        "Adicionar MCP",
        "JR Conect MCPv2",
        "Cliente externo conectado ao JR Conect MCPv2",
        "/api/mcp-instances.php",
        "MCP local instances V11",
        "mcp-connections-panel.js?v=20261101-11",
        "mcp-connections.css?v=20261101-11",
        "mcp_instance_csrf",
    ]

    merged = page_text + "\n" + css_text + "\n" + js_text + "\n" + api_text
    missing = [marker for marker in required if marker not in merged]
    if missing:
        raise RuntimeError("Marcadores V11 ausentes: " + ", ".join(missing))

    php_check(page_text, "mcp-server.php")
    php_check(api_text, "api/mcp-instances.php")
    js_validated = node_check(js_text, "mcp-connections-panel.js")

    print("VALIDADO: MCP Local Instances V11.")
    print("JR Conect MCPv2 sera o servidor MCP principal hospedado no ACS.")
    print("Wiki, Opa Suite e ChatGPT aparecerao como integracoes dentro desse servidor.")
    print("Botao Adicionar MCP cadastrara futuras instancias locais sem criar container automaticamente.")
    print("Cadastro persistente: storage/mcp/instances.json.")
    print("Backend do JR Conect MCPv2 atual NAO sera alterado.")
    print("Validacao JavaScript via Node: " + ("OK" if js_validated else "Node local ausente; validacao estrutural aplicada."))

    if args.dry_run:
        print("SIMULACAO OK: nenhum arquivo alterado e nenhuma pasta criada.")
        return

    lock_path = backup_parent / ".jrc-mcp-local-v11.lock"
    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-mcp-local-v11-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent)
        ))
        os.chmod(backup, 0o700)

        backup_file(page, backup, "mcp-server.php")
        backup_file(css, backup, "assets/css/mcp-connections.css")
        backup_file(js, backup, "assets/js/mcp-connections-panel.js")
        backup_file(api, backup, "api/mcp-instances.php")
        backup_file(storage, backup, "storage/mcp")
        shutil.copy2(Path(__file__), backup / "install.py")

        atomic_write(page, page_payload, page.stat())
        atomic_write(css, css_payload, css.stat())
        atomic_write(js, js_payload, js.stat())
        atomic_write(api, api_payload, api.stat() if api.exists() else None)

        storage_path, storage_owner = prepare_storage(root)

        php_check(page.read_text(encoding="utf-8"), "mcp-server.php")
        php_check(api.read_text(encoding="utf-8"), "api/mcp-instances.php")

        print("INSTALADO: MCP Local Instances V11 aplicado.")
        print("Storage: " + str(storage_path) + " (" + storage_owner + ")")
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
