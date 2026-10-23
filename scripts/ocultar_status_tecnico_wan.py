#!/usr/bin/env python3
"""Hide WAN technical connection/collection status from UI; keep all background collection and 1s values unchanged."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_WAN_STATUS_BACKGROUND_ONLY_V1'
REL='assets/js/device-control-fix.js'

def sha(data): return hashlib.sha256(data).hexdigest()

def patch(before):
    text=before.decode('utf-8')
    nl='\r\n' if '\r\n' in text else '\n'
    text=text.replace('\r\n','\n')
    if VERSION in text:
        return None
    if 'JRC_IXC_CURRENT_SMOOTH_1S_V1' not in text:
        raise RuntimeError('Janela WAN de 1 segundo nao localizada.')
    if "bandwidth-sample-status" not in text:
        raise RuntimeError('Status tecnico WAN nao localizado.')

    anchor="    function setMonitorText(id,value) {\n"
    if text.count(anchor)!=1:
        raise RuntimeError('setMonitorText nao localizado de forma unica.')

    helper="""    /* JRC_WAN_STATUS_BACKGROUND_ONLY_V1 */
    function jrcHideWanTechnicalStatus() {
        document.querySelectorAll('[id="bandwidth-sample-status"]').forEach(node => {
            node.style.display = 'none';
            node.setAttribute('aria-hidden','true');
        });
    }

"""
    text=text.replace(anchor,helper+anchor,1)

    old="""    function setMonitorText(id,value) {
        jrcWanNodes(id).forEach(node => { node.textContent = value ?? 'N/D'; });
    }"""
    if text.count(old)!=1:
        raise RuntimeError('setMonitorText esperado nao encontrado.')
    new="""    function setMonitorText(id,value) {
        jrcWanNodes(id).forEach(node => {
            node.textContent = value ?? 'N/D';
            if (id === 'bandwidth-sample-status') {
                node.style.display = 'none';
                node.setAttribute('aria-hidden','true');
            }
        });
    }"""
    text=text.replace(old,new,1)

    # Ensure dynamically rebuilt cards remain hidden without touching data flow.
    dom="    document.addEventListener('DOMContentLoaded', () => {\n"
    if text.count(dom)!=1:
        raise RuntimeError('DOMContentLoaded nao localizado de forma unica.')
    text=text.replace(dom,
        "    document.addEventListener('DOMContentLoaded', () => {\n        jrcHideWanTechnicalStatus();\n        setInterval(jrcHideWanTechnicalStatus, 1000);\n",
        1
    )

    return text.replace('\n',nl).encode('utf-8')

def check(data):
    node=shutil.which('node')
    if not node:
        raise RuntimeError('Node.js ausente para validar JavaScript.')
    with tempfile.NamedTemporaryFile(suffix='.js') as f:
        f.write(data); f.flush()
        r=subprocess.run([node,'--check',f.name],capture_output=True,text=True)
    if r.returncode:
        raise RuntimeError('Sintaxe JavaScript invalida: '+r.stderr[:1200])

def atomic(path,data,meta):
    fd,name=tempfile.mkstemp(prefix='.jrc-wan-status-',dir=str(path.parent))
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

    with (parent/'.jrc-wan-status-background.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)

        if a.rollback:
            b=a.rollback.resolve()
            meta=json.loads((b/'manifest.json').read_text())
            if meta.get('version')!=VERSION:
                raise RuntimeError('Backup incompativel.')
            path=Path(meta['root'])/REL
            original=(b/'original.js').read_bytes()
            if sha(path.read_bytes())!=meta['after']:
                raise RuntimeError('Arquivo mudou depois da instalacao; rollback cancelado.')
            check(original); atomic(path,original,path.stat())
            print('RESTAURADO: status tecnico WAN voltou a aparecer.')
            return

        root=Path(a.root).resolve()
        path=root/REL
        if not path.is_file() or path.is_symlink():
            raise RuntimeError('device-control-fix.js nao encontrado.')

        before=path.read_bytes()
        after=patch(before)
        if after is None:
            print('JA INSTALADO: status WAN em background.')
            return

        check(before); check(after)
        print('VALIDADO: mensagens tecnicas WAN serao ocultadas; coleta e janela de 1s preservadas.')
        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        if path.read_bytes()!=before:
            raise RuntimeError('Edicao concorrente detectada.')

        backup=Path(tempfile.mkdtemp(
            prefix='gacs-wan-status-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',
            dir=str(parent)
        ))
        os.chmod(backup,0o700)
        shutil.copy2(path,backup/'original.js')
        shutil.copy2(Path(__file__).resolve(),backup/'install.py')
        (backup/'manifest.json').write_text(json.dumps({
            'version':VERSION,'root':str(root),'before':sha(before),'after':sha(after)
        },indent=2))
        (backup/'changes.diff').write_text(''.join(difflib.unified_diff(
            before.decode().splitlines(True),after.decode().splitlines(True),
            fromfile=REL,tofile=REL
        )))

        atomic(path,after,path.stat())
        ptr=parent/'gacs-wan-status-ultimo'
        ptr.write_text(str(backup)+'\n')
        os.chmod(ptr,0o600)

        print('INSTALADO: leitura/conexao WAN agora ficam em background.')
        print('Mantidos: SSE IXC, grafico, fallbacks e media movel de 1 segundo.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try:
        main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr)
        sys.exit(1)
