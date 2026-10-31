#!/usr/bin/env python3
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

BRANCH = "origin/feat/mcp-connected-clients-v1-20261031"

def run(cmd, cwd=None, check=True):
    p = subprocess.run(cmd, cwd=str(cwd) if cwd else None, capture_output=True, text=True)
    if check and p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout or "")[:3500])
    return p

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
    fd, tmp = tempfile.mkstemp(prefix=".jrc-mcp-layout-v4-", dir=str(path.parent))
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

def patch_configuration(text):
    text = re.sub(
        r'mcp-connections\.css\?v=[^"\']+',
        'mcp-connections.css?v=20261031-4',
        text
    )
    text = re.sub(
        r'mcp-connections-panel\.js\?v=[^"\']+',
        'mcp-connections-panel.js?v=20261031-4',
        text
    )

    if "mcp-connections.css?v=20261031-4" not in text:
        anchor = "<!-- Carregar JavaScript externo -->"
        if anchor not in text:
            raise RuntimeError("Ancora de scripts nao encontrada em configuration.php")
        block = (
            '<link rel="stylesheet" href="/assets/css/mcp-connections.css?v=20261031-4">\n'
            '<script src="/assets/js/mcp-connections-panel.js?v=20261031-4" defer></script>\n\n'
        )
        text = text.replace(anchor, block + anchor, 1)

    return text

def patch_header(text):
    if 'href="/configuration.php?tab=mcp"' in text:
        return text

    marker = 'href="/configuration.php"'
    pos = text.find(marker)
    if pos < 0:
        raise RuntimeError("Link /configuration.php nao encontrado no menu lateral")

    # Procura o </li> que fecha o item Configuracao.
    close_li = text.find("</li>", pos)
    if close_li < 0:
        raise RuntimeError("Fechamento do item Configuracao nao encontrado")

    insert_pos = close_li + len("</li>")

    block = """

<li class="jrc-sidebar-mcp-shortcut">
    <a
        href="/configuration.php?tab=mcp"
        data-tooltip="MCP Server"
    >
        <i class="bi bi-stack"></i>
        <span>MCP Server</span>
    </a>
</li>
"""

    return text[:insert_pos] + block + text[insert_pos:]

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default="/var/www/gacs")
    ap.add_argument("--backup-dir", default="/root")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    backup_parent = Path(args.backup_dir).resolve()

    configuration = root / "configuration.php"
    header = root / "views/layouts/header.php"
    css = root / "assets/css/mcp-connections.css"
    js = root / "assets/js/mcp-connections-panel.js"

    for p in (configuration, header):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError("Arquivo invalido ou ausente: " + str(p))

    css_payload = git_show(root, "assets/css/mcp-connections.css")
    js_payload = git_show(root, "assets/js/mcp-connections-panel.js")

    configuration_before = configuration.read_text(encoding="utf-8")
    header_before = header.read_text(encoding="utf-8")

    configuration_after = patch_configuration(configuration_before)
    header_after = patch_header(header_before)

    php_check(configuration_after, "configuration.php")
    php_check(header_after, "views/layouts/header.php")

    print("VALIDADO: MCP Server Layout V4.")
    print("Somente frontend do MCP e menu lateral serao alterados.")
    print("Tabela azul sera neutralizada para preto/cinza.")
    print("Servicos MCP ficarao em cards organizados com icones.")
    print("MCP Server sera adicionado ao menu lateral como atalho para a aba MCP.")
    print("Backend MCP, Wiki, Dashboard e Dispositivos NAO serao alterados.")

    if args.dry_run:
        print("SIMULACAO OK: nenhum arquivo alterado.")
        return

    lock_path = backup_parent / ".jrc-mcp-layout-v4.lock"
    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        # Reconfere se os arquivos nao mudaram entre validacao e escrita.
        if configuration.read_text(encoding="utf-8") != configuration_before:
            raise RuntimeError("configuration.php mudou durante a instalacao")
        if header.read_text(encoding="utf-8") != header_before:
            raise RuntimeError("header.php mudou durante a instalacao")

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-mcp-layout-v4-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent)
        ))
        os.chmod(backup, 0o700)

        backup_file(configuration, backup, "configuration.php")
        backup_file(header, backup, "views/layouts/header.php")
        backup_file(css, backup, "assets/css/mcp-connections.css")
        backup_file(js, backup, "assets/js/mcp-connections-panel.js")
        shutil.copy2(Path(__file__), backup / "install.py")

        atomic_write(configuration, configuration_after.encode("utf-8"), configuration.stat())
        atomic_write(header, header_after.encode("utf-8"), header.stat())
        atomic_write(css, css_payload, css.stat() if css.exists() else None)
        atomic_write(js, js_payload, js.stat() if js.exists() else None)

        php_check(configuration.read_text(encoding="utf-8"), "configuration.php")
        php_check(header.read_text(encoding="utf-8"), "views/layouts/header.php")

        print("INSTALADO: MCP Server Layout V4 aplicado.")
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
