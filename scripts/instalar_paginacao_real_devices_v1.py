#!/usr/bin/env python3
"""Install real server-side pagination for the JR CONECT ACS device inventory."""
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

BRANCH = 'origin/feat/devices-real-pagination-v1-20261030'
FILES = [
    ('api/get-devices-page.php', 'php'),
    ('assets/js/devices-server-pagination.js', 'js'),
]
SCRIPT_TAG = '<script src="/assets/js/devices-server-pagination.js?v=20261030-1"></script>'

def git_show(root: Path, rel: str) -> bytes:
    p = subprocess.run(
        ['git', '-c', 'safe.directory=' + str(root), 'show', BRANCH + ':' + rel],
        cwd=str(root),
        capture_output=True,
    )
    if p.returncode:
        raise RuntimeError('git show falhou para ' + rel + ': ' + p.stderr.decode(errors='ignore')[:500])
    return p.stdout

def check_php(data: bytes) -> None:
    exe = shutil.which('php')
    if not exe:
        raise RuntimeError('php ausente')
    with tempfile.NamedTemporaryFile(suffix='.php') as f:
        f.write(data)
        f.flush()
        p = subprocess.run([exe, '-l', f.name], capture_output=True, text=True)
    if p.returncode:
        raise RuntimeError((p.stderr or p.stdout)[:1600])

def check_js(data: bytes) -> None:
    exe = shutil.which('node')
    if not exe:
        raise RuntimeError('node ausente')
    with tempfile.NamedTemporaryFile(suffix='.js') as f:
        f.write(data)
        f.flush()
        p = subprocess.run([exe, '--check', f.name], capture_output=True, text=True)
    if p.returncode:
        raise RuntimeError((p.stderr or p.stdout)[:1600])

def atomic_write(path: Path, data: bytes, meta=None) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix='.jrc-devices-page-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
            if meta is not None:
                os.fchmod(out.fileno(), stat.S_IMODE(meta.st_mode))
                if os.geteuid() == 0:
                    os.fchown(out.fileno(), meta.st_uid, meta.st_gid)
            else:
                os.fchmod(out.fileno(), 0o644)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def patch_devices_php(data: bytes) -> bytes:
    text = data.decode('utf-8')

    required = [
        'id="items-per-page"',
        'id="devices-table"',
        '/assets/js/devices.js',
    ]
    missing = [m for m in required if m not in text]
    if missing:
        raise RuntimeError('estrutura de devices.php inesperada: ' + ', '.join(missing))

    # Remove "Todos" para impedir que a tela volte a solicitar o parque inteiro.
    text = re.sub(
        r'\s*<option\s+value=["\']0["\'][^>]*>\s*Todos\s*</option>',
        '',
        text,
        count=1,
        flags=re.IGNORECASE,
    )

    if SCRIPT_TAG not in text:
        matches = list(re.finditer(
            r'<script\s+src=["\']/assets/js/devices\.js[^"\']*["\']\s*></script>',
            text,
            flags=re.IGNORECASE,
        ))
        if not matches:
            raise RuntimeError('include de assets/js/devices.js nao encontrado')

        match = matches[-1]
        text = text[:match.end()] + '\n' + SCRIPT_TAG + text[match.end():]

    return text.encode('utf-8')

def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument('--root', default='/var/www/gacs')
    ap.add_argument('--backup-dir', default='/root')
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    root = Path(args.root).resolve()
    backup_parent = Path(args.backup_dir).resolve()
    page = root / 'devices.php'

    if not page.is_file() or page.is_symlink():
        raise RuntimeError('devices.php nao encontrado')

    lock_path = backup_parent / '.jrc-devices-real-pagination.lock'
    with lock_path.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        payload = {}
        for rel, kind in FILES:
            data = git_show(root, rel)
            if kind == 'php':
                check_php(data)
            elif kind == 'js':
                check_js(data)
            payload[rel] = data

        page_before = page.read_bytes()
        page_after = patch_devices_php(page_before)
        check_php(page_after)

        print('VALIDADO: paginacao real de ONUs no servidor.')
        print('A pagina inicial buscara somente 20 ONUs, sem varrer todo o parque.')
        print('Mapa completo sera carregado apenas ao abrir ODP/ODC/OLT/Servidor.')
        print('Opcao Todos sera removida; permanecem 20, 50 e 100 por pagina.')

        if args.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        if page.read_bytes() != page_before:
            raise RuntimeError('devices.php mudou durante a instalacao')

        backup = Path(tempfile.mkdtemp(
            prefix='gacs-devices-real-pagination-' + datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + '-',
            dir=str(backup_parent),
        ))
        os.chmod(backup, 0o700)

        (backup / 'devices.php').write_bytes(page_before)
        (backup / 'install.py').write_bytes(Path(__file__).read_bytes())

        for rel, _ in FILES:
            dest = root / rel
            if dest.is_file():
                b = backup / rel
                b.parent.mkdir(parents=True, exist_ok=True)
                b.write_bytes(dest.read_bytes())

        atomic_write(page, page_after, page.stat())

        for rel, _ in FILES:
            dest = root / rel
            meta = dest.stat() if dest.exists() else None
            atomic_write(dest, payload[rel], meta)

        print('INSTALADO: paginacao real habilitada na tela Equipamentos.')
        print('Auto-refresh agora recarrega somente a pagina atual.')
        print('Busca de serial/MAC/PPPoE e complemento IXC foram preservados.')
        print('Backup: ' + str(backup))

if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('CANCELADO: ' + str(exc), file=sys.stderr)
        sys.exit(1)
