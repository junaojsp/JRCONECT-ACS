#!/usr/bin/env python3
"""Make the existing WAN SVG always visible without changing card layout or data sources."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_WAN_CHART_VISIBLE_V1'
REL='assets/js/device-control-fix.js'

NEW_FN=r'''    function chartHtml(samples) {
        const cutoff=Date.now()-(5*60*1000);
        const recent=(Array.isArray(samples)?samples:[])
            .filter(s=>Number(s.at)>=cutoff && Number.isFinite(s.down)&&Number.isFinite(s.up))
            .sort((a,b)=>Number(a.at)-Number(b.at));
        if(recent.length<1)return '<div class="jr-monitor-wait"><i class="bi bi-activity"></i><span>Aguardando dados de trafego...</span></div>';

        const rawMax=Math.max(0.000001,...recent.flatMap(s=>[Math.max(0,s.down),Math.max(0,s.up)]));
        let factor=1,unit='Mbps';
        if(rawMax<0.001){factor=1000000;unit='bps';}
        else if(rawMax<1){factor=1000;unit='Kbps';}

        const peak=rawMax*factor;
        const displayMax=Math.max(unit==='Mbps'?0.1:1, peak*1.18);
        const top=8,bottom=90;
        const x=(i)=>recent.length===1?50:(i/(recent.length-1))*100;
        const y=(value)=>{
            const scaled=Math.max(0,Number(value)||0)*factor;
            return bottom-(scaled/displayMax)*(bottom-top);
        };
        const points=field=>recent.map((s,i)=>x(i).toFixed(2)+','+Math.max(top,Math.min(bottom,y(s[field]))).toFixed(2)).join(' ');
        const circles=(field,color)=>recent.map((s,i)=>
            '<circle cx="'+x(i).toFixed(2)+'" cy="'+Math.max(top,Math.min(bottom,y(s[field]))).toFixed(2)+'" r="1.15" fill="'+color+'" vector-effect="non-scaling-stroke"/>'
        ).join('');
        const area=field=>'0,'+bottom+' '+points(field)+' 100,'+bottom;
        const first=new Date(recent[0].at).toLocaleTimeString('pt-BR');
        const last=new Date(recent[recent.length-1].at).toLocaleTimeString('pt-BR');

        const label=v=>v>=100?v.toFixed(0):v>=10?v.toFixed(1):v.toFixed(2);
        const topLabel=label(displayMax), midLabel=label(displayMax/2);

        return '<div class="jr-monitor-chart-inner">'+
          '<div class="jr-monitor-scale"><span>'+topLabel+' '+unit+'</span><span>'+midLabel+' '+unit+'</span><span>0 '+unit+'</span></div>'+
          '<div class="jr-monitor-plot">'+
            '<svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="Download e upload em tempo real">'+
              '<path d="M0 8H100M0 49H100M0 90H100" fill="none" stroke="rgba(148,163,184,.22)" stroke-width=".55" vector-effect="non-scaling-stroke"/>'+
              '<polygon points="'+area('down')+'" fill="rgba(56,189,248,.09)"/>'+
              '<polygon points="'+area('up')+'" fill="rgba(52,211,153,.07)"/>'+
              '<polyline points="'+points('down')+'" fill="none" stroke="#38bdf8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>'+
              '<polyline points="'+points('up')+'" fill="none" stroke="#34d399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>'+
              circles('down','#38bdf8')+circles('up','#34d399')+
            '</svg>'+
            '<div class="jr-monitor-times"><span>'+first+'</span><span>'+last+'</span></div>'+
          '</div>'+
          '</div>'+
          '<div class="jr-monitor-chart-legend"><span><i class="down"></i>Download</span><span><i class="up"></i>Upload</span><span>Ultimos 5 minutos</span></div>';
    }'''

def sha(data): return hashlib.sha256(data).hexdigest()

def find_function(text, signature):
    start=text.find(signature)
    if start<0: raise RuntimeError('Funcao chartHtml nao encontrada.')
    brace=text.find('{',start)
    if brace<0: raise RuntimeError('Abertura de chartHtml nao encontrada.')
    depth=0; quote=None; esc=False; i=brace
    while i<len(text):
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
                if depth==0:
                    return start,i+1
        i+=1
    raise RuntimeError('Fechamento de chartHtml nao encontrado.')

def patch(before):
    text=before.decode('utf-8')
    nl='\r\n' if '\r\n' in text else '\n'
    text=text.replace('\r\n','\n')
    if VERSION in text: return None
    if 'function chartHtml(samples)' not in text: raise RuntimeError('chartHtml ausente.')
    if 'IXC AO VIVO' not in text: raise RuntimeError('Monitor WAN esperado nao reconhecido.')
    a,b=find_function(text,'    function chartHtml(samples)')
    marker="    /* "+VERSION+" */\n"
    text=text[:a]+marker+NEW_FN+text[b:]
    return text.replace('\n',nl).encode('utf-8')

def check(data):
    node=shutil.which('node')
    if not node: raise RuntimeError('Node.js ausente para validar JavaScript.')
    with tempfile.NamedTemporaryFile(suffix='.js') as tmp:
        tmp.write(data); tmp.flush()
        r=subprocess.run([node,'--check',tmp.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError('Sintaxe JavaScript invalida: '+r.stderr[:1200])

def atomic(path,data,meta):
    fd,name=tempfile.mkstemp(prefix='.jrc-wan-chart-',dir=str(path.parent))
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
    with (parent/'.jrc-wan-chart-visible.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        if a.rollback:
            b=a.rollback.resolve(); meta=json.loads((b/'manifest.json').read_text())
            if meta.get('version')!=VERSION: raise RuntimeError('Backup incompativel.')
            path=Path(meta['root'])/REL; original=(b/'original.js').read_bytes()
            if sha(path.read_bytes())!=meta['after']: raise RuntimeError('Arquivo mudou depois da instalacao; rollback cancelado.')
            check(original); atomic(path,original,path.stat())
            print('RESTAURADO: desenho anterior do grafico WAN.'); return
        root=Path(a.root).resolve(); path=root/REL
        if not path.is_file() or path.is_symlink(): raise RuntimeError('device-control-fix.js nao encontrado.')
        before=path.read_bytes(); after=patch(before)
        if after is None: print('JA INSTALADO: grafico WAN visivel.'); return
        check(before); check(after)
        print('VALIDADO: apenas renderizacao SVG do grafico WAN sera alterada.')
        if a.dry_run: print('SIMULACAO OK: nenhum arquivo alterado.'); return
        if path.read_bytes()!=before: raise RuntimeError('Edicao concorrente detectada.')
        backup=Path(tempfile.mkdtemp(prefix='gacs-wan-chart-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',dir=str(parent)))
        os.chmod(backup,0o700); shutil.copy2(path,backup/'original.js'); shutil.copy2(Path(__file__).resolve(),backup/'install.py')
        (backup/'manifest.json').write_text(json.dumps({'version':VERSION,'root':str(root),'before':sha(before),'after':sha(after)},indent=2))
        (backup/'changes.diff').write_text(''.join(difflib.unified_diff(before.decode().splitlines(True),after.decode().splitlines(True),fromfile=REL,tofile=REL)))
        atomic(path,after,path.stat())
        ptr=parent/'gacs-wan-chart-ultimo'; ptr.write_text(str(backup)+'\n'); os.chmod(ptr,0o600)
        print('INSTALADO: curva WAN com tracos e pontos explicitos; layout preservado.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr); sys.exit(1)
