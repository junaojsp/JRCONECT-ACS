#!/usr/bin/env python3
"""Install lean GenieACS dashboard data endpoints for JR CONECT ACS."""
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

BRANCH = 'origin/feat/dashboard-lean-data-v1-20261030'

FILES = [
    'lib/GenieACS_DashboardLean.php',
    'api/dashboard-stats.php',
    'api/recent-devices.php',
    'api/uplink-stats.php',
]

EXPECTED = {
    'api/dashboard-stats.php': [
        'dashboard-stats',
        'getDeviceStats',
        'GenieACS_DashboardLean',
    ],
    'api/recent-devices.php': [
        'recent-devices',
        'getDevices',
        'GenieACS_DashboardLean',
    ],
    'api/uplink-stats.php': [
        'uplink-stats',
        'getDevices',
        'GenieACS_DashboardLean',
    ],
}

def git_show(root: Path, rel: str) -> bytes:
    p = subprocess.run(
        ['git', '-c', 'safe.directory=' + str(root), 'show', BRANCH + ':' + rel],
        cwd=str(root),
        capture_output=True,
    )
    if p.returncode:
        raise RuntimeError(
            'git show falhou para ' + rel + ': ' +
            p.stderr.decode(errors='ignore')[:500]
        )
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

def atomic_write(path: Path, data: bytes, meta=None) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix='.jrc-dashboard-lean-', dir=str(path.parent))
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

def validate_current(root: Path) -> None:
    for rel, markers in EXPECTED.items():
        path = root / rel
        if not path.is_file() or path.is_symlink():
            raise RuntimeError(rel + ' nao encontrado ou invalido')

        text = path.read_text(encoding='utf-8', errors='ignore')
        if not any(marker in text for marker in markers):
            raise RuntimeError(
                rel + ' possui estrutura inesperada; instalacao cancelada por seguranca'
            )

def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument('--root', default='/var/www/gacs')
    ap.add_argument('--backup-dir', default='/root')
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    root = Path(args.root).resolve()
    backup_parent = Path(args.backup_dir).resolve()

    if not root.is_dir():
        raise RuntimeError('root do ACS nao encontrado')

    lock_path = backup_parent / '.jrc-dashboard-lean-data.lock'
    with lock_path.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        validate_current(root)

        payload = {}
        for rel in FILES:
            data = git_show(root, rel)
            check_php(data)
            payload[rel] = data

        print('VALIDADO: endpoints enxutos da Dashboard JR CONECT ACS.')
        print('dashboard-stats: contagem via HEAD, sem baixar o parque inteiro.')
        print('recent-devices: GenieACS ordena e retorna somente 10 ONUs.')
        print('uplink-stats: somente campos de RX sao transferidos.')
        print('Nenhum HTML/CSS/JS da Dashboard sera alterado.')

        if args.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        backup = Path(tempfile.mkdtemp(
            prefix='gacs-dashboard-lean-' +
                   datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + '-',
            dir=str(backup_parent),
        ))
        os.chmod(backup, 0o700)

        for rel in FILES:
            src = root / rel
            if src.exists():
                dst = backup / rel
                dst.parent.mkdir(parents=True, exist_ok=True)
                dst.write_bytes(src.read_bytes())

        (backup / 'install.py').write_bytes(Path(__file__).read_bytes())

        for rel in FILES:
            dest = root / rel
            meta = dest.stat() if dest.exists() else None
            atomic_write(dest, payload[rel], meta)

        print('INSTALADO: Dashboard usando consultas enxutas ao GenieACS.')
        print('Layout e comportamento visual preservados.')
        print('Backup: ' + str(backup))

if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('CANCELADO: ' + str(exc), file=sys.stderr)
        sys.exit(1)
