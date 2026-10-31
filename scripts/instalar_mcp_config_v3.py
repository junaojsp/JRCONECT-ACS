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
import time
from pathlib import Path

BRANCH = "origin/feat/mcp-connected-clients-v1-20261031"

def run(cmd, cwd=None, check=True):
    p = subprocess.run(cmd, cwd=str(cwd) if cwd else None, capture_output=True, text=True)
    if check and p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout or "")[:4000])
    return p

def git_show(repo_root, rel):
    p = subprocess.run(
        ["git", "-c", "safe.directory=" + str(repo_root), "show", BRANCH + ":" + rel],
        cwd=str(repo_root), capture_output=True
    )
    if p.returncode != 0:
        raise RuntimeError("git show falhou para " + rel + ": " + p.stderr.decode(errors="ignore")[:1200])
    return p.stdout

def atomic_write(path, data, meta=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=".jrc-mcp-v3-", dir=str(path.parent))
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

def patch_server(text):
    if "JRC_MCP_SERVICES_V3" in text:
        return text

    old = "res.end(JSON.stringify({ success: true, connections: connections.list() }));"
    if old not in text:
        raise RuntimeError("Resposta da rota /connections nao encontrada no server.js")

    new = """const services = [
      {
        id: 'mcp-core',
        name: 'JR Conect MCPv2',
        type: 'Servidor MCP',
        status: 'online',
        tools: ['status_servidor', 'listar_servidores'],
      },
    ];

    if (typeof registrarWikiTools !== 'undefined') {
      services.push({
        id: 'wiki-jrconect',
        name: 'Wiki JR Conect',
        type: 'Modulo de ferramentas MCP',
        status: 'online',
        tools: [
          'wiki_listar_artigos',
          'wiki_ler_artigo',
          'wiki_pesquisar',
          'wiki_criar_artigo',
          'wiki_editar_artigo',
        ],
      });
    }

    // JRC_MCP_SERVICES_V3
    res.end(JSON.stringify({
      success: true,
      services,
      connections: connections.list(),
    }));"""

    return text.replace(old, new, 1)

def patch_configuration(text):
    text = re.sub(
        r'mcp-connections\.css\?v=[^"\']+',
        'mcp-connections.css?v=20261031-3',
        text
    )
    text = re.sub(
        r'mcp-connections-panel\.js\?v=[^"\']+',
        'mcp-connections-panel.js?v=20261031-3',
        text
    )

    if "mcp-connections.css?v=20261031-3" not in text:
        anchor = "<!-- Carregar JavaScript externo -->"
        if anchor not in text:
            raise RuntimeError("Ancora de scripts nao encontrada em configuration.php")
        block = (
            '<link rel="stylesheet" href="/assets/css/mcp-connections.css?v=20261031-3">\n'
            '<script src="/assets/js/mcp-connections-panel.js?v=20261031-3" defer></script>\n\n'
        )
        text = text.replace(anchor, block + anchor, 1)

    return text

def backup_file(src, root, rel):
    if not src.exists():
        return
    dst = root / rel
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dst)

def wait_health():
    last = ""
    for _ in range(30):
        p = run(["curl", "-s", "http://127.0.0.1:3100/health"], check=False)
        last = p.stdout.strip()
        if p.returncode == 0 and '"ok"' in last:
            return last
        time.sleep(1)
    raise RuntimeError("MCP nao ficou saudavel apos 30 segundos. Ultima resposta: " + last)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--acs-root", default="/var/www/gacs")
    ap.add_argument("--mcp-root", default="/opt/jrconect-mcp")
    ap.add_argument("--backup-dir", default="/root")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    acs = Path(args.acs_root).resolve()
    mcp = Path(args.mcp_root).resolve()
    backup_parent = Path(args.backup_dir).resolve()

    server = mcp / "server.js"
    configuration = acs / "configuration.php"

    for p in (server, configuration):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError("Arquivo invalido ou ausente: " + str(p))

    lock_path = backup_parent / ".jrc-mcp-services-v3.lock"
    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        api_payload = git_show(acs, "api/mcp-connections.php")
        css_payload = git_show(acs, "assets/css/mcp-connections.css")
        js_payload = git_show(acs, "assets/js/mcp-connections-panel.js")

        server_before = server.read_text(encoding="utf-8")
        configuration_before = configuration.read_text(encoding="utf-8")

        server_after = patch_server(server_before)
        configuration_after = patch_configuration(configuration_before)

        php_check(api_payload.decode("utf-8"), "api/mcp-connections.php")
        php_check(configuration_after, "configuration.php")

        print("VALIDADO: MCP Configuracao V3.")
        print("Wiki JR Conect sera exibida como servico MCP.")
        print("ChatGPT/Opa permanecem em Conexoes autenticadas.")
        print("Aba MCP sera neutralizada para preto/cinza.")
        print("Dashboard, Dispositivos e demais telas NAO serao alterados.")

        if args.dry_run:
            print("SIMULACAO OK: nenhum arquivo alterado.")
            return

        backup = Path(tempfile.mkdtemp(
            prefix="jrc-mcp-services-v3-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent)
        ))
        os.chmod(backup, 0o700)

        backup_file(server, backup, "opt/jrconect-mcp/server.js")
        backup_file(configuration, backup, "var/www/gacs/configuration.php")
        backup_file(acs / "api/mcp-connections.php", backup, "var/www/gacs/api/mcp-connections.php")
        backup_file(acs / "assets/css/mcp-connections.css", backup, "var/www/gacs/assets/css/mcp-connections.css")
        backup_file(acs / "assets/js/mcp-connections-panel.js", backup, "var/www/gacs/assets/js/mcp-connections-panel.js")
        shutil.copy2(Path(__file__), backup / "install.py")

        if server.read_text(encoding="utf-8") != server_before:
            raise RuntimeError("server.js mudou durante a instalacao")
        if configuration.read_text(encoding="utf-8") != configuration_before:
            raise RuntimeError("configuration.php mudou durante a instalacao")

        atomic_write(server, server_after.encode("utf-8"), server.stat())

        api_path = acs / "api/mcp-connections.php"
        css_path = acs / "assets/css/mcp-connections.css"
        js_path = acs / "assets/js/mcp-connections-panel.js"

        atomic_write(api_path, api_payload, api_path.stat() if api_path.exists() else None)
        atomic_write(css_path, css_payload, css_path.stat() if css_path.exists() else None)
        atomic_write(js_path, js_payload, js_path.stat() if js_path.exists() else None)
        atomic_write(configuration, configuration_after.encode("utf-8"), configuration.stat())

        run(["docker", "compose", "config", "--quiet"], cwd=mcp)
        run(["docker", "compose", "up", "-d", "--build"], cwd=mcp)

        health = wait_health()

        connections = run(
            ["curl", "-fsS", "http://127.0.0.1:3100/connections"],
            check=False
        )
        if connections.returncode != 0 or '"services"' not in connections.stdout:
            raise RuntimeError("Rota /connections nao retornou services. Backup: " + str(backup))

        print("INSTALADO: MCP Configuracao V3 aplicada.")
        print("Health: " + health)
        print("Connections/services: " + connections.stdout.strip()[:1000])
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
