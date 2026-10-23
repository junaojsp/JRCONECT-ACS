#!/usr/bin/env python3
"""Align WAN/PPPoE tab visuals with the current WAN overview card. Logic/actions unchanged."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, tempfile, stat, sys
from pathlib import Path

VERSION='JRC_WAN_TAB_ALIGNED_V1'
REL='assets/css/device-control-fix.css'

CSS=r'''
/* JRC_WAN_TAB_ALIGNED_V1 */
#wan-content .acs-wan-page{
    width:100% !important;
    padding:0 !important;
}

#wan-content .acs-wan-page-header{
    align-items:center !important;
    margin-bottom:14px !important;
    padding:0 0 14px !important;
    border-bottom:1px solid #2a3038 !important;
}

#wan-content .acs-wan-page-header h4{
    margin:3px 0 2px !important;
    font-size:18px !important;
    color:#f5f7fa !important;
}

#wan-content .acs-wan-page-header p{
    color:#8995a8 !important;
    font-size:10px !important;
}

#wan-content .acs-wan-header-actions{
    margin-left:auto !important;
}

#wan-content .acs-wan-summary-pill{
    min-width:72px !important;
    height:40px !important;
    border:1px solid #313845 !important;
    background:#15181e !important;
    border-radius:9px !important;
}

#wan-content .acs-wan-summary-pill strong{
    color:#f7f9fb !important;
    font-size:14px !important;
}

#wan-content .acs-wan-summary-pill span{
    color:#8190a5 !important;
    font-size:8px !important;
}

#wan-content .acs-wan-primary-btn{
    height:40px !important;
    padding:0 14px !important;
    border:1px solid #37404d !important;
    background:#181c22 !important;
    color:#edf2f7 !important;
    border-radius:9px !important;
}

#wan-content .acs-wan-primary-btn:hover{
    background:#20252c !important;
    border-color:#4b5563 !important;
}

#wan-content .acs-wan-grid{
    display:grid !important;
    grid-template-columns:1fr !important;
    gap:14px !important;
    width:100% !important;
}

#wan-content .acs-wan-card{
    width:100% !important;
    min-width:0 !important;
    padding:16px !important;
    border:1px solid #2c323a !important;
    border-radius:12px !important;
    background:#15181e !important;
    box-shadow:none !important;
}

#wan-content .acs-wan-card-top{
    align-items:center !important;
    padding-bottom:13px !important;
    margin-bottom:12px !important;
    border-bottom:1px solid #2b3038 !important;
}

#wan-content .acs-wan-card-icon{
    width:42px !important;
    height:42px !important;
    flex:0 0 42px !important;
    border:1px solid #343b46 !important;
    background:#1c2027 !important;
    color:#dfe7f0 !important;
    border-radius:10px !important;
}

#wan-content .acs-wan-index{
    color:#8592a4 !important;
    font-size:8px !important;
}

#wan-content .acs-wan-card h5{
    color:#f4f7fa !important;
    font-size:15px !important;
    line-height:1.3 !important;
}

#wan-content .acs-wan-state{
    padding:6px 10px !important;
    font-size:9px !important;
}

#wan-content .acs-wan-state.online{
    color:#42e08f !important;
    background:rgba(35,190,108,.08) !important;
    border-color:rgba(35,190,108,.30) !important;
}

#wan-content .acs-wan-state.offline{
    color:#ff6876 !important;
    background:rgba(235,76,92,.08) !important;
    border-color:rgba(235,76,92,.30) !important;
}

#wan-content .acs-wan-tags{
    margin:0 0 12px !important;
    gap:7px !important;
}

#wan-content .acs-wan-tags span{
    padding:5px 8px !important;
    border:1px solid #313845 !important;
    background:#1a1e25 !important;
    color:#a8b4c4 !important;
    border-radius:6px !important;
    font-size:8px !important;
}

#wan-content .acs-wan-tags span.danger{
    color:#ff7b88 !important;
    border-color:rgba(235,76,92,.28) !important;
    background:rgba(235,76,92,.06) !important;
}

#wan-content .acs-wan-hero{
    display:flex !important;
    align-items:flex-end !important;
    justify-content:space-between !important;
    gap:16px !important;
    padding:14px 16px !important;
    margin-bottom:12px !important;
    border:1px solid #303640 !important;
    border-radius:10px !important;
    background:#191d24 !important;
}

#wan-content .acs-wan-hero span{
    color:#7f8da1 !important;
    font-size:8px !important;
    margin-bottom:4px !important;
}

#wan-content .acs-wan-hero strong,
#wan-content .acs-wan-hero a{
    color:#f4f8fb !important;
    font-size:20px !important;
    font-weight:750 !important;
}

#wan-content .acs-wan-details{
    display:grid !important;
    grid-template-columns:repeat(4,minmax(0,1fr)) !important;
    gap:10px !important;
}

#wan-content .acs-wan-details>div{
    min-height:64px !important;
    padding:10px 12px !important;
    border:1px solid #2c323a !important;
    border-radius:9px !important;
    background:#181c22 !important;
    display:flex !important;
    flex-direction:column !important;
    align-items:flex-start !important;
    justify-content:center !important;
    gap:5px !important;
}

#wan-content .acs-wan-details span{
    color:#7f8da0 !important;
    font-size:8px !important;
}

#wan-content .acs-wan-details span i{
    color:#8998aa !important;
}

#wan-content .acs-wan-details strong,
#wan-content .acs-wan-details a{
    width:100% !important;
    color:#f0f4f8 !important;
    font-size:11px !important;
    font-weight:700 !important;
    text-align:left !important;
    overflow-wrap:anywhere !important;
}

#wan-content .acs-wan-error-row{
    border-color:rgba(235,76,92,.25) !important;
    background:rgba(235,76,92,.04) !important;
}

#wan-content .acs-wan-error-row strong{
    color:#ff7b88 !important;
}

#wan-content .acs-wan-card-actions{
    justify-content:flex-end !important;
    gap:8px !important;
    padding-top:14px !important;
    margin-top:12px !important;
    border-top:1px solid #2a3038 !important;
}

#wan-content .acs-wan-card-actions button{
    min-height:34px !important;
    padding:0 12px !important;
    border:1px solid #343b46 !important;
    border-radius:8px !important;
    background:#1a1e25 !important;
    color:#e6edf5 !important;
}

#wan-content .acs-wan-card-actions button:hover{
    background:#22272f !important;
}

#wan-content .acs-wan-card-actions button.danger{
    color:#ff7b88 !important;
    border-color:rgba(235,76,92,.25) !important;
    background:rgba(235,76,92,.05) !important;
}

#wan-content .acs-wan-footnote{
    margin-top:14px !important;
    padding:10px 12px !important;
    border:1px solid #2c323a !important;
    background:#15181e !important;
    color:#7f8da0 !important;
}

@media(max-width:1200px){
    #wan-content .acs-wan-details{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }
}

@media(max-width:760px){
    #wan-content .acs-wan-page-header{
        align-items:flex-start !important;
        flex-direction:column !important;
    }
    #wan-content .acs-wan-header-actions{
        width:100% !important;
        margin-left:0 !important;
        flex-wrap:wrap !important;
    }
    #wan-content .acs-wan-details{
        grid-template-columns:1fr !important;
    }
    #wan-content .acs-wan-hero{
        align-items:flex-start !important;
        flex-direction:column !important;
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
    fd,name=tempfile.mkstemp(prefix='.jrc-wan-tab-',dir=str(path.parent))
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

    with (parent/'.jrc-wan-tab-aligned.lock').open('a') as lock:
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
            print('RESTAURADO: visual anterior da aba WAN.')
            return

        root=Path(a.root).resolve()
        path=root/REL
        if not path.is_file() or path.is_symlink():
            raise RuntimeError('CSS do dispositivo nao encontrado.')

        before=path.read_bytes()
        after=patch(before)
        if after is None:
            print('JA INSTALADO: aba WAN alinhada.')
            return

        print('VALIDADO: somente layout visual da aba WAN/PPPoE sera alterado.')
        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        if path.read_bytes()!=before:
            raise RuntimeError('Edicao concorrente detectada.')

        backup=Path(tempfile.mkdtemp(
            prefix='gacs-wan-tab-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',
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
        ptr=parent/'gacs-wan-tab-aligned-ultimo'
        ptr.write_text(str(backup)+'\n'); os.chmod(ptr,0o600)

        print('INSTALADO: aba WAN/PPPoE alinhada ao visual da Visao geral.')
        print('Editar, excluir, adicionar WAN e logica TR-069 preservados.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr); sys.exit(1)
