#!/usr/bin/env python3
"""Install JR CONECT ACS local device inventory cache safely."""
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

BRANCH = 'origin/feat/local-device-inventory-cache-v1-20261030'

PAYLOAD_FILES = [
    'lib/DeviceInventoryCache.php',
    'scripts/sync_device_inventory.php',
    'api/get-devices-cache-shim.php',
    'api/get-devices-page.php',
    'api/dashboard-stats.php',
    'api/recent-devices.php',
    'api/uplink-stats.php',
]

FALLBACKS = {
    'api/get-devices-page.php': 'api/get-devices-page-precache.php',
    'api/dashboard-stats.php': 'api/dashboard-stats-precache.php',
    'api/recent-devices.php': 'api/recent-devices-precache.php',
    'api/uplink-stats.php': 'api/uplink-stats-precache.php',
}

SHIM_BLOCK = """// Prefer local inventory cache when available; fall back to GenieACS otherwise.
$cacheShim = __DIR__ . '/get-devices-cache-shim.php';
if (is_file($cacheShim)) {
    $cacheHandled = require $cacheShim;
    if ($cacheHandled === true) {
        exit;
    }
}
"""

def run(cmd, cwd=None, check=True, capture=True):
    p = subprocess.run(
        cmd,
        cwd=str(cwd) if cwd else None,
        text=True,
        capture_output=capture,
    )
    if check and p.returncode != 0:
        detail = (p.stderr or p.stdout or '')[:3000]
        raise RuntimeError('Falha: ' + ' '.join(cmd) + '\n' + detail)
    return p

def git_show(root: Path, rel: str) -> bytes:
    p = subprocess.run(
        ['git', '-c', 'safe.directory=' + str(root), 'show', BRANCH + ':' + rel],
        cwd=str(root),
        capture_output=True,
    )
    if p.returncode:
        raise RuntimeError(
            'git show falhou para ' + rel + ': ' +
            p.stderr.decode(errors='ignore')[:800]
        )
    return p.stdout

def check_php(data: bytes, label: str) -> None:
    php = shutil.which('php')
    if not php:
        raise RuntimeError('PHP CLI ausente.')
    with tempfile.NamedTemporaryFile(suffix='.php') as f:
        f.write(data)
        f.flush()
        p = subprocess.run([php, '-l', f.name], capture_output=True, text=True)
    if p.returncode:
        raise RuntimeError('PHP inválido em ' + label + ': ' + (p.stderr or p.stdout)[:1800])

def atomic_write(path: Path, data: bytes, meta=None) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix='.jrc-inventory-', dir=str(path.parent))
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

def patch_legacy_get_devices(data: bytes) -> bytes:
    text = data.decode('utf-8')

    if 'get-devices-cache-shim.php' in text:
        return data

    marker = 'requireLogin();'
    pos = text.find(marker)
    if pos < 0:
        raise RuntimeError('api/get-devices.php sem requireLogin(); esperado.')

    insert_at = pos + len(marker)
    text = text[:insert_at] + '\n\n' + SHIM_BLOCK + text[insert_at:]
    return text.encode('utf-8')

def preserve_fallback(root: Path, src_rel: str, dst_rel: str, backup: Path) -> None:
    src = root / src_rel
    dst = root / dst_rel

    if not src.exists():
        raise RuntimeError(src_rel + ' nao encontrado.')

    # Do not overwrite the original fallback on a reinstall.
    if dst.exists():
        return

    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dst)

    b = backup / dst_rel
    b.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(dst, b)

def install_scheduler(root: Path, backup: Path) -> str:
    php = shutil.which('php') or '/usr/bin/php'
    sync = str(root / 'scripts/sync_device_inventory.php')

    systemctl = shutil.which('systemctl')
    if systemctl and Path('/run/systemd/system').exists():
        service_path = Path('/etc/systemd/system/jrc-device-inventory.service')
        timer_path = Path('/etc/systemd/system/jrc-device-inventory.timer')

        for path in [service_path, timer_path]:
            if path.exists():
                dst = backup / ('systemd/' + path.name)
                dst.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(path, dst)

        service = f"""[Unit]
Description=JR CONECT ACS local device inventory sync
After=network-online.target

[Service]
Type=oneshot
User=www-data
WorkingDirectory={root}
ExecStart={php} {sync} --quiet
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7
"""
        timer = """[Unit]
Description=Refresh JR CONECT ACS local device inventory every minute

[Timer]
OnBootSec=30s
OnUnitActiveSec=60s
AccuracySec=10s
Persistent=true

[Install]
WantedBy=timers.target
"""
        atomic_write(service_path, service.encode('utf-8'), service_path.stat() if service_path.exists() else None)
        atomic_write(timer_path, timer.encode('utf-8'), timer_path.stat() if timer_path.exists() else None)

        run([systemctl, 'daemon-reload'], capture=True)
        run([systemctl, 'enable', '--now', 'jrc-device-inventory.timer'], capture=True)
        return 'systemd timer (60s)'

    cron_path = Path('/etc/cron.d/jrc-device-inventory')
    if cron_path.exists():
        dst = backup / 'cron/jrc-device-inventory'
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(cron_path, dst)

    cron = (
        '* * * * * www-data ' + php + ' ' + sync +
        ' --quiet >> /var/log/jrc-device-inventory.log 2>&1\n'
    )
    atomic_write(cron_path, cron.encode('utf-8'), cron_path.stat() if cron_path.exists() else None)
    return 'cron (60s)'

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--root', default='/var/www/gacs')
    ap.add_argument('--backup-dir', default='/root')
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    root = Path(args.root).resolve()
    backup_parent = Path(args.backup_dir).resolve()

    if not root.is_dir():
        raise RuntimeError('Root do ACS nao encontrado: ' + str(root))
    if not (root / 'api/get-devices.php').is_file():
        raise RuntimeError('api/get-devices.php nao encontrado.')
    if not (root / 'config/config.php').is_file():
        raise RuntimeError('config/config.php nao encontrado.')

    lock_path = backup_parent / '.jrc-local-inventory-install.lock'
    with lock_path.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        payload = {}
        for rel in PAYLOAD_FILES:
            data = git_show(root, rel)
            check_php(data, rel)
            payload[rel] = data

        legacy_path = root / 'api/get-devices.php'
        legacy_before = legacy_path.read_bytes()
        legacy_after = patch_legacy_get_devices(legacy_before)
        check_php(legacy_after, 'api/get-devices.php patched')

        print('VALIDADO: inventario local JR CONECT ACS.')
        print('Dashboard e Dispositivos passarao a ler do MySQL local.')
        print('GenieACS permanecera fonte oficial e detalhe/comandos continuam ao vivo.')
        print('Scripts legados em /api/get-devices.php tambem serao atendidos pelo cache.')
        print('Sincronizacao: incremental a cada 60s; reconciliacao completa a cada 30 min.')

        if args.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        backup = Path(tempfile.mkdtemp(
            prefix='gacs-local-inventory-' +
                   datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + '-',
            dir=str(backup_parent),
        ))
        os.chmod(backup, 0o700)

        # Backup all files that will be touched.
        touched = set(PAYLOAD_FILES + ['api/get-devices.php'])
        for rel in touched:
            src = root / rel
            if src.exists():
                dst = backup / rel
                dst.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(src, dst)

        (backup / 'install.py').write_bytes(Path(__file__).read_bytes())

        # Preserve the exact production implementations as fallbacks.
        for src_rel, dst_rel in FALLBACKS.items():
            preserve_fallback(root, src_rel, dst_rel, backup)

        # Install new cache files/endpoints.
        for rel in PAYLOAD_FILES:
            dest = root / rel
            meta = dest.stat() if dest.exists() else None
            atomic_write(dest, payload[rel], meta)

        # Patch legacy endpoint without replacing production-specific code.
        if legacy_path.read_bytes() != legacy_before:
            raise RuntimeError('api/get-devices.php mudou durante a instalacao.')
        atomic_write(legacy_path, legacy_after, legacy_path.stat())

        # Initial full population. Keep old APIs as automatic fallback until this succeeds.
        php = shutil.which('php') or '/usr/bin/php'
        sync = run(
            [php, str(root / 'scripts/sync_device_inventory.php'), '--full'],
            cwd=root,
            capture=True,
        )
        if sync.stdout.strip():
            print(sync.stdout.strip())

        scheduler = install_scheduler(root, backup)

        # Lightweight verification through CLI, no HTTP/session required.
        verify_code = (
            "require '" + str(root / 'config/config.php').replace("'", "\\'") + "';"
            "require '" + str(root / 'lib/DeviceInventoryCache.php').replace("'", "\\'") + "';"
            "$db=getDBConnection();"
            "$m=App\\\\DeviceInventoryCache::meta($db);"
            "$s=App\\\\DeviceInventoryCache::stats($db);"
            "echo json_encode(['meta'=>$m,'stats'=>$s], JSON_UNESCAPED_SLASHES);"
        )
        verify = run([php, '-r', verify_code], cwd=root, capture=True)
        print('VERIFICACAO: ' + verify.stdout.strip())

        print('INSTALADO: inventario local ativo.')
        print('Agendador: ' + scheduler)
        print('Backup: ' + str(backup))
        print('Agora Dashboard/Dispositivos nao precisam varrer o GenieACS no clique.')

if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('CANCELADO: ' + str(exc), file=sys.stderr)
        sys.exit(1)
