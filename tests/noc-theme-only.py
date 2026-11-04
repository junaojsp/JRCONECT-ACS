"""Verify the visual-only deployment preserves existing application behavior files byte for byte."""
import argparse,json,os,pathlib,shutil,subprocess,uuid
p=argparse.ArgumentParser();p.add_argument('--php',default='php');a=p.parse_args()
source=pathlib.Path(__file__).resolve().parents[1];root=source.parent/('jrc-theme-test-'+uuid.uuid4().hex);root.mkdir();app=root/'app';app.mkdir()
try:
 for d in ['config','assets/css','assets/js','views/layouts','views/device-detail','api','cron']:(app/d).mkdir(parents=True,exist_ok=True)
 for name in ['config/config.php','dashboard.php','devices.php','device-detail.php','assets/js/device-detail.js','views/device-detail/modals.php','api/update-wifi-config.php','api/get-device-detail.php','cron/device-monitor.php']:(app/name).write_text('<?php /* local operational implementation */')
 (app/'api/get-onu-optical.php').write_text("<?php /* local IXC logic */ $ixcToken = 'synthetic-unchanged-token';")
 for name in ['views/layouts/header.php','login.php']:(app/name).write_text('<?php /* local authentication */ ?><html><head></head><body><form action="/original-handler" method="post"></form></body></html>')
 originals={str(p.relative_to(app)).replace('\\','/'):p.read_bytes() for p in app.rglob('*') if p.is_file()}
 command=[os.sys.executable,str(source/'scripts/install-noc-fixes.py'),'--target',str(app),'--backup-root',str(root/'backups'),'--php',a.php,'--theme-only']
 subprocess.run(command,check=True,stdout=subprocess.DEVNULL)
 assert all((app/p).read_bytes()==b for p,b in originals.items())
 subprocess.run(command+['--apply'],check=True,stdout=subprocess.DEVNULL)
 for name,b in originals.items():
  if name not in ['views/layouts/header.php','login.php']:assert (app/name).read_bytes()==b,name
 assert 'action="/original-handler"' in (app/'login.php').read_text()
 assert not (app/'api/noc-devices.php').exists()
 backup,=list((root/'backups').glob('jrc-noc-*'));manifest=json.loads((backup/'manifest.json').read_text())
 assert not any(p.startswith(('api/','lib/','cron/')) for p in manifest['files'])
 subprocess.run(command+['--apply'],check=True,stdout=subprocess.DEVNULL)
 assert len(list((root/'backups').glob('jrc-noc-*')))==1
 subprocess.run(command+['--restore',str(backup)],check=True,stdout=subprocess.DEVNULL)
 assert {str(p.relative_to(app)).replace('\\','/'):p.read_bytes() for p in app.rglob('*') if p.is_file()}==originals
 print('PASS visual-only installer: preview, preserved operational pages/Wi-Fi/APIs/cron/private config, idempotence and rollback')
finally:
 assert root.resolve().parent==source.parent.resolve() and root.name.startswith('jrc-theme-test-');shutil.rmtree(root)
