#!/usr/bin/env python3
"""Apply JR CONECT provisioning V2: fast local-first loading + standard dark layout."""
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

BRANCH = 'origin/feat/provisionamento-standard-v2-20261030'
PHP_FILE = 'provisionamento-onu.php'
JS_FILE = 'assets/js/provisionamento-onu.js'
CSS_FILE = 'assets/css/provisionamento-standard-v2.css'
CSS_TAG = '<link rel="stylesheet" href="/assets/css/provisionamento-standard-v2.css?v=20261030-2">'

HELPERS = r"""
/* JRC_PROVISION_V2 */
function jrcProvNormSerial(value) {
    return String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
}

function jrcProvNormalizeLocal(d) {
    var serial = String(d.serial_number || '');
    return {
        serial: serial,
        acs: {
            device_id: d.device_id || '',
            serial_number: serial,
            product_class: d.product_class || '',
            last_inform: d.last_inform || '',
            status: d.status || ''
        },
        ixc: {}
    };
}

async function jrcProvLoadLocalInventory() {
    var out = [];
    var skip = 0;
    var limit = 500;

    while (true) {
        var response = await fetch(
            '/api/get-devices.php?limit=' + limit + '&skip=' + skip,
            {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }
        );

        var data = await response.json();

        if (!response.ok || !data || !data.success) {
            throw new Error((data && data.message) || 'Inventario local indisponivel');
        }

        var chunk = Array.isArray(data.devices) ? data.devices : [];
        out = out.concat(chunk.map(jrcProvNormalizeLocal));

        if (!data.hasMore || chunk.length === 0) break;

        skip += chunk.length;
        if (skip > 50000) {
            throw new Error('Limite de seguranca do inventario atingido');
        }
    }

    return out;
}

function jrcProvApplyDiscovery(data) {
    if (!data || !data.success) return false;

    var list = Array.isArray(data.devices) ? data.devices : [];
    if (!list.length) return false;

    devices = list;
    page = 1;
    render();
    return true;
}

function jrcProvReadDiscoveryCache() {
    try {
        var raw = sessionStorage.getItem('jrc.provision.discovery.v2');
        if (!raw) return null;

        var cached = JSON.parse(raw);
        if (!cached || !cached.savedAt || !cached.data) return null;

        if ((Date.now() - Number(cached.savedAt)) > (5 * 60 * 1000)) {
            sessionStorage.removeItem('jrc.provision.discovery.v2');
            return null;
        }

        return cached.data;
    } catch (e) {
        return null;
    }
}

function jrcProvSaveDiscoveryCache(data) {
    try {
        sessionStorage.setItem(
            'jrc.provision.discovery.v2',
            JSON.stringify({
                savedAt: Date.now(),
                data: data
            })
        );
    } catch (e) {
        console.debug('[PROVISIONAMENTO] Cache discovery indisponivel:', e);
    }
}

async function jrcProvLoadDiscovery(force) {
    if (!force) {
        var cached = jrcProvReadDiscoveryCache();
        if (cached && jrcProvApplyDiscovery(cached)) {
            return;
        }
    }

    var response = await fetch(
        '/api/discovery-ixc-tr069.php',
        {
            cache: force ? 'no-store' : 'default',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }
    );

    var data = await response.json();

    if (!response.ok || !data || !data.success) {
        throw new Error((data && data.message) || 'Falha na consulta IXC/ACS');
    }

    jrcProvSaveDiscoveryCache(data);
    jrcProvApplyDiscovery(data);
}

function jrcProvScheduleDiscovery() {
    var run = function () {
        jrcProvLoadDiscovery(false).catch(function (error) {
            console.warn('[PROVISIONAMENTO] Complemento IXC/ACS em background:', error);
        });
    };

    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(run, { timeout: 1800 });
    } else {
        window.setTimeout(run, 900);
    }
}
"""

NEW_LOAD = r"""async function load(forceDiscovery) {
    var force = forceDiscovery === true;
    var refresh = $('onu-refresh');
    var rows = $('onu-rows');

    if (refresh) refresh.disabled = true;

    if (rows) {
        rows.innerHTML =
            '<tr><td colspan="8" class="onu-empty">Carregando inventario local...</td></tr>';
    }

    var localLoaded = false;

    try {
        var localDevices = await jrcProvLoadLocalInventory();
        devices = localDevices;
        page = 1;
        render();
        localLoaded = true;
    } catch (error) {
        console.warn('[PROVISIONAMENTO] Inventario local indisponivel:', error);
    }

    try {
        if (force) {
            await jrcProvLoadDiscovery(true);
        } else if (localLoaded) {
            jrcProvScheduleDiscovery();
        } else {
            await jrcProvLoadDiscovery(false);
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

def git_show(root: Path, rel: str) -> bytes:
    p = subprocess.run(
        ['git', '-c', 'safe.directory=' + str(root), 'show', BRANCH + ':' + rel],
        cwd=str(root),
        capture_output=True,
    )
    if p.returncode:
        raise RuntimeError(
            'git show falhou para ' + rel + ': ' +
            p.stderr.decode(errors='ignore')[:1000]
        )
    return p.stdout

def run(cmd):
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout or '')[:3000])
    return p

def php_check(text: str, label: str):
    php = shutil.which('php')
    if not php:
        raise RuntimeError('PHP CLI nao encontrado para validar ' + label)
    with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False, encoding='utf-8') as f:
        f.write(text)
        tmp = f.name
    try:
        run([php, '-l', tmp])
    finally:
        os.unlink(tmp)

def node_check(text: str, label: str):
    node = shutil.which('node')
    if not node:
        raise RuntimeError('Node.js nao encontrado para validar ' + label)
    with tempfile.NamedTemporaryFile('w', suffix='.js', delete=False, encoding='utf-8') as f:
        f.write(text)
        tmp = f.name
    try:
        run([node, '--check', tmp])
    finally:
        os.unlink(tmp)

def find_async_function(text: str, name: str):
    m = re.search(r'\basync\s+function\s+' + re.escape(name) + r'\s*\([^)]*\)\s*\{', text)
    if not m:
        raise RuntimeError('Funcao async ' + name + '() nao encontrada.')

    start = m.start()
    brace = text.find('{', m.start())
    depth = 0
    quote = None
    escape = False
    i = brace

    while i < len(text):
        ch = text[i]

        if quote is not None:
            if escape:
                escape = False
            elif ch == '\\':
                escape = True
            elif ch == quote:
                quote = None
            i += 1
            continue

        if ch in ("'", '"') or ch == chr(96):
            quote = ch
            i += 1
            continue

        if ch == '{':
            depth += 1
        elif ch == '}':
            depth -= 1
            if depth == 0:
                return start, i + 1

        i += 1

    raise RuntimeError('Fim da funcao ' + name + '() nao encontrado.')

def patch_php(text: str):
    changed = 0

    if CSS_TAG not in text:
        anchor = '<header class="onu-title">'
        pos = text.find(anchor)
        if pos < 0:
            anchor = '<div class="onu-shell">'
            pos = text.find(anchor)
        if pos < 0:
            raise RuntimeError('Estrutura principal do provisionamento nao encontrada.')

        text = text[:pos] + CSS_TAG + '\n' + text[pos:]
        changed += 1

    text, count = re.subn(
        r'<script\s+src=["\']/assets/js/provisionamento-onu\.js[^"\']*["\']\s*></script>',
        '<script src="/assets/js/provisionamento-onu.js?v=20261030-2"></script>',
        text,
        count=1,
        flags=re.IGNORECASE,
    )
    changed += count

    return text, changed

def patch_js(text: str):
    if 'JRC_PROVISION_V2' in text:
        return text, 0

    required = [
        'async function load',
        '/api/discovery-ixc-tr069.php',
        'onu-refresh',
        'render',
    ]
    missing = [item for item in required if item not in text]
    if missing:
        raise RuntimeError(
            'Estrutura inesperada em provisionamento-onu.js: ' + ', '.join(missing)
        )

    start, end = find_async_function(text, 'load')
    text = text[:start] + HELPERS + '\n' + NEW_LOAD + text[end:]

    patterns = [
        r"\$\(\s*['\"]onu-refresh['\"]\s*\)\.addEventListener\(\s*['\"]click['\"]\s*,\s*load\s*\)",
        r"document\.getElementById\(\s*['\"]onu-refresh['\"]\s*\)\.addEventListener\(\s*['\"]click['\"]\s*,\s*load\s*\)",
    ]

    replaced = False
    for pattern in patterns:
        text2, count = re.subn(
            pattern,
            "$('onu-refresh').addEventListener('click', () => load(true))",
            text,
            count=1,
        )
        if count:
            text = text2
            replaced = True
            break

    if not replaced:
        # Some production revisions already use a wrapper.
        if "load(true)" not in text:
            raise RuntimeError('Listener do botao Atualizar nao localizado.')

    return text, 1

def atomic_write(path: Path, data: bytes, meta=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix='.jrc-prov-v2-', dir=str(path.parent))
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

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--root', default='/var/www/gacs')
    ap.add_argument('--backup-dir', default='/root')
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    root = Path(args.root).resolve()
    backup_parent = Path(args.backup_dir).resolve()

    php_path = root / PHP_FILE
    js_path = root / JS_FILE
    css_path = root / CSS_FILE

    for p in (php_path, js_path):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError('Arquivo nao encontrado ou invalido: ' + str(p))

    lock_path = backup_parent / '.jrc-provisionamento-v2.lock'
    with lock_path.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        php_before = php_path.read_text(encoding='utf-8')
        js_before = js_path.read_text(encoding='utf-8')

        php_after, php_changes = patch_php(php_before)
        js_after, js_changes = patch_js(js_before)
        css_data = git_show(root, CSS_FILE)

        php_check(php_after, PHP_FILE)
        node_check(js_after, JS_FILE)

        print('VALIDADO: Provisionamento V2 JR CONECT ACS.')
        print('Dashboard e Dispositivos NAO serao alterados.')
        print('Layout: preto/cinza, removendo fundos azuis da pagina.')
        print('Vermelho permanece somente em acoes e estados selecionados.')
        print('Carregamento: inventario local primeiro; discovery IXC/ACS em background.')
        print('Botao Atualizar continua forçando uma consulta completa.')
        print('Cache da discovery: 5 minutos por sessao.')
        print(f'Alteracoes previstas: php={php_changes}, js={js_changes}, css=1.')

        if args.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        backup = Path(tempfile.mkdtemp(
            prefix='gacs-provisionamento-v2-' +
                   datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + '-',
            dir=str(backup_parent),
        ))
        os.chmod(backup, 0o700)

        (backup / 'assets/js').mkdir(parents=True, exist_ok=True)
        (backup / 'assets/css').mkdir(parents=True, exist_ok=True)

        shutil.copy2(php_path, backup / PHP_FILE)
        shutil.copy2(js_path, backup / JS_FILE)
        if css_path.exists():
            shutil.copy2(css_path, backup / CSS_FILE)
        shutil.copy2(Path(__file__), backup / 'install.py')

        if php_path.read_text(encoding='utf-8') != php_before:
            raise RuntimeError('provisionamento-onu.php mudou durante a instalacao.')
        if js_path.read_text(encoding='utf-8') != js_before:
            raise RuntimeError('provisionamento-onu.js mudou durante a instalacao.')

        atomic_write(php_path, php_after.encode('utf-8'), php_path.stat())
        atomic_write(js_path, js_after.encode('utf-8'), js_path.stat())
        atomic_write(css_path, css_data, css_path.stat() if css_path.exists() else None)

        php_check(php_path.read_text(encoding='utf-8'), PHP_FILE)
        node_check(js_path.read_text(encoding='utf-8'), JS_FILE)

        print('INSTALADO: Provisionamento V2 aplicado.')
        print('Backup: ' + str(backup))

if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('CANCELADO: ' + str(exc), file=sys.stderr)
        sys.exit(1)
