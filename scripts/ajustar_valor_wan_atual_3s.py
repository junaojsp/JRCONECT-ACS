#!/usr/bin/env python3
"""Smooth only WAN current values over the latest 3 seconds of real IXC SSE samples."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_IXC_CURRENT_SMOOTH_V1'
REL='assets/js/device-control-fix.js'

NEW_FN=r'''    function jrcPaintIxcSseSample(sample) {
        if (!sample) return false;
        const down = jrcWanNumber(sample.download_mbps);
        const up = jrcWanNumber(sample.upload_mbps);
        if (down === null || up === null) return false;

        const parsed = sample.sample_time ? new Date(sample.sample_time).getTime() : Date.now();
        const at = Number.isFinite(parsed) ? parsed : Date.now();

        traffic.liveAvailable = true;
        traffic.liveReason = null;
        traffic.liveDisabledUntil = 0;
        traffic.liveSource = 'ixc-sse';
        traffic.ixcSseLast = {download_mbps:down, upload_mbps:up, sample_time:new Date(at).toISOString()};

        traffic.samples.push({at, down, up, source:'ixc-sse'});
        const cutoff = Date.now() - (5 * 60 * 1000);
        traffic.samples = traffic.samples.filter(row => Number(row.at) >= cutoff).slice(-360);

        // O EventSource do IXC pode alternar amostras muito baixas/zero entre
        // atualizacoes. Para os cards "agora", usa apenas a media das amostras
        // reais dos ultimos 3 segundos. A curva permanece com os valores brutos.
        const currentCutoff = at - 3000;
        const current = traffic.samples.filter(row =>
            Number(row.at) >= currentCutoff &&
            Number(row.at) <= at &&
            Number.isFinite(row.down) &&
            Number.isFinite(row.up)
        );
        const currentDown = current.length
            ? current.reduce((sum,row)=>sum+Math.max(0,row.down),0)/current.length
            : down;
        const currentUp = current.length
            ? current.reduce((sum,row)=>sum+Math.max(0,row.up),0)/current.length
            : up;

        setLiveRate('live-rx', currentDown);
        setLiveRate('live-tx', currentUp);
        setMonitorText('jr-monitor-source', 'IXC EventSource + IXC/RADIUS');
        setMonitorText('jr-monitor-live-source', 'IXC EventSource');
        setMonitorText('jr-source-live-badge', 'IXC AO VIVO');
        setMonitorText('jr-source-live-state', 'Stream IXC conectado');
        setMonitorText('bandwidth-sample-status', 'IXC AO VIVO • ' + new Date(at).toLocaleTimeString('pt-BR'));

        jrcWanNodes('bandwidth-bars').forEach(node => {
            node.innerHTML = chartHtml(traffic.samples);
        });
        return true;
    }'''

def sha(data): return hashlib.sha256(data).hexdigest()

def find_fn(text):
    sig='    function jrcPaintIxcSseSample(sample)'
    start=text.find(sig)
    if start<0: raise RuntimeError('jrcPaintIxcSseSample nao encontrada. O SSE IXC precisa estar instalado.')
    brace=text.find('{',start); depth=0; quote=None; esc=False
    for i in range(brace,len(text)):
        c=text[i]
        if quote:
            if esc: esc=False
            elif c=='\\': esc=True
            elif c==quote: quote=None
        else:
            if c in ("'",'"','`'): quote=c
            elif c=='{': depth+=1
            elif c=='}':
                depth-=1
                if depth==0: return start,i+1
    raise RuntimeError('Fechamento da funcao SSE nao encontrado.')

def patch(before):
    text=before.decode('utf-8')
    nl='\r\n' if '\r\n' in text else '\n'
    text=text.replace('\r\n','\n')
    if VERSION in text: return None
    if 'JRC_IXC_SSE_REALTIME_V1' not in text:
        raise RuntimeError('Stream IXC SSE nao localizado.')
    a,b=find_fn(text)
    text=text[:a]+'    /* '+VERSION+' */\n'+NEW_FN+text[b:]
    return text.replace('\n',nl).encode('utf-8')

def check(data):
    node=shutil.which('node')
    if not node: raise RuntimeError('Node.js ausente.')
    with tempfile.NamedTemporaryFile(suffix='.js') as f:
        f.write(data); f.flush()
        r=subprocess.run([node,'--check',f.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError('Sintaxe JS invalida: '+r.stderr[:1000])

def atomic(path,data,meta):
    fd,name=tempfile.mkstemp(prefix='.jrc-ixc-current-',dir=str(path.parent))
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
    with (parent/'.jrc-ixc-current-smooth.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        if a.rollback:
            b=a.rollback.resolve(); meta=json.loads((b/'manifest.json').read_text())
            if meta.get('version')!=VERSION: raise RuntimeError('Backup incompativel.')
            path=Path(meta['root'])/REL; original=(b/'original.js').read_bytes()
            if sha(path.read_bytes())!=meta['after']: raise RuntimeError('Arquivo mudou depois da instalacao.')
            check(original); atomic(path,original,path.stat())
            print('RESTAURADO: calculo anterior dos valores atuais WAN.'); return
        root=Path(a.root).resolve(); path=root/REL
        if not path.is_file() or path.is_symlink(): raise RuntimeError('Arquivo JS nao encontrado.')
        before=path.read_bytes(); after=patch(before)
        if after is None: print('JA INSTALADO.'); return
        check(before); check(after)
        print('VALIDADO: somente Download/Upload agora receberao media movel de 3s.')
        if a.dry_run: print('SIMULACAO OK: nenhum arquivo alterado.'); return
        if path.read_bytes()!=before: raise RuntimeError('Edicao concorrente detectada.')
        backup=Path(tempfile.mkdtemp(prefix='gacs-ixc-current-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',dir=str(parent)))
        os.chmod(backup,0o700); shutil.copy2(path,backup/'original.js'); shutil.copy2(Path(__file__).resolve(),backup/'install.py')
        (backup/'manifest.json').write_text(json.dumps({'version':VERSION,'root':str(root),'before':sha(before),'after':sha(after)},indent=2))
        (backup/'changes.diff').write_text(''.join(difflib.unified_diff(before.decode().splitlines(True),after.decode().splitlines(True),fromfile=REL,tofile=REL)))
        atomic(path,after,path.stat())
        ptr=parent/'gacs-ixc-current-ultimo'; ptr.write_text(str(backup)+'\n'); os.chmod(ptr,0o600)
        print('INSTALADO: valores WAN atuais suavizados em janela real de 3 segundos.')
        print('Curva, SSE, layout e fallbacks preservados.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr); sys.exit(1)
