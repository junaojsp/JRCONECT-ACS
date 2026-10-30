#!/usr/bin/env python3
"""Apply JR CONECT provisioning visual V3 neutral theme only."""
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

BRANCH='origin/feat/provisionamento-standard-v2-20261030'
CSS='assets/css/provisionamento-standard-v2.css'
PHP='provisionamento-onu.php'
OLD='provisionamento-standard-v2.css?v=20261030-2'
NEW='provisionamento-standard-v2.css?v=20261030-3'

def git_show(root, rel):
    p=subprocess.run(
        ['git','-c','safe.directory='+str(root),'show',BRANCH+':'+rel],
        cwd=str(root),capture_output=True
    )
    if p.returncode:
        raise RuntimeError(p.stderr.decode(errors='ignore')[:1200])
    return p.stdout

def php_lint(text):
    php=shutil.which('php')
    if not php: raise RuntimeError('PHP CLI ausente')
    with tempfile.NamedTemporaryFile('w',suffix='.php',delete=False,encoding='utf-8') as f:
        f.write(text); tmp=f.name
    try:
        p=subprocess.run([php,'-l',tmp],capture_output=True,text=True)
        if p.returncode:
            raise RuntimeError((p.stderr or p.stdout)[:1500])
    finally:
        os.unlink(tmp)

def atomic_write(path,data,meta=None):
    fd,tmp=tempfile.mkstemp(prefix='.jrc-prov-v3-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data); f.flush(); os.fsync(f.fileno())
            if meta is not None:
                os.fchmod(f.fileno(),stat.S_IMODE(meta.st_mode))
                if os.geteuid()==0:
                    os.fchown(f.fileno(),meta.st_uid,meta.st_gid)
            else:
                os.fchmod(f.fileno(),0o644)
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
    backup_parent=Path(a.backup_dir).resolve()
    css_path=root/CSS
    php_path=root/PHP

    if not php_path.is_file(): raise RuntimeError('provisionamento-onu.php nao encontrado')
    if not css_path.is_file(): raise RuntimeError('CSS V2 nao encontrado')

    lock_path=backup_parent/'.jrc-provisionamento-v3.lock'
    with lock_path.open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)

        php_before=php_path.read_text(encoding='utf-8')
        css_before=css_path.read_bytes()
        css_after=git_show(root,CSS)

        php_after=php_before.replace(OLD,NEW)
        if php_after==php_before and NEW not in php_before:
            raise RuntimeError('Link do CSS V2 esperado nao encontrado no provisionamento')

        php_lint(php_after)

        print('VALIDADO: Provisionamento V3 visual.')
        print('Somente Provisionamento sera alterado.')
        print('Fundo estrutural azul sera neutralizado para preto/cinza.')
        print('Cards, filtros, tabela e paginacao seguem o padrao escuro.')
        print('Nenhuma logica de carregamento sera alterada nesta etapa.')

        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        backup=Path(tempfile.mkdtemp(
            prefix='gacs-provisionamento-v3-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',
            dir=str(backup_parent)
        ))
        os.chmod(backup,0o700)

        (backup/'assets/css').mkdir(parents=True,exist_ok=True)
        shutil.copy2(php_path,backup/PHP)
        shutil.copy2(css_path,backup/CSS)
        shutil.copy2(Path(__file__),backup/'install.py')

        if php_path.read_text(encoding='utf-8')!=php_before:
            raise RuntimeError('provisionamento-onu.php mudou durante a instalacao')
        if css_path.read_bytes()!=css_before:
            raise RuntimeError('CSS mudou durante a instalacao')

        atomic_write(php_path,php_after.encode('utf-8'),php_path.stat())
        atomic_write(css_path,css_after,css_path.stat())

        php_lint(php_path.read_text(encoding='utf-8'))

        print('INSTALADO: Provisionamento V3 visual aplicado.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try:
        main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr)
        sys.exit(1)
