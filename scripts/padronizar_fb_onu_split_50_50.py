#!/usr/bin/env python3
"""Equalize the live server optical/WAN pair using the actual local classes .fb-onu-split and .fb-onu."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, tempfile, stat, sys
from pathlib import Path

VERSION='JRC_FB_ONU_SPLIT_EQUAL_V1'
REL='assets/css/device-control-fix.css'

CSS=r'''
/* JRC_FB_ONU_SPLIT_EQUAL_V1 */
#overview-content .fb-onu-split{
    display:grid !important;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr) !important;
    gap:12px !important;
    align-items:stretch !important;
    width:100% !important;
}

#overview-content .fb-onu-split > .fb-onu{
    min-width:0 !important;
    width:100% !important;
    display:flex !important;
    flex-direction:column !important;
}

#overview-content .fb-onu-split > .fb-onu > .acs-overview-card,
#overview-content .fb-onu-split > .fb-onu > .acs-optical-history{
    min-width:0 !important;
    width:100% !important;
    height:100% !important;
    margin:0 !important;
    align-self:stretch !important;
    box-sizing:border-box !important;
}

@media (max-width:899px){
    #overview-content .fb-onu-split{
        grid-template-columns:1fr !important;
    }
}
'''

def sha(b): return hashlib.sha256(b).hexdigest()

def patch(before):
    text=before.decode('utf-8')
    if VERSION in text: return None
    sep='' if text.endswith('\n') else '\n'
    return (text+sep+CSS).encode('utf-8')

def atomic(path,data,meta):
    fd,name=tempfile.mkstemp(prefix='.jrc-fb-onu-',dir=str(path.parent))
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

    with (parent/'.jrc-fb-onu-split.lock').open('a') as lock:
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
            print('JA INSTALADO: cards fb-onu em 50/50.')
            return

        print('VALIDADO: .fb-onu-split sera ajustado diretamente para 50/50.')
        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        if path.read_bytes()!=before:
            raise RuntimeError('Edicao concorrente detectada.')

        backup=Path(tempfile.mkdtemp(
            prefix='gacs-fb-onu-split-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',
            dir=str(parent)
        ))
        os.chmod(backup,0o700)
        (backup/'original.css').write_bytes(before)
        (backup/'install.py').write_bytes(Path(__file__).read_bytes())
        (backup/'manifest.json').write_text(json.dumps({
            'version':VERSION,'root':str(root),'before':sha(before),'after':sha(after)
        },indent=2))
        (backup/'changes.diff').write_text(''.join(difflib.unified_diff(
            before.decode().splitlines(True),after.decode().splitlines(True),
            fromfile=REL,tofile=REL
        )))

        atomic(path,after,path.stat())
        ptr=parent/'gacs-fb-onu-split-ultimo'
        ptr.write_text(str(backup)+'\n')
        os.chmod(ptr,0o600)

        print('INSTALADO: Sinal optico e Trafego WAN agora usam 50/50 no container real.')
        print('Grafico, SSE e leitura de 1s preservados.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr); sys.exit(1)
