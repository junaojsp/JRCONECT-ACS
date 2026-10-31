#!/usr/bin/env python3
"""Install MCP authenticated-connections registry + ACS panel safely."""
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

BRANCH = 'origin/feat/mcp-connected-clients-v1-20261031'
MCP_ROOT_DEFAULT = '/opt/jrconect-mcp'
ACS_ROOT_DEFAULT = '/var/www/gacs'

GIT_FILES = [
    'mcp/connection-registry.js',
    'api/mcp-connections.php',
    'assets/css/mcp-connections.css',
    'assets/js/mcp-connections-panel.js',
]

CSS_TAG = '<link rel="stylesheet" href="/assets/css/mcp-connections.css?v=20261031-1">'
JS_TAG = '<script src="/assets/js/mcp-connections-panel.js?v=20261031-1" defer></script>'

def run(cmd, cwd=None, check=True):
    p = subprocess.run(
        cmd,
        cwd=str(cwd) if cwd else None,
        capture_output=True,
        text=True,
    )
    if check and p.returncode != 0:
        raise RuntimeError(
            'Falha: ' + ' '.join(cmd) + '\n' + (p.stderr or p.stdout or '')[:4000]
        )
    return p

def git_show(repo_root: Path, rel: str) -> bytes:
    p = subprocess.run(
        ['git', '-c', 'safe.directory=' + str(repo_root), 'show', BRANCH + ':' + rel],
        cwd=str(repo_root),
        capture_output=True,
    )
    if p.returncode != 0:
        raise RuntimeError(
            'git show falhou para ' + rel + ': ' +
            p.stderr.decode(errors='ignore')[:1200]
        )
    return p.stdout

def atomic_write(path: Path, data: bytes, meta=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix='.jrc-mcp-connections-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as f:
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

def node_check(text: str, label: str):
    node = shutil.which('node')
    if node:
        with tempfile.NamedTemporaryFile('w', suffix='.js', delete=False, encoding='utf-8') as f:
            f.write(text)
            tmp = f.name
        try:
            run([node, '--check', tmp])
        finally:
            os.unlink(tmp)
        return

    docker = shutil.which('docker')
    if not docker:
        raise RuntimeError('Node.js/Docker ausentes para validar ' + label)

    p = subprocess.run(
        [docker, 'run', '--rm', '-i', 'node:24-alpine', 'node', '--check', '-'],
        input=text,
        capture_output=True,
        text=True,
    )
    if p.returncode != 0:
        raise RuntimeError('JavaScript invalido em ' + label + ': ' + (p.stderr or p.stdout or '')[:2500])

def php_check(text: str, label: str):
    php = shutil.which('php')
    if not php:
        raise RuntimeError('PHP CLI ausente para validar ' + label)
    with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False, encoding='utf-8') as f:
        f.write(text)
        tmp = f.name
    try:
        run([php, '-l', tmp])
    finally:
        os.unlink(tmp)

def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f'{label}: esperado 1 ocorrencia, encontrado {count}')
    return text.replace(old, new, 1)

def patch_server(text: str) -> str:
    if 'JRC_MCP_CONNECTIONS_V1' in text:
        return text

    text = replace_once(
        text,
        "import { validarToken } from './auth.js';",
        "import { validarToken } from './auth.js';\n"
        "import { ConnectionRegistry } from './connection-registry.js'; // JRC_MCP_CONNECTIONS_V1",
        'import auth.js',
    )

    text = replace_once(
        text,
        'const handler = createMcpHandler(() => {',
        'const connections = new ConnectionRegistry();\n\n'
        'const handler = createMcpHandler(() => {',
        'createMcpHandler',
    )

    # Registra a ferramenta sem assumir onde wiki-tools foi adicionado.
    return_matches = list(re.finditer(r'^[ \t]*return\s+mcp\s*;\s*$', text, flags=re.MULTILINE))
    if len(return_matches) != 1:
        raise RuntimeError(
            f'return mcp: esperado 1 ocorrencia, encontrado {len(return_matches)}'
        )

    tool_block = """  mcp.registerTool('listar_servidores', {
    description: 'Lista clientes e sistemas que ja autenticaram no MCP JR Conect, com status e ultima comunicacao',
  }, async () => ({
    content: [{
      type: 'text',
      text: JSON.stringify({ connections: connections.list() }, null, 2),
    }],
  }));

"""
    m = return_matches[0]
    text = text[:m.start()] + tool_block + text[m.start():]

    health_anchor = """  if (req.method === 'GET' && req.url === '/health') {"""
    if health_anchor not in text:
        raise RuntimeError('Rota /health nao encontrada no server.js')

    connections_route = """  if (req.method === 'GET' && req.url?.split('?')[0] === '/connections') {
    res.writeHead(200, {
      'Content-Type': 'application/json',
      'Cache-Control': 'no-store',
    });
    res.end(JSON.stringify({ success: true, connections: connections.list() }));
    return;
  }

"""
    text = text.replace(health_anchor, connections_route + health_anchor, 1)

    opa_anchor = "    req.mcpUser = { sub: 'opa-suite', client: 'opa-suite' };"
    if opa_anchor not in text:
        raise RuntimeError('Bloco de autenticacao Opa Suite nao encontrado')

    text = text.replace(
        opa_anchor,
        opa_anchor + """
    connections.touch({
      id: 'opa-suite',
      name: 'Opa Suite',
      clientId: 'opa-suite',
      authType: 'token',
    });""",
        1,
    )

    oauth_pattern = re.compile(
        r"(?P<indent>[ \t]*)req\.mcpUser\s*=\s*\{\s*sub:\s*payload\.sub,\s*client:\s*payload\.azp\s*\}\s*;"
    )
    oauth_match = oauth_pattern.search(text)
    if not oauth_match:
        raise RuntimeError('Bloco OAuth/Keycloak nao encontrado no server.js')

    indent = oauth_match.group('indent')
    oauth_replacement = oauth_match.group(0) + f"""
{indent}const clientId = String(payload.azp || 'oauth-client');
{indent}connections.touch({{
{indent}  id: clientId,
{indent}  name: clientId === 'chatgpt-mcp' ? 'ChatGPT' : clientId,
{indent}  clientId,
{indent}  authType: 'oauth',
{indent}}});"""
    text = text[:oauth_match.start()] + oauth_replacement + text[oauth_match.end():]

    return text

def patch_compose(text: str) -> str:
    if './runtime:/data' in text:
        return text

    lines = text.splitlines()
    volume_idx = None
    secret_idx = None
    indent = None

    for i, line in enumerate(lines):
        if re.match(r'^\s+volumes:\s*$', line):
            volume_idx = i
            continue
        if volume_idx is not None and i > volume_idx:
            if re.match(r'^\s+-\s+\./[^:]+:/run/secrets/', line):
                secret_idx = i
                indent = re.match(r'^(\s*)', line).group(1)
                break
            if line and not line.startswith(' '):
                break

    if secret_idx is None or indent is None:
        raise RuntimeError('Bloco volumes/secret esperado nao encontrado no docker-compose.yml')

    lines.insert(secret_idx + 1, indent + '- ./runtime:/data')
    return '\n'.join(lines) + ('\n' if text.endswith('\n') else '')

def patch_dockerfile(text: str) -> str:
    if 'connection-registry.js' in text:
        return text

    lines = text.splitlines()
    for i, line in enumerate(lines):
        if line.strip().startswith('COPY ') and 'server.js' in line and 'auth.js' in line:
            stripped = line.rstrip()
            if stripped.endswith('./'):
                prefix = stripped[:-2].rstrip()
                lines[i] = prefix + ' connection-registry.js ./'
                return '\n'.join(lines) + ('\n' if text.endswith('\n') else '')

    # Fallback seguro: copia o modulo em uma instrucao separada.
    for i, line in enumerate(lines):
        if line.strip().startswith('COPY ') and 'server.js' in line:
            lines.insert(i + 1, 'COPY connection-registry.js ./')
            return '\n'.join(lines) + ('\n' if text.endswith('\n') else '')

    raise RuntimeError('Instrucao COPY do server.js nao encontrada no Dockerfile')


def patch_configuration(text: str) -> str:
    if 'mcp-connections-panel.js' in text and 'mcp-connections.css' in text:
        return text

    anchor = '<!-- Carregar JavaScript externo -->'
    if anchor not in text:
        raise RuntimeError('Ancora de scripts da configuration.php nao encontrada')

    block = CSS_TAG + '\n' + JS_TAG + '\n\n'
    return text.replace(anchor, block + anchor, 1)

def backup_file(src: Path, dst_root: Path, rel: str):
    if not src.exists():
        return
    dst = dst_root / rel
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dst)

def detect_node_ids():
    docker = shutil.which('docker')
    if not docker:
        return 1000, 1000

    uid = run([docker, 'exec', 'jrconect-mcp', 'id', '-u', 'node'], check=False)
    gid = run([docker, 'exec', 'jrconect-mcp', 'id', '-g', 'node'], check=False)

    try:
        u = int((uid.stdout or '').strip())
        g = int((gid.stdout or '').strip())
        return u, g
    except Exception:
        return 1000, 1000

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--acs-root', default=ACS_ROOT_DEFAULT)
    ap.add_argument('--mcp-root', default=MCP_ROOT_DEFAULT)
    ap.add_argument('--backup-dir', default='/root')
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    acs_root = Path(args.acs_root).resolve()
    mcp_root = Path(args.mcp_root).resolve()
    backup_parent = Path(args.backup_dir).resolve()

    server = mcp_root / 'server.js'
    compose = mcp_root / 'docker-compose.yml'
    dockerfile = mcp_root / 'Dockerfile'
    configuration = acs_root / 'configuration.php'

    for p in [server, compose, dockerfile, configuration]:
        if not p.is_file() or p.is_symlink():
            raise RuntimeError('Arquivo invalido ou ausente: ' + str(p))

    lock_path = backup_parent / '.jrc-mcp-connections-install.lock'
    with lock_path.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        payload = {}
        for rel in GIT_FILES:
            payload[rel] = git_show(acs_root, rel)

        server_before = server.read_text(encoding='utf-8')
        compose_before = compose.read_text(encoding='utf-8')
        dockerfile_before = dockerfile.read_text(encoding='utf-8')
        configuration_before = configuration.read_text(encoding='utf-8')

        server_after = patch_server(server_before)
        compose_after = patch_compose(compose_before)
        dockerfile_after = patch_dockerfile(dockerfile_before)
        configuration_after = patch_configuration(configuration_before)

        node_check(server_after, 'server.js')
        node_check(payload['mcp/connection-registry.js'].decode('utf-8'), 'connection-registry.js')
        node_check(payload['assets/js/mcp-connections-panel.js'].decode('utf-8'), 'mcp-connections-panel.js')
        php_check(payload['api/mcp-connections.php'].decode('utf-8'), 'api/mcp-connections.php')
        php_check(configuration_after, 'configuration.php')

        print('VALIDADO: conexoes autenticadas do JR Conect MCPv2.')
        print('server.js sera patchado sem substituir wiki-tools.js ou outros imports locais.')
        print('docker-compose recebera somente o volume ./runtime:/data.')
        print('Dockerfile passara a copiar connection-registry.js sem remover wiki-tools.js.')
        print('ACS recebera painel Servidores conectados ao MCP.')
        print('Nenhum token, segredo ou identificador pessoal sera exibido.')
        print('Ferramenta MCP nova: listar_servidores.')

        if args.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado e nenhum container reiniciado.')
            return

        backup = Path(tempfile.mkdtemp(
            prefix='jrc-mcp-connections-' +
                   datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + '-',
            dir=str(backup_parent),
        ))
        os.chmod(backup, 0o700)

        backup_file(server, backup, 'opt/jrconect-mcp/server.js')
        backup_file(compose, backup, 'opt/jrconect-mcp/docker-compose.yml')
        backup_file(dockerfile, backup, 'opt/jrconect-mcp/Dockerfile')
        backup_file(mcp_root / 'connection-registry.js', backup, 'opt/jrconect-mcp/connection-registry.js')
        backup_file(configuration, backup, 'var/www/gacs/configuration.php')
        backup_file(acs_root / 'api/mcp-connections.php', backup, 'var/www/gacs/api/mcp-connections.php')
        backup_file(acs_root / 'assets/css/mcp-connections.css', backup, 'var/www/gacs/assets/css/mcp-connections.css')
        backup_file(acs_root / 'assets/js/mcp-connections-panel.js', backup, 'var/www/gacs/assets/js/mcp-connections-panel.js')
        shutil.copy2(Path(__file__), backup / 'install.py')

        if server.read_text(encoding='utf-8') != server_before:
            raise RuntimeError('server.js mudou durante a instalacao')
        if compose.read_text(encoding='utf-8') != compose_before:
            raise RuntimeError('docker-compose.yml mudou durante a instalacao')
        if dockerfile.read_text(encoding='utf-8') != dockerfile_before:
            raise RuntimeError('Dockerfile mudou durante a instalacao')
        if configuration.read_text(encoding='utf-8') != configuration_before:
            raise RuntimeError('configuration.php mudou durante a instalacao')

        # MCP files
        atomic_write(server, server_after.encode('utf-8'), server.stat())
        atomic_write(compose, compose_after.encode('utf-8'), compose.stat())
        atomic_write(dockerfile, dockerfile_after.encode('utf-8'), dockerfile.stat())
        registry_path = mcp_root / 'connection-registry.js'
        atomic_write(
            registry_path,
            payload['mcp/connection-registry.js'],
            registry_path.stat() if registry_path.exists() else None,
        )

        runtime = mcp_root / 'runtime'
        runtime.mkdir(mode=0o755, exist_ok=True)
        uid, gid = detect_node_ids()
        if os.geteuid() == 0:
            os.chown(runtime, uid, gid)
        os.chmod(runtime, 0o755)

        # ACS files
        api_path = acs_root / 'api/mcp-connections.php'
        css_path = acs_root / 'assets/css/mcp-connections.css'
        js_path = acs_root / 'assets/js/mcp-connections-panel.js'

        atomic_write(
            api_path,
            payload['api/mcp-connections.php'],
            api_path.stat() if api_path.exists() else None,
        )
        atomic_write(
            css_path,
            payload['assets/css/mcp-connections.css'],
            css_path.stat() if css_path.exists() else None,
        )
        atomic_write(
            js_path,
            payload['assets/js/mcp-connections-panel.js'],
            js_path.stat() if js_path.exists() else None,
        )
        atomic_write(configuration, configuration_after.encode('utf-8'), configuration.stat())

        # Revalidate on disk
        node_check(server.read_text(encoding='utf-8'), 'server.js')
        node_check(registry_path.read_text(encoding='utf-8'), 'connection-registry.js')
        php_check(api_path.read_text(encoding='utf-8'), 'api/mcp-connections.php')
        php_check(configuration.read_text(encoding='utf-8'), 'configuration.php')

        docker = shutil.which('docker')
        if not docker:
            raise RuntimeError('Docker nao encontrado apos gravacao; use o backup para rollback')

        run([docker, 'compose', 'config', '--quiet'], cwd=mcp_root)
        run([docker, 'compose', 'up', '-d', '--build'], cwd=mcp_root)

        health = run(
            ['curl', '-fsS', 'http://127.0.0.1:3100/health'],
            check=False,
        )
        if health.returncode != 0 or '"ok"' not in health.stdout:
            raise RuntimeError(
                'Container subiu, mas /health falhou. Backup: ' + str(backup)
            )

        connections = run(
            ['curl', '-fsS', 'http://127.0.0.1:3100/connections'],
            check=False,
        )
        if connections.returncode != 0 or '"success":true' not in connections.stdout:
            raise RuntimeError(
                'Rota local /connections nao respondeu como esperado. Backup: ' + str(backup)
            )

        print('INSTALADO: registro de conexoes MCP ativo.')
        print('Health: ' + health.stdout.strip())
        print('Connections: ' + connections.stdout.strip()[:500])
        print('Backup: ' + str(backup))
        print('Clientes ja conectados aparecerao apos a proxima requisicao autenticada.')

if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('CANCELADO: ' + str(exc), file=sys.stderr)
        sys.exit(1)
