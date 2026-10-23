#!/usr/bin/env python3
"""Remove only the artificial 55/60s cutoff from the IXC SSE proxy."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_IXC_SSE_NO_ARTIFICIAL_TIMEOUT_V1'
REL='api/stream-ixc-live-traffic.php'

def sha(data): return hashlib.sha256(data).hexdigest()

def patch(before):
    text=before.decode('utf-8')
    nl='\r\n' if '\r\n' in text else '\n'
    text=text.replace('\r\n','\n')
    if VERSION in text:
        return None

    required=[
        "@set_time_limit(70);",
        "CURLOPT_TIMEOUT => 60,",
        "if (connection_aborted() || ($now - $started) >= 55) return 1;"
    ]
    for token in required:
        if token not in text:
            raise RuntimeError('Estrutura SSE diferente; trecho nao encontrado: '+token)

    text=text.replace("@set_time_limit(70);",
                      "/* "+VERSION+" */\n@set_time_limit(0);",1)
    text=text.replace("CURLOPT_TIMEOUT => 60,",
                      "CURLOPT_TIMEOUT => 0,",1)
    text=text.replace(
        "if (connection_aborted() || ($now - $started) >= 55) return 1;",
        "if (connection_aborted()) return 1;",
        1
    )

    # Keep existing heartbeat and browser behavior; only remove the scheduled cutoff.
    text=text.replace('\n',nl)
    return text.encode('utf-8')

def check(data):
    php=shutil.which('php')
    if not php:
        raise RuntimeError('PHP CLI ausente para validar endpoint SSE.')
    with tempfile.NamedTemporaryFile(suffix='.php') as f:
        f.write(data); f.flush()
        r=subprocess.run([php,'-l',f.name],capture_output=True,text=True)
    if r.returncode:
        raise RuntimeError('Sintaxe PHP invalida: '+(r.stderr or r.stdout)[:1200])

def atomic(path,data,meta):
    fd,name=tempfile.mkstemp(prefix='.jrc-ixc-no-timeout-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data); out.flush(); os.fsync(out.fileno())
            os.fchmod(out.fileno(),stat.S_IMODE(meta.st_mode))
            if os.geteuid()==0:
                os.fchown(out.fileno(),meta.st_uid,meta.st_gid)
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

    with (parent/'.jrc-ixc-no-timeout.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)

        if a.rollback:
            b=a.rollback.resolve()
            meta=json.loads((b/'manifest.json').read_text())
            if meta.get('version')!=VERSION:
                raise RuntimeError('Backup incompativel.')
            path=Path(meta['root'])/REL
            original=(b/'original.php').read_bytes()
            if sha(path.read_bytes())!=meta['after']:
                raise RuntimeError('Arquivo mudou depois da instalacao; rollback cancelado.')
            check(original); atomic(path,original,path.stat())
            print('RESTAURADO: timeout SSE anterior de 55/60 segundos.')
            return

        root=Path(a.root).resolve()
        path=root/REL
        if not path.is_file() or path.is_symlink():
            raise RuntimeError('Endpoint SSE nao encontrado.')

        before=path.read_bytes()
        after=patch(before)
        if after is None:
            print('JA INSTALADO: SSE sem timeout artificial.')
            return

        check(before); check(after)
        print('VALIDADO: somente o timeout artificial do proxy IXC sera removido.')
        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        if path.read_bytes()!=before:
            raise RuntimeError('Edicao concorrente detectada.')

        backup=Path(tempfile.mkdtemp(
            prefix='gacs-ixc-no-timeout-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',
            dir=str(parent)
        ))
        os.chmod(backup,0o700)
        shutil.copy2(path,backup/'original.php')
        shutil.copy2(Path(__file__).resolve(),backup/'install.py')
        (backup/'manifest.json').write_text(json.dumps({
            'version':VERSION,'root':str(root),'before':sha(before),'after':sha(after)
        },indent=2))
        (backup/'changes.diff').write_text(''.join(difflib.unified_diff(
            before.decode().splitlines(True),after.decode().splitlines(True),
            fromfile=REL,tofile=REL
        )))

        atomic(path,after,path.stat())
        ptr=parent/'gacs-ixc-no-timeout-ultimo'
        ptr.write_text(str(backup)+'\n')
        os.chmod(ptr,0o600)

        print('INSTALADO: proxy SSE IXC sem corte artificial de 55/60s.')
        print('A conexao permanece aberta enquanto IXC/browser mantiverem o stream.')
        print('JS, grafico, layout e fallbacks nao foram alterados.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try:
        main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr)
        sys.exit(1)
