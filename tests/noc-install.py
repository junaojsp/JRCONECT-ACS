"""Installer smoke test against synthetic application files only."""
import argparse
import json
import os
import pathlib
import shutil
import subprocess
import tempfile
import uuid

parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
parser.add_argument('--temp-dir')
args = parser.parse_args()
source = pathlib.Path(__file__).resolve().parents[1]
parent = pathlib.Path(args.temp_dir or tempfile.gettempdir()).resolve()
root = parent / ('jrc-install-test-' + uuid.uuid4().hex)
root.mkdir()
app = root / 'app'
app.mkdir()
try:
    for folder in ['api', 'lib', 'assets/js', 'assets/css', 'config', 'cron', 'views/layouts', 'views/device-detail']:
        (app / folder).mkdir(parents=True, exist_ok=True)
    for file in ['config/config.php', 'dashboard.php', 'login.php', 'index.php', 'device-detail.php', 'assets/js/device-detail.js', 'views/device-detail/modals.php', 'api/update-wifi-config.php']:
        (app / file).write_text('<?php /* local content must survive */')
    for themed in ['login.php', 'discovery.php', 'views/layouts/header.php']:
        (app / themed).write_text('<?php /* local access checks and controls */ ?><html><head><title>Local</title></head><body><form id="local-form" method="POST"><input name="username"></form></body></html>')
    (app / 'lib/IXCConfig.php').write_bytes((source / 'lib/IXCConfig.php').read_bytes())
    for file in ['cron/device-monitor.php', 'cron/webhook-monitor.php']:
        text = (source / file).read_text(encoding='utf-8').replace('0-59/5', '*/5')
        (app / file).write_text(text, encoding='utf-8')
    (app / 'api/get-onu-optical.php').write_text('''<?php
$ixcBaseUrl = 'https://example.invalid';
$ixcToken = 'synthetic-old-token';
/* =========================================================
   RESPOSTA JSON
   ========================================================= */
function localCustomization() { return 42; }
try {
    if (
        $ixcToken === ''
    ) { throw new RuntimeException('Missing token'); }
    /* =====================================================
       CONSULTAR GENIEACS
       ===================================================== */
} catch (Throwable $e) {}
''')
    originals = {str(p.relative_to(app)).replace('\\', '/'): p.read_bytes()
                 for p in app.rglob('*') if p.is_file()}
    command = [os.sys.executable, str(source / 'scripts/install-noc-fixes.py'), '--target', str(app),
               '--backup-root', str(root / 'backups'), '--php', args.php, '--theme']
    subprocess.run(command, check=True, stdout=subprocess.DEVNULL)
    assert all((app / p).read_bytes() == b for p, b in originals.items())
    env = dict(os.environ, IXC_BASE_URL='https://example.invalid', IXC_TOKEN='synthetic-new-token')
    subprocess.run(command + ['--apply'], check=True, env=env, stdout=subprocess.DEVNULL)
    for file in ['config/config.php', 'dashboard.php', 'index.php', 'lib/IXCConfig.php', 'device-detail.php', 'assets/js/device-detail.js', 'views/device-detail/modals.php', 'api/update-wifi-config.php']:
        assert (app / file).read_bytes() == originals[file]
    import re
    for themed in ['login.php', 'discovery.php', 'views/layouts/header.php']:
        actual = (app / themed).read_text()
        stripped = re.sub(r'<!-- JRC NOC SHARED THEME -->.*?<!-- /JRC NOC SHARED THEME -->\n', '', actual, flags=re.S)
        assert stripped == originals[themed].decode()
        assert 'id="local-form"' in actual
    migrated = (app / 'api/get-onu-optical.php').read_text()
    assert 'synthetic-old-token' not in migrated and 'localCustomization' in migrated and 'getIxcConfig()' in migrated
    for file in ['api/get-onu-optical.php', 'cron/device-monitor.php', 'cron/webhook-monitor.php']:
        subprocess.run([args.php, '-l', str(app / file)], check=True, stdout=subprocess.DEVNULL)
    backup, = (root / 'backups').glob('jrc-noc-*')
    manifest = json.loads((backup / 'manifest.json').read_text())
    assert not any(p.startswith('config/') for p in manifest['files'])
    subprocess.run(command + ['--apply'], check=True, env=env, stdout=subprocess.DEVNULL)
    assert len(list((root / 'backups').glob('jrc-noc-*'))) == 1
    subprocess.run(command + ['--restore', str(backup)], check=True, stdout=subprocess.DEVNULL)
    actual = {str(p.relative_to(app)).replace('\\', '/'): p.read_bytes()
              for p in app.rglob('*') if p.is_file()}
    assert actual == originals
    print('PASS installer: preview, selective migration, local-file preservation, backup, idempotence and rollback')
finally:
    assert root.resolve().parent == parent and root.name.startswith('jrc-install-test-')
    shutil.rmtree(root)
