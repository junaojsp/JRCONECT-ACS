#!/usr/bin/env python3
"""Standardize Optical + WAN overview cards to equal columns without changing their content."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_OVERVIEW_EQUAL_CARDS_V1'
REL='assets/css/device-control-fix.css'

CSS=r'''
/* JRC_OVERVIEW_EQUAL_CARDS_V1
   Equal visual weight for Optical + WAN cards on the device overview. */
#overview-content .jrc-optical-wan-row,
#overview-content .acs-optical-wan-row,
#overview-content .jr-optical-wan-row {
    grid-template-columns: minmax(0,1fr) minmax(0,1fr) !important;
    align-items: stretch !important;
}

#overview-content .jrc-optical-wan-row > *,
#overview-content .acs-optical-wan-row > *,
#overview-content .jr-optical-wan-row > * {
    min-width: 0 !important;
    width: 100% !important;
    height: 100% !important;
}

/* Current overview fallback: if the pair is rendered in a generic two-card grid,
   make both children equal without touching other sections. */
#overview-content :is(.acs-optical-history,.jr-optical-history-card),
#overview-content :has(#bandwidth-bars) {
    min-width: 0 !important;
    width: 100% !important;
}

@media (min-width: 900px) {
    #overview-content .jrc-optical-wan-row,
    #overview-content .acs-optical-wan-row,
    #overview-content .jr-optical-wan-row {
        display: grid !important;
        grid-template-columns: repeat(2,minmax(0,1fr)) !important;
        gap: 12px !important;
    }
}

@media (max-width: 899px) {
    #overview-content .jrc-optical-wan-row,
    #overview-content .acs-optical-wan-row,
    #overview-content .jr-optical-wan-row {
        grid-template-columns: 1fr !important;
    }
}
'''

def sha(data): return hashlib.sha256(data).hexdigest()

def patch(before):
    text=before.decode('utf-8')
    if VERSION in text:
        return None
    sep='' if text.endswith('\n') else '\n'
    return (text+sep+CSS).encode('utf-8')

def atomic(path,data,meta):
    fd,name=tempfile.mkstemp(prefix='.jrc-equal-cards-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data); out.flush(); os.fsync(out.fileno())
            os.fchmod(out.fileno(),stat.S_IMODE(meta.st_mode))
            if os.geteuid()==0: os.fchown(out.fileno(),meta.st_uid,meta.st_gid)
        os.replace(name,path)
    finally:
        if os.path.exists(name): os.unlink(name)

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--root',default='/var/www/gacs')
    p.add_argument('--backup-dir',default='/root')
    m=p.add_mutually_exclusive_group()
    m.add_argument('--dry-run',action='store_true')
    m.add_argument('--rollback',type=Path)
    a=p.parse_args()
    parent=a.rollback.resolve().parent if a.rollback else Path(a.backup_dir).resolve()

    with (parent/'.jrc-equal-cards.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)

        if a.rollback:
            b=a.rollback.resolve()
            meta=json.loads((b/'manifest.json').read_text())
            if meta.get('version')!=VERSION: raise RuntimeError('Backup incompativel.')
            path=Path(meta['root'])/REL
            original=(b/'original.css').read_bytes()
            if sha(path.read_bytes())!=meta['after']:
                raise RuntimeError('CSS mudou depois da instalacao; rollback cancelado.')
            atomic(path,original,path.stat())
            print('RESTAURADO: tamanho anterior dos cards.')
            return

        root=Path(a.root).resolve()
        path=root/REL
        if not path.is_file() or path.is_symlink():
            raise RuntimeError('CSS do dispositivo nao encontrado.')

        before=path.read_bytes()
        after=patch(before)
        if after is None:
            print('JA INSTALADO: cards padronizados.')
            return

        print('VALIDADO: somente proporcao/largura dos cards sera alterada.')
        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        if path.read_bytes()!=before:
            raise RuntimeError('Edicao concorrente detectada.')

        backup=Path(tempfile.mkdtemp(
            prefix='gacs-equal-cards-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',
            dir=str(parent)
        ))
        os.chmod(backup,0o700)
        shutil.copy2(path,backup/'original.css')
        shutil.copy2(Path(__file__).resolve(),backup/'install.py')
        (backup/'manifest.json').write_text(json.dumps({
            'version':VERSION,'root':str(root),'before':sha(before),'after':sha(after)
        },indent=2))
        (backup/'changes.diff').write_text(''.join(difflib.unified_diff(
            before.decode().splitlines(True),after.decode().splitlines(True),
            fromfile=REL,tofile=REL
        )))
        atomic(path,after,path.stat())
        ptr=parent/'gacs-equal-cards-ultimo'
        ptr.write_text(str(backup)+'\n'); os.chmod(ptr,0o600)

        print('INSTALADO: cards Optical/WAN padronizados em 50/50.')
        print('Grafico, SSE e coleta de 1s preservados.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr); sys.exit(1)
