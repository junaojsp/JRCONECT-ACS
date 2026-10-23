#!/usr/bin/env python3
"""JRC ACS - keep WAN overview graph stable across auto-refresh and transient source gaps."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_WAN_OVERVIEW_FIX_V2'
REL='assets/js/device-control-fix.js'

def sha(data): return hashlib.sha256(data).hexdigest()

def atomic(path,data,metadata):
    fd,name=tempfile.mkstemp(prefix='.jrc-wan-v2-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data); out.flush(); os.fsync(out.fileno())
            os.fchmod(out.fileno(),stat.S_IMODE(metadata.st_mode))
            if os.geteuid()==0: os.fchown(out.fileno(),metadata.st_uid,metadata.st_gid)
        os.replace(name,path)
    finally:
        if os.path.exists(name): os.unlink(name)

def check(data):
    node=shutil.which('node')
    if not node: raise RuntimeError('Node.js ausente para validar sintaxe.')
    with tempfile.NamedTemporaryFile(suffix='.js') as tmp:
        tmp.write(data); tmp.flush()
        r=subprocess.run([node,'--check',tmp.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError('Sintaxe JavaScript invalida: '+r.stderr[:1200])

def once(text,old,new,label):
    count=text.count(old)
    if count!=1: raise RuntimeError(f'Trecho {label} esperado 1x, encontrado {count}x. Nada alterado.')
    return text.replace(old,new,1)

def patch(before):
    text=before.decode('utf-8')
    nl='\r\n' if '\r\n' in text else '\n'
    text=text.replace('\r\n','\n')
    if VERSION in text: return None
    if 'JRC_WAN_OVERVIEW_FIX_V1' not in text:
        raise RuntimeError('A correcao WAN v1 nao foi localizada. Aplique a v1 primeiro.')

    anchor="""    function jrcWanActive() {
        return !document.hidden && (monitoringActive() ||
            jrcWanNodes('bandwidth-bars').some(jrcWanVisible));
    }
"""
    helper=anchor+"""    /* JRC_WAN_OVERVIEW_FIX_V2 */
    window.JRC_WAN_OVERVIEW_FIX = '20261023-2';
    function jrcWanPaintLastSample(message) {
        const samples = Array.isArray(traffic.samples) ? traffic.samples : [];
        const last = samples.length ? samples[samples.length - 1] : null;
        if (!last || !Number.isFinite(last.down) || !Number.isFinite(last.up)) return false;
        setLiveRate('live-rx', last.down);
        setLiveRate('live-tx', last.up);
        jrcWanNodes('bandwidth-bars').forEach(node => { node.innerHTML = chartHtml(samples); });
        if (message) setMonitorText('bandwidth-sample-status', message);
        return true;
    }
"""
    text=once(text,anchor,helper,'helper v2')

    old_no_data="""            if(!data?.live){
                traffic.liveAvailable=false;
                traffic.liveDisabledUntil=Date.now()+4000;
                setMonitorText('live-rx-mbps','--');
                setMonitorText('live-tx-mbps','--');
                setMonitorText('live-rx-unit','Mbps');
                setMonitorText('live-tx-unit','Mbps');
                setMonitorText('jr-source-live-badge','AGUARDANDO');
"""
    new_no_data="""            if(!data?.live){
                traffic.liveAvailable=false;
                traffic.liveDisabledUntil=Date.now()+4000;
                const keptLastSample = jrcWanPaintLastSample(
                    'Sem nova amostra do IXC/BRAS; mantendo a ultima leitura valida.'
                );
                if(!keptLastSample){
                    setMonitorText('live-rx-mbps','--');
                    setMonitorText('live-tx-mbps','--');
                    setMonitorText('live-rx-unit','Mbps');
                    setMonitorText('live-tx-unit','Mbps');
                }
                setMonitorText('jr-source-live-badge',keptLastSample ? 'ULTIMA AMOSTRA' : 'AGUARDANDO');
"""
    text=once(text,old_no_data,new_no_data,'fallback sem dados')

    old_status="""                const status=jrcWanElement('bandwidth-sample-status');
                if(status)status.textContent=
                    traffic.liveReason==='wancommon_management_only'
                        ? 'Contador TR-069 WANCommon ignorado; aguardando tráfego real do IXC/BRAS.'
                        : (reasons.join(' | ') || 'Nenhuma amostra WAN valida.');
                const chart=jrcWanElement('bandwidth-bars');
                if(chart&&!traffic.samples.length)chart.innerHTML=chartHtml([]);
                return;
"""
    new_status="""                const status=jrcWanElement('bandwidth-sample-status');
                if(status && !keptLastSample) status.textContent=
                    traffic.liveReason==='wancommon_management_only'
                        ? 'Contador TR-069 WANCommon ignorado; aguardando trafego real do IXC/BRAS.'
                        : (reasons.join(' | ') || 'Nenhuma amostra WAN valida.');
                if(!keptLastSample){
                    jrcWanNodes('bandwidth-bars').forEach(node => { node.innerHTML=chartHtml([]); });
                }
                return;
"""
    text=once(text,old_status,new_status,'status sem dados')

    old_catch="""        }catch(e){
            traffic.liveAvailable=false;
            traffic.liveReason='request_failed';
            traffic.liveDisabledUntil=Date.now()+5000;
            setMonitorText('jr-source-live-state','IXC/BRAS temporariamente sem atualização');
            setMonitorText('jr-monitor-live-source','Última amostra válida mantida');
            const status=jrcWanElement('bandwidth-sample-status');
            if(status) status.textContent='Sem nova amostra do IXC/BRAS • mantendo a última leitura válida';
            const chart=jrcWanElement('bandwidth-bars');
            if(chart && traffic.samples.length) chart.innerHTML=chartHtml(traffic.samples);
        }finally{
"""
    new_catch="""        }catch(e){
            traffic.liveAvailable=false;
            traffic.liveReason='request_failed';
            traffic.liveDisabledUntil=Date.now()+5000;
            setMonitorText('jr-source-live-state','IXC/BRAS temporariamente sem atualizacao');
            setMonitorText('jr-monitor-live-source','Ultima amostra valida mantida');
            if(!jrcWanPaintLastSample('Sem nova amostra do IXC/BRAS; mantendo a ultima leitura valida.')){
                setMonitorText('bandwidth-sample-status','Falha temporaria na consulta WAN; aguardando nova amostra.');
            }
        }finally{
"""
    text=once(text,old_catch,new_catch,'catch live')

    old_wrapper="""        window.loadDeviceDetail = async function(...args) {
            const result = await originalLoad.apply(this,args);
            await enhanceControls();
            return result;
        };
"""
    new_wrapper="""        window.loadDeviceDetail = async function(...args) {
            const result = await originalLoad.apply(this,args);
            await enhanceControls();
            // O auto-refresh recria o card da Visao geral. Repinta imediatamente
            // a ultima amostra valida antes da proxima consulta de rede.
            jrcWanPaintLastSample('Ultima amostra valida; atualizando trafego...');
            setTimeout(() => {
                if (typeof window.updateRadiusBandwidthSample === 'function') {
                    window.updateRadiusBandwidthSample(true);
                }
                if (typeof window.updateTr069LiveTraffic === 'function') {
                    window.updateTr069LiveTraffic(true);
                }
            }, 0);
            return result;
        };
"""
    text=once(text,old_wrapper,new_wrapper,'wrapper loadDeviceDetail')

    return text.replace('\n',nl).encode('utf-8')

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--root',default='/var/www/gacs')
    p.add_argument('--backup-dir',default='/root')
    m=p.add_mutually_exclusive_group()
    m.add_argument('--dry-run',action='store_true')
    m.add_argument('--rollback',type=Path)
    a=p.parse_args()
    parent=a.rollback.resolve().parent if a.rollback else Path(a.backup_dir).resolve()
    with (parent/'.jrc-wan-overview-v2.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        if a.rollback:
            b=a.rollback.resolve(); meta=json.loads((b/'manifest.json').read_text())
            if meta.get('version')!=VERSION or meta.get('file')!=REL: raise RuntimeError('Backup incompativel.')
            path=Path(meta['root'])/REL; original=(b/'original.js').read_bytes()
            if sha(path.read_bytes())!=meta['after']: raise RuntimeError('Arquivo mudou depois da instalacao; reversao cancelada.')
            check(original); atomic(path,original,path.stat()); shutil.copystat(b/'original.js',path)
            print('RESTAURADO: estabilidade WAN v2 removida.'); return
        root=Path(a.root).resolve(); path=root/REL
        if not path.is_file() or path.is_symlink(): raise RuntimeError('Arquivo esperado nao encontrado.')
        before=path.read_bytes(); after=patch(before)
        if after is None: print('JA INSTALADO: WAN v2.'); return
        check(before); check(after)
        print('VALIDADO: correcao de persistencia do grafico WAN.')
        if a.dry_run: print('SIMULACAO OK: nenhum arquivo alterado.'); return
        if path.read_bytes()!=before: raise RuntimeError('Edicao concorrente detectada.')
        backup=Path(tempfile.mkdtemp(prefix='gacs-wan-stable-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',dir=str(parent)))
        os.chmod(backup,0o700); shutil.copy2(path,backup/'original.js'); shutil.copy2(Path(__file__).resolve(),backup/'install.py')
        (backup/'manifest.json').write_text(json.dumps({'version':VERSION,'root':str(root),'file':REL,'before':sha(before),'after':sha(after)},indent=2))
        (backup/'changes.diff').write_text(''.join(difflib.unified_diff(before.decode().splitlines(True),after.decode().splitlines(True),fromfile=REL,tofile=REL)))
        atomic(path,after,path.stat())
        ptr=parent/'gacs-wan-stable-ultimo'; ptr.write_text(str(backup)+'\n'); os.chmod(ptr,0o600)
        print('INSTALADO: grafico WAN preserva a ultima amostra durante refresh e falhas temporarias.')
        print('Backup: '+str(backup))
        print('Nenhuma alteracao no optico, APIs ou configuracao de equipamentos.')

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr); sys.exit(1)
