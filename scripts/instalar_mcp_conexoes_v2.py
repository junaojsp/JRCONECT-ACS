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
    fd, tmp = tempfile.mkstemp(prefix=".jrc-mcp-v2-", dir=str(path.parent))
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

def node_check_text(text, label):
    docker = shutil.which("docker")
    if not docker:
        raise RuntimeError("Docker ausente para validar " + label)

    # O MCP usa ES Modules. Validar via arquivo .mjs evita o Node tratar stdin como CommonJS.
    with tempfile.NamedTemporaryFile("w", suffix=".mjs", delete=False, encoding="utf-8") as f:
        f.write(text)
        tmp = f.name

    try:
        p = subprocess.run(
            [
                docker, "run", "--rm",
                "-v", tmp + ":/tmp/check.mjs:ro",
                "node:24-alpine",
                "node", "--check", "/tmp/check.mjs"
            ],
            capture_output=True,
            text=True
        )
        if p.returncode != 0:
            raise RuntimeError(
                "JavaScript invalido em " + label + ": " +
                (p.stderr or p.stdout or "")[:2500]
            )
    finally:
        os.unlink(tmp)

def php_check_text(text, label):
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
    if "JRC_MCP_CONNECTIONS_V2" in text:
        return text

    auth_import = "import { validarToken } from './auth.js';"
    if auth_import not in text:
        raise RuntimeError("Import validarToken nao encontrado no server.js")
    text = text.replace(
        auth_import,
        auth_import + "\nimport { ConnectionRegistry } from './connection-registry.js'; // JRC_MCP_CONNECTIONS_V2",
        1
    )

    handler_anchor = "const handler = createMcpHandler(() => {"
    if handler_anchor not in text:
        raise RuntimeError("createMcpHandler nao encontrado no server.js")
    text = text.replace(
        handler_anchor,
        "const connections = new ConnectionRegistry();\n\n" + handler_anchor,
        1
    )

    return_pos = text.find("  return mcp;")
    if return_pos < 0:
        raise RuntimeError("return mcp nao encontrado no server.js")

    tool_block = """  mcp.registerTool('listar_servidores', {
    description: 'Lista clientes e sistemas que ja autenticaram no MCP JR Conect, com status e ultima comunicacao',
  }, async () => ({
    content: [{
      type: 'text',
      text: JSON.stringify({ connections: connections.list() }, null, 2),
    }],
  }));

"""
    text = text[:return_pos] + tool_block + text[return_pos:]

    health_anchor = "  if (req.method === 'GET' && req.url === '/health') {"
    if health_anchor not in text:
        raise RuntimeError("Rota /health nao encontrada no server.js")

    route_block = """  if (req.method === 'GET' && req.url?.split('?')[0] === '/connections') {
    res.writeHead(200, {
      'Content-Type': 'application/json',
      'Cache-Control': 'no-store',
    });
    res.end(JSON.stringify({ success: true, connections: connections.list() }));
    return;
  }

"""
    text = text.replace(health_anchor, route_block + health_anchor, 1)

    opa_anchor = "    req.mcpUser = { sub: 'opa-suite', client: 'opa-suite' };"
    if opa_anchor not in text:
        raise RuntimeError("Bloco Opa Suite nao encontrado no server.js")
    text = text.replace(
        opa_anchor,
        opa_anchor + """
    connections.touch({
      id: 'opa-suite',
      name: 'Opa Suite',
      clientId: 'opa-suite',
      authType: 'token',
    });""",
        1
    )

    oauth_anchor = "    req.mcpUser = { sub: payload.sub, client: payload.azp };"
    if oauth_anchor not in text:
        raise RuntimeError("Bloco OAuth nao encontrado no server.js")
    text = text.replace(
        oauth_anchor,
        oauth_anchor + """
    const clientId = String(payload.azp || 'oauth-client');
    connections.touch({
      id: clientId,
      name: clientId === 'chatgpt-mcp' ? 'ChatGPT' : clientId,
      clientId,
      authType: 'oauth',
    });""",
        1
    )

    return text

def patch_compose(text):
    if "./runtime:/data" in text:
        return text

    lines = text.splitlines()
    for i, line in enumerate(lines):
        if "./opa-token.secret:/run/secrets/opa-token:ro" in line:
            indent = line[:len(line) - len(line.lstrip())]
            lines.insert(i + 1, indent + "- ./runtime:/data")
            return "\n".join(lines) + ("\n" if text.endswith("\n") else "")

    raise RuntimeError("Volume opa-token.secret nao encontrado no docker-compose.yml")

def patch_dockerfile(text):
    if "connection-registry.js" in text:
        return text

    for line in text.splitlines():
        stripped = line.strip()
        if stripped in ("COPY . .", "COPY . ./", "COPY ./ .", "COPY ./ ./"):
            return text
        if stripped.startswith("COPY . ") and "/app" in stripped:
            return text

    lines = text.splitlines()
    for i, line in enumerate(lines):
        stripped = line.strip()
        if stripped.startswith("COPY ") and "server.js" in stripped and "auth.js" in stripped:
            indent = line[:len(line) - len(line.lstrip())]
            if stripped.endswith("./"):
                base = stripped[:-2].rstrip()
                lines[i] = indent + base + " connection-registry.js ./"
            else:
                lines.insert(i + 1, indent + "COPY connection-registry.js ./")
            return "\n".join(lines) + ("\n" if text.endswith("\n") else "")

    raise RuntimeError("Dockerfile sem COPY reconhecido")

def patch_configuration(text):
    css = '<link rel="stylesheet" href="/assets/css/mcp-connections.css?v=20261031-2">'
    js = '<script src="/assets/js/mcp-connections-panel.js?v=20261031-2" defer></script>'

    if "mcp-connections-panel.js" in text and "mcp-connections.css" in text:
        return text

    anchor = "<!-- Carregar JavaScript externo -->"
    if anchor not in text:
        raise RuntimeError("Ancora de scripts nao encontrada em configuration.php")

    return text.replace(anchor, css + "\n" + js + "\n\n" + anchor, 1)

def backup_file(src, root, rel):
    if not src.exists():
        return
    dst = root / rel
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dst)

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
    compose = mcp / "docker-compose.yml"
    dockerfile = mcp / "Dockerfile"
    configuration = acs / "configuration.php"

    for p in (server, compose, dockerfile, configuration):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError("Arquivo invalido ou ausente: " + str(p))

    lock_path = backup_parent / ".jrc-mcp-connections-v2.lock"
    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        payload_registry = git_show(acs, "mcp/connection-registry.js")
        payload_api = git_show(acs, "api/mcp-connections.php")
        payload_css = git_show(acs, "assets/css/mcp-connections.css")
        payload_js = git_show(acs, "assets/js/mcp-connections-panel.js")

        server_before = server.read_text(encoding="utf-8")
        compose_before = compose.read_text(encoding="utf-8")
        dockerfile_before = dockerfile.read_text(encoding="utf-8")
        configuration_before = configuration.read_text(encoding="utf-8")

        server_after = patch_server(server_before)
        compose_after = patch_compose(compose_before)
        dockerfile_after = patch_dockerfile(dockerfile_before)
        configuration_after = patch_configuration(configuration_before)

        node_check_text(server_after, "server.js")
        node_check_text(payload_registry.decode("utf-8"), "connection-registry.js")
        node_check_text(payload_js.decode("utf-8"), "mcp-connections-panel.js")
        php_check_text(payload_api.decode("utf-8"), "api/mcp-connections.php")
        php_check_text(configuration_after, "configuration.php")

        print("VALIDADO: MCP conexoes V2.")
        print("Wiki existente sera preservada.")
        print("Nova ferramenta: listar_servidores.")
        print("Nova rota local: /connections.")
        print("Painel sera carregado na pagina Configuracoes.")

        if args.dry_run:
            print("SIMULACAO OK: nenhum arquivo alterado.")
            return

        backup = Path(tempfile.mkdtemp(
            prefix="jrc-mcp-connections-v2-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent)
        ))
        os.chmod(backup, 0o700)

        backup_file(server, backup, "opt/jrconect-mcp/server.js")
        backup_file(compose, backup, "opt/jrconect-mcp/docker-compose.yml")
        backup_file(dockerfile, backup, "opt/jrconect-mcp/Dockerfile")
        backup_file(configuration, backup, "var/www/gacs/configuration.php")
        backup_file(mcp / "connection-registry.js", backup, "opt/jrconect-mcp/connection-registry.js")
        backup_file(acs / "api/mcp-connections.php", backup, "var/www/gacs/api/mcp-connections.php")
        backup_file(acs / "assets/css/mcp-connections.css", backup, "var/www/gacs/assets/css/mcp-connections.css")
        backup_file(acs / "assets/js/mcp-connections-panel.js", backup, "var/www/gacs/assets/js/mcp-connections-panel.js")
        shutil.copy2(Path(__file__), backup / "install.py")

        if server.read_text(encoding="utf-8") != server_before:
            raise RuntimeError("server.js mudou durante a instalacao")
        if compose.read_text(encoding="utf-8") != compose_before:
            raise RuntimeError("docker-compose.yml mudou durante a instalacao")
        if dockerfile.read_text(encoding="utf-8") != dockerfile_before:
            raise RuntimeError("Dockerfile mudou durante a instalacao")
        if configuration.read_text(encoding="utf-8") != configuration_before:
            raise RuntimeError("configuration.php mudou durante a instalacao")

        atomic_write(server, server_after.encode("utf-8"), server.stat())
        atomic_write(compose, compose_after.encode("utf-8"), compose.stat())
        atomic_write(dockerfile, dockerfile_after.encode("utf-8"), dockerfile.stat())

        registry_path = mcp / "connection-registry.js"
        atomic_write(registry_path, payload_registry, registry_path.stat() if registry_path.exists() else None)

        runtime = mcp / "runtime"
        runtime.mkdir(mode=0o777, exist_ok=True)
        os.chmod(runtime, 0o777)

        api_path = acs / "api/mcp-connections.php"
        css_path = acs / "assets/css/mcp-connections.css"
        js_path = acs / "assets/js/mcp-connections-panel.js"

        atomic_write(api_path, payload_api, api_path.stat() if api_path.exists() else None)
        atomic_write(css_path, payload_css, css_path.stat() if css_path.exists() else None)
        atomic_write(js_path, payload_js, js_path.stat() if js_path.exists() else None)
        atomic_write(configuration, configuration_after.encode("utf-8"), configuration.stat())

        run(["docker", "compose", "config", "--quiet"], cwd=mcp)
        run(["docker", "compose", "up", "-d", "--build"], cwd=mcp)

        health = run(["curl", "-fsS", "http://127.0.0.1:3100/health"], check=False)
        if health.returncode != 0 or '"ok"' not in health.stdout:
            raise RuntimeError("Health MCP falhou. Backup: " + str(backup))

        conn = run(["curl", "-fsS", "http://127.0.0.1:3100/connections"], check=False)
        if conn.returncode != 0 or '"success":true' not in conn.stdout:
            raise RuntimeError("Rota /connections falhou. Backup: " + str(backup))

        print("INSTALADO: MCP conexoes V2 ativo.")
        print("Health: " + health.stdout.strip())
        print("Connections: " + conn.stdout.strip()[:500])
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
