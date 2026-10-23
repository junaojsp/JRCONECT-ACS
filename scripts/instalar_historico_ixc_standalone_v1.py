#!/usr/bin/env python3
"""Install standalone IXC WAN history module and include it in device-detail.php."""
import argparse, datetime, fcntl, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_WAN_HISTORY_STANDALONE_IXC_V1'
BRANCH='origin/fix/device-charts-install-20261022-v1'
FILES=[
    ('api/get-wan-consumption-ixc.php','php'),
    ('assets/js/wan-history-ixc.js','js'),
    ('assets/css/wan-history-ixc.css','css'),
]

def git_show(root, rel):
    r=subprocess.run(
        ['git','-c','safe.directory='+str(root),'show',BRANCH+':'+rel],
        cwd=str(root),capture_output=True
    )
    if r.returncode:
        raise RuntimeError('git show falhou para '+rel+': '+r.stderr.decode(errors='ignore')[:400])
    return r.stdout

def check_php(data):
    exe=shutil.which('php')
    if not exe: raise RuntimeError('php ausente')
    with tempfile.NamedTemporaryFile(suffix='.php') as f:
        f.write(data); f.flush()
        r=subprocess.run([exe,'-l',f.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError((r.stderr or r.stdout)[:1200])

def check_js(data):
    exe=shutil.which('node')
    if not exe: raise RuntimeError('node ausente')
    with tempfile.NamedTemporaryFile(suffix='.js') as f:
        f.write(data); f.flush()
        r=subprocess.run([exe,'--check',f.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError((r.stderr or r.stdout)[:1200])

def atomic(path,data,meta=None):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.jrc-wan-history-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data); out.flush(); os.fsync(out.fileno())
            if meta:
                os.fchmod(out.fileno(),stat.S_IMODE(meta.st_mode))
                if os.geteuid()==0: os.fchown(out.fileno(),meta.st_uid,meta.st_gid)
            else:
                os.fchmod(out.fileno(),0o644)
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--root',default='/var/www/gacs')
    ap.add_argument('--backup-dir',default='/root')
    ap.add_argument('--dry-run',action='store_true')
    a=ap.parse_args()

    root=Path(a.root).resolve()
    parent=Path(a.backup_dir).resolve()
    page=root/'device-detail.php'
    if not page.is_file() or page.is_symlink():
        raise RuntimeError('device-detail.php nao encontrado')

    with (parent/'.jrc-wan-history-standalone.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)

        payload={}
        for rel,kind in FILES:
            data=git_show(root,rel)
            if kind=='php': check_php(data)
            elif kind=='js': check_js(data)
            payload[rel]=data

        page_before=page.read_bytes()
        text=page_before.decode('utf-8')

        css_tag='<link rel="stylesheet" href="/assets/css/wan-history-ixc.css?v=20261023-1">'
        js_tag='<script src="/assets/js/wan-history-ixc.js?v=20261023-1"></script>'

        if css_tag not in text:
            anchor='<link rel="stylesheet" href="/assets/css/device-control-fix.css?v=<?php echo time(); ?>">'
            if anchor not in text:
                raise RuntimeError('anchor CSS nao encontrado em device-detail.php')
            text=text.replace(anchor,anchor+'\n'+css_tag,1)

        if js_tag not in text:
            anchor='<script src="/assets/js/device-control-fix.js?v=<?php echo time(); ?>"></script>'
            if anchor not in text:
                raise RuntimeError('anchor JS nao encontrado em device-detail.php')
            text=text.replace(anchor,anchor+'\n'+js_tag,1)

        page_after=text.encode('utf-8')
        check_php(page_after)

        print('VALIDADO: modulo standalone de historico IXC pronto.')
        print('Fonte: PPPoE exibido na aba WAN -> radusuarios -> radusuarios_consumo.')
        print('SSE ao vivo de 1s nao sera alterado.')

        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        if page.read_bytes()!=page_before:
            raise RuntimeError('device-detail.php mudou durante a instalacao')

        backup=Path(tempfile.mkdtemp(
            prefix='gacs-wan-history-standalone-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',
            dir=str(parent)
        ))
        os.chmod(backup,0o700)
        (backup/'device-detail.php').write_bytes(page_before)
        (backup/'manifest.json').write_text(json.dumps({'version':VERSION,'root':str(root)},indent=2))
        (backup/'install.py').write_bytes(Path(__file__).read_bytes())

        for rel,_ in FILES:
            dest=root/rel
            if dest.exists() and dest.is_file():
                b=backup/rel
                b.parent.mkdir(parents=True,exist_ok=True)
                b.write_bytes(dest.read_bytes())

        atomic(page,page_after,page.stat())
        for rel,_ in FILES:
            dest=root/rel
            meta=dest.stat() if dest.exists() else None
            atomic(dest,payload[rel],meta)

        print('INSTALADO: historico IXC standalone habilitado.')
        print('Abra WAN/PPPoE e use 30 dias para comparar com o IXC.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try:
        main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr)
        sys.exit(1)
