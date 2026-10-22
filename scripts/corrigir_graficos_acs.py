#!/usr/bin/env python3
"""JRC ACS: targeted optical/WAN chart repairs with backup and rollback.
No git operations, no network requests, no API/PHP/CSS/config replacements.
Run on the Linux application server, not on a workstation.
"""
import argparse
import datetime
import difflib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile

VERSION = 'JRC_DEVICE_CHARTS_FIX_V1'
TARGETS = {
    'assets/js/optical-history.js': ['opticalHistoryView'],
    'assets/js/device-detail.js': ['renderModernBandwidthChart', 'renderBandwidthLiveSample', 'fetchBandwidthJson', 'updateRadiusBandwidthSample'],
}
REPLACEMENTS = {'opticalHistoryView': 'function opticalHistoryView(data) {\n    const esc = value => String(value ?? \'\').replace(/[&<>"\']/g, c => ({\'&\':\'&amp;\',\'<\':\'&lt;\',\'>\':\'&gt;\',\'"\':\'&quot;\',"\'":\'&#39;\'}[c]));\n    const numeric = v => v !== null && v !== undefined && v !== \'\' && typeof v !== \'boolean\' && Number.isFinite(Number(v)) ? Number(v) : null;\n    const points = (Array.isArray(data?.points) ? data.points : []).map(p => ({\n        time:numeric(p.time), rx:numeric(p.rx), min:numeric(p.min ?? p.rx), max:numeric(p.max ?? p.rx)\n    })).filter(p => Object.values(p).every(Number.isFinite)).sort((a,b) => a.time-b.time);\n    if (!points.length) return \'<p class="text-muted mb-0">Sem leituras opticas neste periodo. Nenhum valor foi estimado.</p>\';\n    const bucket = numeric(data.bucket_seconds) > 0 ? Number(data.bucket_seconds) : 900;\n    const min = Math.floor(Math.min(-27,...points.map(p=>p.min))-1);\n    const max = Math.ceil(Math.max(-25,...points.map(p=>p.max))+1);\n    const left=52, right=716, top=22, bottom=180;\n    const first=points[0].time, last=points[points.length-1].time;\n    const x=t=>last===first ? (left+right)/2 : left+(t-first)/(last-first)*(right-left);\n    const y=v=>bottom-(v-min)/(max-min)*(bottom-top);\n    const format=v=>Number(v).toFixed(2).replace(\'.\',\',\');\n    const date=t=>new Date(t).toLocaleString(\'pt-BR\',{day:\'2-digit\',month:\'2-digit\',hour:\'2-digit\',minute:\'2-digit\'});\n    let path=\'\', gaps=0, previous=null;\n    for (const p of points) {\n        const gap=previous!==null && p.time-previous>bucket*1500;\n        if (gap) gaps++;\n        path+=(previous===null || gap ? \'M\':\'L\')+x(p.time).toFixed(2)+\',\'+y(p.rx).toFixed(2)+\' \';\n        previous=p.time;\n    }\n    const guides=[...new Set([min,-27,-25,max])].sort((a,b)=>a-b).map(v=>\n        `<line x1="${left}" x2="${right}" y1="${y(v)}" y2="${y(v)}" stroke="${v===-27?\'#f87171\':v===-25?\'#fbbf24\':\'#3d4554\'}" stroke-dasharray="4 5"/><text x="44" y="${y(v)+4}" text-anchor="end" fill="#aeb6c4" font-size="11">${v}</text>`).join(\'\');\n    const dots=points.map(p=>`<circle cx="${x(p.time)}" cy="${y(p.rx)}" r="3.5" fill="#ff555d"><title>${esc(date(p.time))} | RX ${format(p.rx)} dBm | min ${format(p.min)} / max ${format(p.max)}</title></circle>`).join(\'\');\n    const stats=data.stats||{}, worst=Math.min(...points.map(p=>p.min)), best=Math.max(...points.map(p=>p.max));\n    const count=numeric(stats.samples);\n    const seen=stats.last_seen_at ? new Date(stats.last_seen_at) : null;\n    const seenText=seen && Number.isFinite(seen.getTime()) ? \' | Ultima observacao: \'+seen.toLocaleString(\'pt-BR\') : \'\';\n    const gapText=gaps ? ` | ${gaps} lacuna(s) sem coletas. Os intervalos sem dados nao foram preenchidos.` : \'\';\n    const singleText=points.length===1 ? \'Uma faixa de tempo com leitura: ainda nao ha uma serie continua.\' : `${points.length} intervalos com dados reais${gapText}`;\n    return `<div class="acs-history-stats"><span>Menor RX <strong>${format(worst)} dBm</strong></span><span>Maior RX <strong>${format(best)} dBm</strong></span><span>Variacao <strong>${format(best-worst)} dB</strong></span><span>Leituras <strong>${count===null?\'--\':count}</strong></span></div>\n    <svg class="acs-history-chart" data-jrc-chart-fix="optical-v1" viewBox="0 0 760 224" style="display:block;width:100%;height:220px" role="img" aria-label="Historico optico real; lacunas sem interpolacao"><text x="${left}" y="12" fill="#aeb6c4" font-size="11">Potencia RX (dBm)</text>${guides}<path d="${path}" fill="none" stroke="#ff555d" stroke-width="2"/>${dots}<text x="${left}" y="210" fill="#aeb6c4" font-size="11">${esc(date(first))}</text>${last!==first?`<text x="${right}" y="210" text-anchor="end" fill="#aeb6c4" font-size="11">${esc(date(last))}</text>`:\'\'}</svg>\n    <p class="small text-muted mb-0">${esc(singleText)}</p><p class="small text-muted mb-0">IXC | Media por intervalo de ${bucket/60} min | Referencias da interface: -25 e -27 dBm${esc(seenText)}</p>${Number(stats.unconfirmed_time)>0?\'<p class="small text-warning">Ha leituras sem horario de medicao informado pelo IXC; nesses casos vale o horario de observacao.</p>\':\'\'}`;\n}', 'renderModernBandwidthChart': 'function renderModernBandwidthChart(samples) {\n    const valid=(Array.isArray(samples)?samples:[]).filter(s=>Number.isFinite(s.time)&&Number.isFinite(s.rxMbps)&&Number.isFinite(s.txMbps)&&s.rxMbps>=0&&s.txMbps>=0).slice(-60).sort((a,b)=>a.time-b.time);\n    if (!valid.length) return \'<div class="acs-chart-wait" role="status">Ainda sem amostras validas de trafego. O motivo da consulta aparece abaixo.</div>\';\n    const left=66,right=704,top=18,bottom=174;\n    const peak=Math.max(...valid.flatMap(s=>[s.rxMbps,s.txMbps]));\n    const upper=Math.max(0.1,Math.ceil(peak*1.15*10)/10);\n    const first=valid[0].time,last=valid[valid.length-1].time;\n    const x=t=>last===first ? (left+right)/2 : left+(t-first)/(last-first)*(right-left);\n    const y=v=>bottom-v/upper*(bottom-top);\n    const stamp=t=>new Date(t).toLocaleTimeString(\'pt-BR\');\n    const line=(field,color)=>{\n        let previous=null,path=\'\';\n        for (const s of valid){path+=(previous===null||s.time-previous>30000?\'M\':\'L\')+x(s.time).toFixed(2)+\',\'+y(s[field]).toFixed(2)+\' \';previous=s.time;}\n        const s=valid[valid.length-1];\n        return `<path d="${path}" fill="none" stroke="${color}" stroke-width="2"/><circle cx="${x(s.time)}" cy="${y(s[field])}" r="3.5" fill="${color}"><title>${stamp(s.time)} | ${s[field].toFixed(3)} Mbps</title></circle>`;\n    };\n    const grid=[0,upper/2,upper].map(v=>`<line x1="${left}" x2="${right}" y1="${y(v)}" y2="${y(v)}" stroke="#364152" stroke-dasharray="4 5"/><text x="58" y="${y(v)+4}" text-anchor="end" fill="#aeb6c4" font-size="11">${v.toFixed(2)}</text>`).join(\'\');\n    const elapsed=Math.max(0,Math.round((last-first)/1000));\n    return `<svg data-jrc-chart-fix="wan-v1" viewBox="0 0 740 220" style="display:block;width:100%;height:220px" role="img" aria-label="Download e upload medidos"><text x="${left}" y="12" fill="#aeb6c4" font-size="11">Mbps</text>${grid}${line(\'rxMbps\',\'#ff5b63\')}${line(\'txMbps\',\'#60a5fa\')}<text x="${left}" y="202" fill="#aeb6c4" font-size="11">${stamp(first)}</text>${last!==first?`<text x="${right}" y="202" text-anchor="end" fill="#aeb6c4" font-size="11">${stamp(last)}</text>`:\'\'}</svg><div class="small text-muted"><span style="color:#ff5b63">Download</span> / <span style="color:#60a5fa">Upload</span> | ${valid.length} amostra(s) recebida(s) em ${elapsed}s</div>`;\n}', 'renderBandwidthLiveSample': "function renderBandwidthLiveSample(live, sourceLabel) {\n    const number=v=>(typeof v==='number'||(typeof v==='string'&&v.trim()!==''))&&Number.isFinite(Number(v))&&Number(v)>=0 ? Number(v):null;\n    const rx=number(live?.download_mbps),tx=number(live?.upload_mbps);\n    if(rx===null||tx===null) return false;\n    const now=Date.now();\n    bandwidthSamples.push({time:now,rx:null,tx:null,rxMbps:rx,txMbps:tx,source:String(sourceLabel||'Fonte nao informada')});\n    if(bandwidthSamples.length>60)bandwidthSamples.splice(0,bandwidthSamples.length-60);\n    const format=v=>v.toFixed(v>0&&v<0.01?4:2);\n    const set=(id,text)=>{const node=document.getElementById(id);if(node)node.textContent=text;};\n    set('live-rx-mbps',format(rx));set('live-tx-mbps',format(tx));\n    set('bandwidth-sample-status',String(sourceLabel||'Fonte nao informada')+' | Amostra recebida: '+new Date(now).toLocaleTimeString('pt-BR'));\n    const chart=document.getElementById('bandwidth-bars');\n    if(chart)chart.innerHTML=renderModernBandwidthChart(bandwidthSamples);\n    return true;\n}", 'fetchBandwidthJson': "async function fetchBandwidthJson(url) {\n    const controller=new AbortController();\n    const timeout=setTimeout(()=>controller.abort(),15000);\n    try {\n        const parsed=new URL(url,window.location.href);\n        const allowed=['/api/get-radius-session.php','/api/get-ixc-live-traffic.php','/api/get-ne-live-traffic.php','/api/get-tr069-live-traffic.php'];\n        if(parsed.origin!==window.location.origin || !allowed.includes(parsed.pathname)) return {success:false,available:false,message:'Consulta de trafego recusada: endpoint inesperado.'};\n        const response=await fetch(parsed.href,{credentials:'same-origin',cache:'no-store',signal:controller.signal});\n        if(!response.ok)return {success:false,available:false,message:'Consulta de trafego retornou HTTP '+response.status+'.'};\n        const type=response.headers.get('content-type')||'';\n        if(!type.includes('json'))return {success:false,available:false,message:'A consulta nao retornou JSON. Verifique a sessao de acesso.'};\n        const data=await response.json();\n        if(!data||typeof data!=='object'||Array.isArray(data))return {success:false,available:false,message:'Formato de resposta de trafego invalido.'};\n        if(!data.success)return {success:false,available:false,message:typeof data.message==='string'?data.message:'Fonte de trafego indisponivel.'};\n        return data;\n    } catch(error) {\n        return {success:false,available:false,message:error?.name==='AbortError'?'Tempo limite de 15s na consulta de trafego.':'Falha de rede ou resposta invalida na consulta de trafego.'};\n    } finally {clearTimeout(timeout);}\n}", 'updateRadiusBandwidthSample': "async function updateRadiusBandwidthSample() {\n    const setStatus=text=>{const el=document.getElementById('bandwidth-sample-status');if(el)el.textContent=text;};\n    const emptyRates=()=>{for(const id of ['live-rx-mbps','live-tx-mbps']){const el=document.getElementById(id);if(el)el.textContent='--';}};\n    const paint=()=>{const chart=document.getElementById('bandwidth-bars');if(chart)chart.innerHTML=renderModernBandwidthChart(bandwidthSamples);};\n    if(!deviceId || !document.getElementById('bandwidth-bars'))return;\n    paint();\n    if(bandwidthSampleRequestInFlight){setStatus('Consulta em andamento; aguardando resposta da fonte.');return;}\n    bandwidthSampleRequestInFlight=true;\n    const reasons=[];\n    try {\n        setStatus('Consultando sessao IXC/RADIUS...');\n        const radius=await fetchBandwidthJson('/api/get-radius-session.php?device_id='+encodeURIComponent(deviceId));\n        if(!radius?.success){emptyRates();setStatus('IXC/RADIUS: '+(radius?.message||'Consulta indisponivel.'));return;}\n        const session=radius.session;\n        if(!radius.online || !session){emptyRates();setStatus('A consulta nao localizou uma sessao PPPoE online no IXC/RADIUS. O estado WAN, sozinho, nao fornece uma taxa de trafego.');return;}\n        const downloadTotal=toCounter(session.download_bytes),uploadTotal=toCounter(session.upload_bytes);\n        for(const [id,value] of [['live-rx-total',downloadTotal],['live-tx-total',uploadTotal]]){const el=document.getElementById(id);if(el)el.textContent=formatTrafficBytes(value);}\n        const source=document.getElementById('live-traffic-source');\n        if(source)source.textContent='IXC/RADIUS | '+(session.interface||'sessao PPPoE');\n        const accept=(result,label)=>!!(result?.success&&result?.available&&renderBandwidthLiveSample(result.live,result.source||label));\n        const loginId=Number(radius.ixc_login_id);\n        if(Number.isInteger(loginId)&&loginId>0){\n            setStatus('Consultando trafego no IXC...');\n            const live=await fetchBandwidthJson('/api/get-ixc-live-traffic.php?login_id='+encodeURIComponent(loginId));\n            if(accept(live,'IXC'))return;\n            reasons.push('IXC: '+(live?.message||'sem amostra valida'));\n        }\n        setStatus('Consultando trafego no concentrador...');\n        const params=new URLSearchParams({ip:session.ip||'',username:session.username||'',nas_ip:session.bras||'',session_id:session.session_id||''});\n        const ne=await fetchBandwidthJson('/api/get-ne-live-traffic.php?'+params);\n        if(accept(ne,'Concentrador'))return;\n        reasons.push('Concentrador: '+(ne?.message||'sem amostra valida'));\n        setStatus('Consultando contadores TR-069 da sessao...');\n        const tr=await fetchBandwidthJson('/api/get-tr069-live-traffic.php?device_id='+encodeURIComponent(deviceId));\n        const management=/WAN common/i.test(String(tr?.profile||''));\n        if(!management && accept(tr,'TR-069'))return;\n        reasons.push(management?'TR-069: contador WANCommon excluido, conforme politica existente.':'TR-069: '+(tr?.message||'sem segunda amostra valida'));\n        emptyRates();\n        setStatus(reasons.join(' | '));\n    } catch(error) {\n        emptyRates();setStatus('Falha ao atualizar o grafico WAN. As amostras anteriores foram preservadas.');\n        console.warn('[JRC device charts]',error);\n    } finally {\n        bandwidthSampleRequestInFlight=false;\n        paint();\n    }\n}"}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def find_function(text, name):
    pattern = re.compile(r'(?m)^(?:async[ \t]+)?function[ \t]+' + re.escape(name) + r'[ \t]*\([^\r\n]*\)[ \t]*\{[ \t]*\r?$')
    starts = list(pattern.finditer(text))
    if len(starts) != 1:
        raise RuntimeError('Compatibilidade: funcao %s encontrada %d vez(es). Nenhum arquivo deve ser substituido por inteiro.' % (name, len(starts)))
    start = starts[0]
    end = re.search(r'(?m)^}[ \t]*;?[ \t]*\r?$', text[start.end():])
    if not end:
        raise RuntimeError('Fechamento da funcao %s nao reconhecido.' % name)
    finish = start.end() + end.end()
    block = text[start.start():finish]
    if re.search(r'(?m)^(?:async[ \t]+)?function[ \t]+', block[len(start.group()):]):
        raise RuntimeError('Limites de %s ambiguos; operacao cancelada.' % name)
    return start.start(), finish, block


def prepare(root):
    outputs = {}
    # Reject page-local overrides rather than editing an inactive implementation.
    page = root / 'device-detail.php'
    if page.is_file():
        page_text = page.read_text(encoding='utf-8')
        for names in TARGETS.values():
            for name in names:
                if re.search(r'(?m)^\s*(?:async\s+)?function\s+' + re.escape(name) + r'\s*\(', page_text):
                    raise RuntimeError('A funcao ' + name + ' tambem existe no PHP da pagina. Cancelado para nao editar a implementacao errada.')
    for relative, functions in TARGETS.items():
        path = root / relative
        if not path.is_file() or path.is_symlink():
            raise RuntimeError('Arquivo regular ausente ou link simbolico recusado: ' + relative)
        if root not in path.resolve().parents:
            raise RuntimeError('Caminho fora da aplicacao: ' + relative)
        original = path.read_bytes()
        text = original.decode('utf-8')
        if VERSION in text:
            raise RuntimeError('A correcao V1 ja aparece em ' + relative + '. Nao foi aplicada novamente.')
        if relative.endswith('device-detail.js'):
            for token in ['bandwidthSamples', 'bandwidthSampleRequestInFlight', 'toCounter', 'formatTrafficBytes', 'deviceId', 'bandwidth-bars', 'bandwidth-sample-status', 'live-rx-mbps', 'live-tx-mbps']:
                if token not in text:
                    raise RuntimeError('Versao local nao compativel: falta ' + token)
            _, _, old = find_function(text, 'updateRadiusBandwidthSample')
            for endpoint in ['get-radius-session.php', 'get-ixc-live-traffic.php', 'get-ne-live-traffic.php', 'get-tr069-live-traffic.php']:
                if endpoint not in old:
                    raise RuntimeError('Fluxo WAN diferente do revisado: falta ' + endpoint + '. Cancelado para preservar a sua versao.')
        newline = '\r\n' if '\r\n' in text else '\n'
        for name in functions:
            start, end, _ = find_function(text, name)
            replacement = REPLACEMENTS[name].replace('\n', newline)
            text = text[:start] + replacement + text[end:]
        text = '/* ' + VERSION + ' */' + newline + text
        outputs[relative] = {'before': original, 'after': text.encode('utf-8')}
    return outputs


def check_javascript(outputs):
    node = shutil.which('node') or shutil.which('nodejs')
    if not node:
        raise RuntimeError('Node.js nao encontrado para validar a sintaxe dos arquivos modificados. Nada foi alterado. Nao instale dependencias nem force a alteracao; envie esta mensagem.')
    for relative, data in outputs.items():
        with tempfile.NamedTemporaryFile(suffix='.js') as tmp:
            tmp.write(data['after']); tmp.flush()
            result = subprocess.run([node, '--check', tmp.name], capture_output=True, text=True)
            if result.returncode:
                raise RuntimeError('Validacao JavaScript falhou em ' + relative + ':\n' + result.stderr[:2000])


def atomic_write(path, data, metadata):
    fd, temp = tempfile.mkstemp(prefix='.jrc-chart-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data); out.flush(); os.fsync(out.fileno())
            os.fchmod(out.fileno(), stat.S_IMODE(metadata.st_mode))
            if os.geteuid() == 0:
                os.fchown(out.fileno(), metadata.st_uid, metadata.st_gid)
        os.replace(temp, path)
    finally:
        if os.path.exists(temp): os.unlink(temp)


def install(root, backup_parent, outputs):
    for relative, data in outputs.items():
        if (root / relative).read_bytes() != data['before']:
            raise RuntimeError('Arquivo editado durante a verificacao: ' + relative)
    backup = Path(tempfile.mkdtemp(prefix='gacs-device-charts-' + datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + '-', dir=str(backup_parent)))
    os.chmod(backup, 0o700)
    manifest = {'version': VERSION, 'root': str(root), 'files': {}}
    for relative, data in outputs.items():
        dest = backup / relative
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(root / relative, dest)
        manifest['files'][relative] = {'before': digest(data['before']), 'after': digest(data['after'])}
    shutil.copy2(Path(__file__).resolve(), backup / 'install.py')
    (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    diff = ''.join(''.join(difflib.unified_diff(v['before'].decode('utf-8').splitlines(True), v['after'].decode('utf-8').splitlines(True), fromfile=k, tofile=k)) for k, v in outputs.items())
    (backup / 'changes.diff').write_text(diff, encoding='utf-8')
    applied = []
    try:
        for relative, data in outputs.items():
            path = root / relative
            if path.read_bytes() != data['before']:
                raise RuntimeError('Mudanca concorrente detectada: ' + relative)
            atomic_write(path, data['after'], path.stat())
            applied.append(relative)
    except Exception:
        for relative in reversed(applied):
            path = root / relative
            if digest(path.read_bytes()) == manifest['files'][relative]['after']:
                atomic_write(path, (backup / relative).read_bytes(), path.stat())
        raise
    pointer = backup_parent / 'gacs-device-charts-ultimo'
    pointer.write_text(str(backup) + '\n', encoding='utf-8')
    os.chmod(pointer, 0o600)
    print('INSTALADO: tratamento de espera WAN e desenho do historico optico.')
    print('Backup: ' + str(backup))
    print('Somente dois arquivos JavaScript foram alterados. Nenhuma API ou configuracao alterada.')
    print('Atualize a pagina do dispositivo com Ctrl+F5.')
    print('Se faltarem dados, a tela informara a causa; este instalador nao cria medicoes.')
    print('REVERTER: python3 ' + str(backup / 'install.py') + ' --rollback ' + str(backup))


def rollback(backup):
    manifest = json.loads((backup / 'manifest.json').read_text(encoding='utf-8'))
    if manifest.get('version') != VERSION or set(manifest.get('files', {})) != set(TARGETS):
        raise RuntimeError('Manifesto de backup invalido.')
    root = Path(manifest['root']).resolve()
    for relative, hashes in manifest['files'].items():
        path = root / relative
        if path.is_symlink() or digest(path.read_bytes()) != hashes['after']:
            raise RuntimeError('Reversao cancelada: arquivo editado apos a instalacao: ' + relative)
        if digest((backup / relative).read_bytes()) != hashes['before']:
            raise RuntimeError('Backup alterado ou corrompido: ' + relative)
    for relative in manifest['files']:
        path = root / relative
        atomic_write(path, (backup / relative).read_bytes(), path.stat())
        shutil.copystat(backup / relative, path)
    print('RESTAURADO: os dois arquivos voltaram ao estado anterior a esta correcao.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/var/www/gacs')
    parser.add_argument('--backup-dir', default='/root')
    group = parser.add_mutually_exclusive_group()
    group.add_argument('--dry-run', action='store_true')
    group.add_argument('--rollback', type=Path)
    args = parser.parse_args()
    if args.rollback:
        parent = args.rollback.resolve().parent
    else:
        parent = Path(args.backup_dir).resolve()
    if not parent.is_dir():
        raise RuntimeError('Diretorio de backup inexistente: ' + str(parent))
    with (parent / '.gacs-device-charts.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args.rollback:
            rollback(args.rollback.resolve()); return
        root = Path(args.root).resolve()
        outputs = prepare(root)
        check_javascript(outputs)
        for relative, data in outputs.items():
            print('VALIDADO: ' + relative + ' | ' + str(len(TARGETS[relative])) + ' funcao(oes) | SHA256 anterior ' + digest(data['before']))
        if args.dry_run:
            print('SIMULACAO OK: nenhum arquivo da aplicacao alterado.'); return
        install(root, parent, outputs)

if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('CANCELADO: ' + str(exc), file=sys.stderr)
        sys.exit(1)
