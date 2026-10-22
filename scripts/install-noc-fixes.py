"""Selective Debian installer. Default is a preview; --apply writes with backup.
Never runs cron jobs, accesses IXC/GenieACS, or changes login/dashboard/config credentials.
"""
import argparse
import datetime
import json
import os
import pathlib
import re
import stat
import subprocess
import tempfile
from types import SimpleNamespace

SOURCE = pathlib.Path(__file__).resolve().parents[1]
NOC_FILES = ['lib/NocGenieACS.php', 'lib/NocIxcOptical.php',
             'api/dashboard-noc-data.php', 'assets/js/dashboard-noc.js', 'dashboard-noc.php']


def safe_path(root, relative):
    path = root / relative
    if path.is_symlink() or not path.resolve().is_relative_to(root):
        raise RuntimeError('Unsafe destination path: ' + relative)
    if not path.parent.is_dir():
        raise RuntimeError('Missing application directory: ' + str(path.parent))
    return path


def plan(root):
    changes = {p: (SOURCE / p).read_bytes() for p in NOC_FILES}
    config = safe_path(root, 'lib/IXCConfig.php')
    if not config.exists():
        changes['lib/IXCConfig.php'] = (SOURCE / 'lib/IXCConfig.php').read_bytes()
    elif 'function getIxcConfig(' not in config.read_text(encoding='utf-8'):
        raise RuntimeError('Existing IXCConfig.php needs manual compatibility review.')
    for relative in ['cron/device-monitor.php', 'cron/webhook-monitor.php']:
        path = safe_path(root, relative)
        if not path.is_file():
            continue
        original = path.read_text(encoding='utf-8')
        updated = re.sub(r'(?m)^(\s*\*\s+)\*/(\d+)', r'\g<1>0-59/\2', original)
        if updated != original:
            changes[relative] = updated.encode('utf-8')
    relative = 'api/get-onu-optical.php'
    path = safe_path(root, relative)
    if path.exists():
        original = path.read_text(encoding='utf-8')
        if re.search(r'\$ixcToken\s*=\s*[\'\"]', original):
            # Migrate only the known legacy configuration blocks; preserve local logic.
            start = original.find('$ixcBaseUrl =')
            end_marker = '/* =========================================================\n   RESPOSTA JSON'
            validation = "    if (\n        $ixcToken === ''"
            next_marker = '    /* =====================================================\n       CONSULTAR GENIEACS'
            if start < 0 or original.count(end_marker) != 1 or original.count(validation) != 1 or original.count(next_marker) != 1:
                raise RuntimeError('Legacy optical configuration differs; manual migration required.')
            end = original.index(end_marker, start)
            updated = original[:start] + "require_once __DIR__ . '/../lib/IXCConfig.php';\n\n\n" + original[end:]
            start = updated.index(validation)
            end = updated.index(next_marker, start)
            updated = updated[:start] + ("    $ixcConfig = getIxcConfig();\n"
                "    $ixcBaseUrl = $ixcConfig['base_url'];\n"
                "    $ixcToken = $ixcConfig['token'];\n\n\n") + updated[end:]
            changes[relative] = updated.encode('utf-8')
    return {p: data for p, data in changes.items()
            if not safe_path(root, p).exists() or safe_path(root, p).read_bytes() != data}


def replace(path, data, metadata=None):
    fd, name = tempfile.mkstemp(prefix='.noc-', dir=path.parent)
    temp = pathlib.Path(name)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        if metadata is not None:
            if hasattr(os, 'chown'):
                os.chown(temp, metadata.st_uid, metadata.st_gid)
            os.chmod(temp, stat.S_IMODE(metadata.st_mode))
        else:
            parent = path.parent.stat()
            if hasattr(os, 'chown'):
                os.chown(temp, parent.st_uid, parent.st_gid)
            os.chmod(temp, 0o644)
        os.replace(temp, path)
    finally:
        if temp.exists():
            temp.unlink()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--target', default='/var/www/gacs')
    parser.add_argument('--backup-root', default='/var/backups')
    parser.add_argument('--php', default='php')
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--restore', help='Restore the files listed in a private installer backup')
    args = parser.parse_args()
    root = pathlib.Path(args.target).resolve()
    if root == SOURCE or not (root / 'config/config.php').is_file():
        raise RuntimeError('Target must be an installed application, separate from the source checkout.')
    if args.restore:
        backup = pathlib.Path(args.restore).resolve()
        manifest = json.loads((backup / 'manifest.json').read_text())
        allowed = set(NOC_FILES + ['lib/IXCConfig.php', 'cron/device-monitor.php',
                                  'cron/webhook-monitor.php', 'api/get-onu-optical.php'])
        if manifest['target'] != str(root) or not set(manifest['files']).issubset(allowed):
            raise RuntimeError('Backup does not match this application.')
        for relative, info in manifest['files'].items():
            path = safe_path(root, relative)
            if info['existed']:
                metadata = SimpleNamespace(st_mode=info['mode'], st_uid=info['uid'], st_gid=info['gid'])
                replace(path, (backup / relative).read_bytes(), metadata)
            elif path.exists():
                path.unlink()
        print('Previous files restored. Private IXC credentials were not changed.')
        return
    changes = plan(root)
    for relative, content in changes.items():
        if relative.endswith('.php'):
            checked = subprocess.run([args.php, '-l'], input=content, capture_output=True)
            if checked.returncode:
                raise RuntimeError('PHP syntax check failed: ' + relative)
    print('Validated changes:')
    for relative in changes:
        print('  ' + relative)
    if not args.apply:
        print('Preview only. Run again with --apply after configuring the private IXC token.')
        return
    if not changes:
        print('Already up to date.')
        return
    if 'api/get-onu-optical.php' in changes:
        reader = root / 'lib/IXCConfig.php'
        if not reader.exists():
            reader = SOURCE / 'lib/IXCConfig.php'
        code = 'try { require $argv[1]; getIxcConfig(); } catch (Throwable $e) { exit(2); }'
        checked = subprocess.run([args.php, '-r', code, str(reader)], capture_output=True)
        if checked.returncode:
            raise RuntimeError('Configure private IXC credentials before migrating the legacy optical endpoint.')
    parent = pathlib.Path(args.backup_root).resolve()
    if parent.is_relative_to(root):
        raise RuntimeError('Backup must be outside the public application directory.')
    stamp = datetime.datetime.now().strftime('%Y%m%d-%H%M%S-%f')
    backup = parent / ('jrc-noc-' + stamp)
    backup.mkdir(parents=True, mode=0o700 if os.name == 'posix' else 0o777)
    before = {}
    for relative in changes:
        path = safe_path(root, relative)
        before[relative] = (path.read_bytes(), path.stat()) if path.exists() else (None, None)
        if path.exists():
            saved = backup / relative
            saved.parent.mkdir(parents=True, exist_ok=True, mode=0o700 if os.name == 'posix' else 0o777)
            saved.write_bytes(before[relative][0])
            os.chmod(saved, 0o600)
    manifest = {p: {'existed': data is not None, 'mode': stat.S_IMODE(info.st_mode) if info else None,
                    'uid': getattr(info, 'st_uid', None), 'gid': getattr(info, 'st_gid', None)}
                for p, (data, info) in before.items()}
    (backup / 'manifest.json').write_text(json.dumps({'target': str(root), 'files': manifest}, indent=2))
    installed = []
    try:
        # Dependencies first; publish the endpoint after the new clients/config reader.
        for relative in sorted(changes, key=lambda p: (not p.startswith('lib/'), p)):
            replace(safe_path(root, relative), changes[relative], before[relative][1])
            installed.append(relative)
    except Exception:
        for relative in reversed(installed):
            path = safe_path(root, relative)
            data, metadata = before[relative]
            if data is None:
                path.unlink()
            else:
                replace(path, data, metadata)
        raise
    print('Installed successfully. Private backup: ' + str(backup))
    print('No login, current dashboard, configuration credentials or running services were changed.')


if __name__ == '__main__':
    main()
