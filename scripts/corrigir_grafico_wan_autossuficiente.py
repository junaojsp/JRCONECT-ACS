#!/usr/bin/env python3
"""Replace only WAN chart renderer with a self-contained responsive SVG. Card layout/data sources preserved."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_WAN_CHART_SELFCONTAINED_V1'
REL='assets/js/device-control-fix.js'

NEW_FN=r'''    function chartHtml(samples) {
        const cutoff=Date.now()-(5*60*1000);
        const recent=(Array.isArray(samples)?samples:[])
            .filter(s=>Number(s.at)>=cutoff && Number.isFinite(s.down)&&Number.isFinite(s.up))
            .sort((a,b)=>Number(a.at)-Number(b.at));

        if(!recent.length){
            return '<div class="jr-monitor-wait"><i class="bi bi-activity"></i><span>Aguardando dados de trafego...</span></div>';
        }

        const rawMax=Math.max(0.000001,...recent.flatMap(s=>[Math.max(0,s.down),Math.max(0,s.up)]));
        let factor=1,unit='Mbps';
        if(rawMax<0.001){factor=1000000;unit='bps';}
        else if(rawMax<1){factor=1000;unit='Kbps';}

        const peak=rawMax*factor;
        const displayMax=Math.max(unit==='Mbps'?0.10:1, peak*1.20);
        const W=760,H=230;
        const L=62,R=738,T=18,B=188;
        const plotW=R-L, plotH=B-T;

        const x=(i)=>recent.length===1 ? L+plotW/2 : L+(i/(recent.length-1))*plotW;
        const y=(value)=>{
            const scaled=Math.max(0,Number(value)||0)*factor;
            return B-(scaled/displayMax)*plotH;
        };
        const clampY=value=>Math.max(T,Math.min(B,y(value)));

        const fmtAxis=v=>v>=100?v.toFixed(0):v>=10?v.toFixed(1):v.toFixed(2);
        const fmtTime=t=>new Date(t).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});

        const pathFor=field=>{
            if(recent.length===1){
                const xx=x(0).toFixed(2), yy=clampY(recent[0][field]).toFixed(2);
                return 'M '+xx+' '+yy+' L '+xx+' '+yy;
            }
            return recent.map((s,i)=>(i?'L ':'M ')+x(i).toFixed(2)+' '+clampY(s[field]).toFixed(2)).join(' ');
        };

        const dots=(field,color)=>recent.map((s,i)=>
            '<circle cx="'+x(i).toFixed(2)+'" cy="'+clampY(s[field]).toFixed(2)+'" r="3" fill="'+color+'">'+
            '<title>'+fmtTime(s.at)+' • '+field.toUpperCase()+' '+(Math.max(0,Number(s[field])||0)*factor).toFixed(2)+' '+unit+'</title></circle>'
        ).join('');

        const areaFor=field=>{
            const pts=recent.map((s,i)=>x(i).toFixed(2)+','+clampY(s[field]).toFixed(2)).join(' ');
            return L+','+B+' '+pts+' '+R+','+B;
        };

        const first=fmtTime(recent[0].at);
        const last=fmtTime(recent[recent.length-1].at);
        const midX=(L+R)/2;
        const topLabel=fmtAxis(displayMax);
        const midLabel=fmtAxis(displayMax/2);

        return '<div style="width:100%;height:230px;position:relative;overflow:hidden">'+
          '<svg viewBox="0 0 '+W+' '+H+'" preserveAspectRatio="none" style="display:block;width:100%;height:230px;overflow:visible" role="img" aria-label="Grafico WAN em tempo real">'+
            '<defs>'+
              '<linearGradient id="jrcWanDownArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#38bdf8" stop-opacity=".22"/><stop offset="100%" stop-color="#38bdf8" stop-opacity="0"/></linearGradient>'+
              '<linearGradient id="jrcWanUpArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#34d399" stop-opacity=".18"/><stop offset="100%" stop-color="#34d399" stop-opacity="0"/></linearGradient>'+
            '</defs>'+
            '<line x1="'+L+'" y1="'+T+'" x2="'+R+'" y2="'+T+'" stroke="#334155" stroke-width="1" stroke-dasharray="4 5"/>'+
            '<line x1="'+L+'" y1="'+((T+B)/2)+'" x2="'+R+'" y2="'+((T+B)/2)+'" stroke="#334155" stroke-width="1" stroke-dasharray="4 5"/>'+
            '<line x1="'+L+'" y1="'+B+'" x2="'+R+'" y2="'+B+'" stroke="#475569" stroke-width="1"/>'+
            '<text x="'+(L-10)+'" y="'+(T+4)+'" text-anchor="end" fill="#8da0b8" font-size="12">'+topLabel+' '+unit+'</text>'+
            '<text x="'+(L-10)+'" y="'+(((T+B)/2)+4)+'" text-anchor="end" fill="#8da0b8" font-size="12">'+midLabel+' '+unit+'</text>'+
            '<text x="'+(L-10)+'" y="'+(B+4)+'" text-anchor="end" fill="#8da0b8" font-size="12">0 '+unit+'</text>'+
            '<polygon points="'+areaFor('down')+'" fill="url(#jrcWanDownArea)"/>'+
            '<polygon points="'+areaFor('up')+'" fill="url(#jrcWanUpArea)"/>'+
            '<path d="'+pathFor('down')+'" fill="none" stroke="#38bdf8" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>'+
            '<path d="'+pathFor('up')+'" fill="none" stroke="#34d399" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>'+
            dots('down','#38bdf8')+dots('up','#34d399')+
            '<text x="'+L+'" y="211" text-anchor="start" fill="#8da0b8" font-size="11">'+first+'</text>'+
            '<text x="'+midX+'" y="211" text-anchor="middle" fill="#738399" font-size="11">Ultimos 5 minutos</text>'+
            '<text x="'+R+'" y="211" text-anchor="end" fill="#8da0b8" font-size="11">'+last+'</text>'+
          '</svg>'+
        '</div>'+
        '<div class="jr-monitor-chart-legend" style="padding-top:2px">'+
          '<span><i class="down"></i>Download</span>'+
          '<span><i class="up"></i>Upload</span>'+
          '<span>'+recent.length+' amostra(s)</span>'+
        '</div>';
    }'''

def sha(data): return hashlib.sha256(data).hexdigest()

def find_fn(text):
    sig='    function chartHtml(samples)'
    start=text.find(sig)
    if start<0: raise RuntimeError('chartHtml nao encontrada.')
    brace=text.find('{',start)
    depth=0; quote=None; esc=False
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
    raise RuntimeError('Fechamento de chartHtml nao encontrado.')

def patch(before):
    text=before.decode('utf-8')
    nl='\r\n' if '\r\n' in text else '\n'
    text=text.replace('\r\n','\n')
    if VERSION in text: return None
    if 'function chartHtml(samples)' not in text: raise RuntimeError('Renderizador WAN ausente.')
    if 'IXC AO VIVO' not in text: raise RuntimeError('Monitor IXC esperado nao reconhecido.')
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
    fd,name=tempfile.mkstemp(prefix='.jrc-wan-self-',dir=str(path.parent))
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
    with (parent/'.jrc-wan-selfcontained.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        if a.rollback:
            b=a.rollback.resolve(); meta=json.loads((b/'manifest.json').read_text())
            if meta.get('version')!=VERSION: raise RuntimeError('Backup incompativel.')
            path=Path(meta['root'])/REL; original=(b/'original.js').read_bytes()
            if sha(path.read_bytes())!=meta['after']: raise RuntimeError('Arquivo mudou depois da instalacao.')
            check(original); atomic(path,original,path.stat())
            print('RESTAURADO: renderizador WAN anterior.'); return
        root=Path(a.root).resolve(); path=root/REL
        if not path.is_file() or path.is_symlink(): raise RuntimeError('Arquivo JS nao encontrado.')
        before=path.read_bytes(); after=patch(before)
        if after is None: print('JA INSTALADO.'); return
        check(before); check(after)
        print('VALIDADO: somente o desenho interno do grafico WAN sera substituido.')
        if a.dry_run: print('SIMULACAO OK: nenhum arquivo alterado.'); return
        if path.read_bytes()!=before: raise RuntimeError('Edicao concorrente detectada.')
        backup=Path(tempfile.mkdtemp(prefix='gacs-wan-self-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',dir=str(parent)))
        os.chmod(backup,0o700); shutil.copy2(path,backup/'original.js'); shutil.copy2(Path(__file__).resolve(),backup/'install.py')
        (backup/'manifest.json').write_text(json.dumps({'version':VERSION,'root':str(root),'before':sha(before),'after':sha(after)},indent=2))
        (backup/'changes.diff').write_text(''.join(difflib.unified_diff(before.decode().splitlines(True),after.decode().splitlines(True),fromfile=REL,tofile=REL)))
        atomic(path,after,path.stat())
        ptr=parent/'gacs-wan-self-ultimo'; ptr.write_text(str(backup)+'\n'); os.chmod(ptr,0o600)
        print('INSTALADO: grafico WAN autossuficiente; card e layout preservados.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr); sys.exit(1)
