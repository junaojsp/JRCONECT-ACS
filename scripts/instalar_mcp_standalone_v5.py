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

BRANCH = "origin/feat/mcp-standalone-page-v5-20261031"

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
            raise RuntimeError("PHP invalido em " + label + ": " + (p.stderr or p.stdout)[:2200])
    finally:
        os.unlink(tmp)

def atomic_write(path, data, meta=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=".jrc-mcp-v5-", dir=str(path.parent))
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

def remove_li_for_mcp_tab(text):
    markers = ['data-bs-target="#mcp-config"', 'id="mcp-tab"']
    pos = -1
    for marker in markers:
        pos = text.find(marker)
        if pos >= 0:
            break
    if pos < 0:
        return text, False

    start = text.rfind("<li", 0, pos)
    end = text.find("</li>", pos)
    if start < 0 or end < 0:
        raise RuntimeError("Item da aba MCP encontrado, mas bloco <li> esta invalido")

    end += len("</li>")
    return text[:start] + text[end:], True

def remove_div_by_id(text, element_id):
    id_markers = [f'id="{element_id}"', f"id='{element_id}'"]
    pos = -1
    for marker in id_markers:
        pos = text.find(marker)
        if pos >= 0:
            break

    if pos < 0:
        return text, False

    start = text.rfind("<div", 0, pos)
    if start < 0:
        raise RuntimeError("Bloco #" + element_id + " encontrado sem <div> inicial")

    token_re = re.compile(r"<div\b[^>]*>|</div\s*>", re.IGNORECASE)
    depth = 0
    end = None

    for m in token_re.finditer(text, start):
        token = m.group(0).lower()
        if token.startswith("<div") and not token.startswith("</div"):
            depth += 1
        else:
            depth -= 1
            if depth == 0:
                end = m.end()
                break

    if end is None:
        raise RuntimeError("Nao foi possivel fechar o bloco #" + element_id)

    # Remove comentario imediatamente anterior, se existir.
    prefix_start = start
    before = text[max(0, start - 120):start]
    cm = re.search(r"<!--\s*Configura[cç][aã]o MCP\s*-->\s*$", before, re.IGNORECASE)
    if cm:
        prefix_start = max(0, start - 120) + cm.start()

    return text[:prefix_start] + text[end:], True

def patch_configuration(text):
    changed = False

    # Remove CSS/JS do painel da tela Configuracoes.
    new_text = re.sub(
        r'^\s*<link[^>]+mcp-connections\.css[^>]*>\s*\n?',
        '',
        text,
        flags=re.IGNORECASE | re.MULTILINE
    )
    if new_text != text:
        text = new_text
        changed = True

    new_text = re.sub(
        r'^\s*<script[^>]+mcp-connections-panel\.js[^>]*></script>\s*\n?',
        '',
        text,
        flags=re.IGNORECASE | re.MULTILINE
    )
    if new_text != text:
        text = new_text
        changed = True

    text, removed_tab = remove_li_for_mcp_tab(text)
    changed = changed or removed_tab

    text, removed_panel = remove_div_by_id(text, "mcp-config")
    changed = changed or removed_panel

    return text, changed

def patch_header(text):
    original = text

    text = text.replace('/configuration.php?tab=mcp', '/mcp-server.php')

    if 'href="/mcp-server.php"' not in text:
        raise RuntimeError("Atalho MCP Server nao encontrado no menu lateral")

    # Ajusta a classe ativa apenas no link MCP Server.
    pattern = re.compile(
        r'(<a\b(?=[^>]*href="/mcp-server\.php")[^>]*)(>)',
        re.IGNORECASE | re.DOTALL
    )
    match = pattern.search(text)
    if not match:
        raise RuntimeError("Nao foi possivel localizar a tag <a> do MCP Server")

    opening = match.group(1)
    if "class=" not in opening:
        active = ' class="<?php echo ($currentPage ?? \'\') === \'mcp-server\' ? \'active\' : \'\'; ?>"'
        opening = opening + active
        text = text[:match.start()] + opening + ">" + text[match.end():]
    elif "mcp-server" not in opening:
        # Nao altera classes desconhecidas automaticamente.
        pass

    return text, (text != original)

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
    page = root / "mcp-server.php"
    css = root / "assets/css/mcp-connections.css"
    js = root / "assets/js/mcp-connections-panel.js"

    for p in (configuration, header):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError("Arquivo invalido ou ausente: " + str(p))

    page_payload = git_show(root, "mcp-server.php")
    css_payload = git_show(root, "assets/css/mcp-connections.css")
    js_payload = git_show(root, "assets/js/mcp-connections-panel.js")

    configuration_before = configuration.read_text(encoding="utf-8")
    header_before = header.read_text(encoding="utf-8")

    configuration_after, config_changed = patch_configuration(configuration_before)
    header_after, header_changed = patch_header(header_before)

    php_check(configuration_after, "configuration.php")
    php_check(header_after, "views/layouts/header.php")
    php_check(page_payload.decode("utf-8"), "mcp-server.php")

    print("VALIDADO: MCP Server standalone V5.")
    print("A aba Configuracao MCP sera removida de Configuracoes.")
    print("MCP Server permanecera somente no menu lateral.")
    print("Nova pagina: /mcp-server.php.")
    print("Clientes usarao status Ativo/Inativo com janela de 15 minutos.")
    print("Servicos MCP continuam com status Online/Offline real do backend.")
    print(f"Alteracoes detectadas: configuracao={config_changed}, menu={header_changed}.")

    if args.dry_run:
        print("SIMULACAO OK: nenhum arquivo alterado.")
        return

    lock_path = backup_parent / ".jrc-mcp-standalone-v5.lock"
    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        if configuration.read_text(encoding="utf-8") != configuration_before:
            raise RuntimeError("configuration.php mudou durante a instalacao")
        if header.read_text(encoding="utf-8") != header_before:
            raise RuntimeError("header.php mudou durante a instalacao")

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-mcp-standalone-v5-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent)
        ))
        os.chmod(backup, 0o700)

        backup_file(configuration, backup, "configuration.php")
        backup_file(header, backup, "views/layouts/header.php")
        backup_file(page, backup, "mcp-server.php")
        backup_file(css, backup, "assets/css/mcp-connections.css")
        backup_file(js, backup, "assets/js/mcp-connections-panel.js")
        shutil.copy2(Path(__file__), backup / "install.py")

        atomic_write(configuration, configuration_after.encode("utf-8"), configuration.stat())
        atomic_write(header, header_after.encode("utf-8"), header.stat())
        atomic_write(page, page_payload, page.stat() if page.exists() else None)
        atomic_write(css, css_payload, css.stat() if css.exists() else None)
        atomic_write(js, js_payload, js.stat() if js.exists() else None)

        php_check(configuration.read_text(encoding="utf-8"), "configuration.php")
        php_check(header.read_text(encoding="utf-8"), "views/layouts/header.php")
        php_check(page.read_text(encoding="utf-8"), "mcp-server.php")

        print("INSTALADO: MCP Server standalone V5 aplicado.")
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
