#!/usr/bin/env python3
"""Prevent WAN fallback/status flicker while fresh IXC SSE samples are available."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_IXC_SSE_GRACE_V1'
REL='assets/js/device-control-fix.js'

def sha(data): return hashlib.sha256(data).hexdigest()

def patch(before):
    text=before.decode('utf-8')
    nl='\r\n' if '\r\n' in text else '\n'
    text=text.replace('\r\n','\n')
    if VERSION in text:
        return None
    if 'JRC_IXC_SSE_REALTIME_V1' not in text:
        raise RuntimeError('SSE IXC realtime nao localizado.')
    if 'JRC_IXC_CURRENT_SMOOTH_1S_V1' not in text:
        raise RuntimeError('Ajuste WAN de 1s nao localizado.')

    old_last="""        traffic.ixcSseLast = {download_mbps:down, upload_mbps:up, sample_time:new Date(at).toISOString()};
"""
    new_last="""        traffic.ixcSseLast = {download_mbps:down, upload_mbps:up, sample_time:new Date(at).toISOString()};
        traffic.ixcSseLastAt = Date.now();
"""
    if text.count(old_last)!=1:
        raise RuntimeError('Registro da ultima amostra SSE nao encontrado exatamente uma vez.')
    text=text.replace(old_last,new_last,1)

    old_guard="""        const now=Date.now();
"""
    # Only patch the one inside updateTr069LiveTraffic, not other functions.
    sig='    window.updateTr069LiveTraffic = async function(force=false) {'
    start=text.find(sig)
    if start<0:
        raise RuntimeError('updateTr069LiveTraffic nao encontrada.')
    stop=text.find('    window.updateRadiusBandwidthSample = async function',start)
    if stop<0:
        raise RuntimeError('Fim de updateTr069LiveTraffic nao encontrado.')
    block=text[start:stop]
    if block.count(old_guard)!=1:
        raise RuntimeError('Inicio do controle de polling WAN diferente do esperado.')

    new_guard="""        const now=Date.now();
        /* JRC_IXC_SSE_GRACE_V1 */
        const sseAge = Number.isFinite(Number(traffic.ixcSseLastAt))
            ? now - Number(traffic.ixcSseLastAt)
            : Infinity;
        // Durante uma reconexao curta do EventSource, mantenha a ultima amostra
        // IXC e nao deixe o polling de fallback piscar "Consultando trafego WAN".
        if (!force && sseAge < 5000) return;
"""
    block=block.replace(old_guard,new_guard,1)

    old_status="            setMonitorText('bandwidth-sample-status','Consultando trafego WAN...');"
    if old_status not in block:
        # tolerate accented spelling from an older local installer
        old_status2="            setMonitorText('bandwidth-sample-status','Consultando tráfego WAN...');"
        if old_status2 not in block:
            raise RuntimeError('Status Consultando trafego WAN nao encontrado.')
        old_status=old_status2
    new_status="""            if (!traffic.ixcSseLastAt || (Date.now() - Number(traffic.ixcSseLastAt)) >= 5000) {
                setMonitorText('bandwidth-sample-status','Consultando trafego WAN...');
            }"""
    block=block.replace(old_status,new_status,1)
    text=text[:start]+block+text[stop:]

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
    fd,name=tempfile.mkstemp(prefix='.jrc-sse-grace-',dir=str(path.parent))
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

    with (parent/'.jrc-sse-grace.lock').open('a') as lock:
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
            print('RESTAURADO: comportamento anterior do fallback WAN.')
            return

        root=Path(a.root).resolve()
        path=root/REL
        if not path.is_file() or path.is_symlink():
            raise RuntimeError('device-control-fix.js nao encontrado.')

        before=path.read_bytes()
        after=patch(before)
        if after is None:
            print('JA INSTALADO: tolerancia SSE de 5 segundos.')
            return

        check(before); check(after)
        print('VALIDADO: fallback WAN aguardara 5s antes de assumir durante reconexao SSE.')
        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.')
            return

        if path.read_bytes()!=before:
            raise RuntimeError('Edicao concorrente detectada.')

        backup=Path(tempfile.mkdtemp(
            prefix='gacs-sse-grace-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',
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
        ptr=parent/'gacs-sse-grace-ultimo'
        ptr.write_text(str(backup)+'\n')
        os.chmod(ptr,0o600)

        print('INSTALADO: ultima amostra IXC permanece durante reconexoes curtas.')
        print('Texto Consultando trafego WAN nao piscara enquanto houver amostra recente.')
        print('Grafico, layout, SSE e media de 1s preservados.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try:
        main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr)
        sys.exit(1)
