#!/usr/bin/env python3
"""Install JR CONECT dashboard visual reference theme safely."""
import argparse, datetime, fcntl, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

BRANCH='origin/feat/dashboard-visual-reference-20261029'
CSS_REL='assets/css/dashboard-reference-dark.css'
PAGE_REL='dashboard.php'
TAG='<link rel="stylesheet" href="/assets/css/dashboard-reference-dark.css?v=20261029-1">'

def git_show(root, rel):
    p=subprocess.run(
        ['git','-c','safe.directory='+str(root),'show',BRANCH+':'+rel],
        cwd=str(root),capture_output=True
    )
    if p.returncode:
        raise RuntimeError('git show falhou para '+rel+': '+p.stderr.decode(errors='ignore')[:500])
    return p.stdout

def check_php(data):
    exe=shutil.which('php')
    if not exe:
        raise RuntimeError('php ausente')
    with tempfile.NamedTemporaryFile(suffix='.php') as f:
        f.write(data); f.flush()
        p=subprocess.run([exe,'-l',f.name],capture_output=True,text=True)
    if p.returncode:
        raise RuntimeError((p.stderr or p.stdout)[:1200])

def atomic(path,data,meta=None):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.jrc-dashboard-theme-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data); out.flush(); os.fsync(out.fileno())
            if meta:
                os.fchmod(out.fileno(),stat.S_IMODE(meta.st_mode))
                if os.geteuid()==0:
                    os.fchown(out.fileno(),meta.st_uid,meta.st_gid)
            else:
                os.fchmod(out.fileno(),0o644)
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--root',default='/var/www/gacs')
    ap.add_argument('--backup-dir',default='/root')
    ap.add_argument('--dry-run',action='store_true')
    a=ap.parse_args()

    root=Path(a.root).resolve()
    parent=Path(a.backup_dir).resolve()
    page=root/PAGE_REL

    if not page.is_file() or page.is_symlink():
        raise RuntimeError('dashboard.php nao encontrado')

    with (parent/'.jrc-dashboard-theme.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)

        css=git_show(root,CSS_REL)
        page_before=page.read_bytes()
        text=page_before.decode('utf-8')

        # Confirma que estamos na dashboard JR CONECT esperada.
        markers=['jrc-dashboard','jrc-dashboard-title','recent-devices']
        missing=[m for m in markers if m not in text]
        if missing:
            raise RuntimeError('estrutura da dashboard inesperada: '+', '.join(missing))

        if TAG not in text:
            anchors=[
                '<link rel="stylesheet" href="/assets/css/jrc-inventory.css?v=1">',
                '</style>'
            ]
            if anchors[0] in text:
                text=text.replace(anchors[0],anchors[0]+'\n'+TAG,1)
            elif anchors[1] in text:
                text=text.replace(anchors[1],anchors[1]+'\n'+TAG,1)
            else:
                raise RuntimeError('ponto seguro para incluir CSS nao encontrado')

        page_after=text.encode('utf-8')
        check_php(page_after)

        print('VALIDADO: tema visual da dashboard JR CONECT.')
        print('Somente CSS sera adicionado; APIs, graficos e consultas permanecem intactos.')

        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        if page.read_bytes()!=page_before:
            raise RuntimeError('dashboard.php mudou durante a instalacao')

        backup=Path(tempfile.mkdtemp(
            prefix='gacs-dashboard-theme-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',
            dir=str(parent)
        ))
        os.chmod(backup,0o700)
        (backup/'dashboard.php').write_bytes(page_before)

        css_dest=root/CSS_REL
        if css_dest.is_file():
            b=backup/CSS_REL
            b.parent.mkdir(parents=True,exist_ok=True)
            b.write_bytes(css_dest.read_bytes())

        atomic(page,page_after,page.stat())
        meta=css_dest.stat() if css_dest.exists() else None
        atomic(css_dest,css,meta)

        print('INSTALADO: novo visual da dashboard habilitado.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try:
        main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr)
        sys.exit(1)
