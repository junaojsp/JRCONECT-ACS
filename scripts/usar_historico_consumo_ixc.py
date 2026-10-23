#!/usr/bin/env python3
"""Switch WAN history source from local buckets to IXC radusuarios_consumo, with local fallback."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_WAN_HISTORY_IXC_V1'
API_REL='api/get-wan-traffic-history.php'
JS_REL='assets/js/device-control-fix.js'

API=r'''<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/IXCConfig.php';
if (is_file(__DIR__ . '/../lib/WanTrafficHistory.php')) require_once __DIR__ . '/../lib/WanTrafficHistory.php';

if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jrcIxcRequest(string $entity, array $query): array
{
    $cfg = getIxcConfig();
    $url = rtrim((string)$cfg['base_url'], '/') . '/webservice/v1/' . $entity . '?' . http_build_query($query);
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('curl_init_failed');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode((string)$cfg['token']),
        ],
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $http < 200 || $http >= 300) {
        throw new RuntimeException('ixc_http_' . $http . ($err ? '_' . $err : ''));
    }
    $json = json_decode((string)$body, true);
    if (!is_array($json)) throw new RuntimeException('ixc_json_invalid');
    $rows = isset($json['registros']) && is_array($json['registros'])
        ? $json['registros']
        : (array_is_list($json) ? $json : []);
    return [$json, $rows];
}

function jrcNum(array $row, array $keys): float
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && is_numeric($row[$k])) return max(0.0, (float)$row[$k]);
    }
    return 0.0;
}

function jrcDate(array $row): ?int
{
    foreach (['data','data_hora','datahora','datetime','created_at','dt_criacao'] as $k) {
        if (empty($row[$k])) continue;
        $v = trim((string)$row[$k]);
        $ts = strtotime($v);
        if ($ts !== false) return $ts;
    }
    return null;
}

function jrcBuildIxcHistory(string $loginId, int $hours): array
{
    $hours = max(1, min(720, $hours));
    [$meta, $rows] = jrcIxcRequest('radusuarios_consumo', [
        'qtype' => 'radusuarios_consumo.id_login',
        'query' => $loginId,
        'oper' => '=',
        'page' => '1',
        'rp' => '2000',
        'sort' => 'id',
        'order' => 'desc',
    ]);

    $cutoff = time() - ($hours * 3600);
    $daily = [];
    $rxTotal = 0.0;
    $txTotal = 0.0;

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $ts = jrcDate($row);
        if ($ts !== null && $ts < $cutoff) continue;

        $rx = jrcNum($row, ['consumo','download','download_bytes','bytes_download','acctinputoctets']);
        $tx = jrcNum($row, ['consumo_upload','upload','upload_bytes','bytes_upload','acctoutputoctets']);

        // Some IXC views expose MB values. Prefer native consumo fields when present.
        if ($rx <= 0 && isset($row['download_mb']) && is_numeric($row['download_mb'])) $rx = (float)$row['download_mb'] * 1048576;
        if ($tx <= 0 && isset($row['upload_mb']) && is_numeric($row['upload_mb'])) $tx = (float)$row['upload_mb'] * 1048576;

        if ($rx <= 0 && $tx <= 0) continue;
        $dayTs = $ts ?? time();
        $key = date('Y-m-d', $dayTs);
        if (!isset($daily[$key])) $daily[$key] = ['t'=>strtotime($key.' 12:00:00')*1000,'rx_bytes'=>0.0,'tx_bytes'=>0.0,'samples'=>0];
        $daily[$key]['rx_bytes'] += $rx;
        $daily[$key]['tx_bytes'] += $tx;
        $daily[$key]['samples']++;
        $rxTotal += $rx;
        $txTotal += $tx;
    }

    ksort($daily);
    $points = array_values($daily);
    foreach ($points as &$p) {
        $p['rx_mbps'] = 0.0;
        $p['tx_mbps'] = 0.0;
        $p['rx_peak_mbps'] = 0.0;
        $p['tx_peak_mbps'] = 0.0;
    }
    unset($p);

    return [
        'login_id'=>(int)$loginId,
        'hours'=>$hours,
        'source'=>'IXC radusuarios_consumo',
        'points'=>$points,
        'summary'=>[
            'samples'=>count($rows),
            'rx_avg_mbps'=>0,
            'tx_avg_mbps'=>0,
            'rx_peak_mbps'=>0,
            'tx_peak_mbps'=>0,
            'rx_bytes'=>$rxTotal,
            'tx_bytes'=>$txTotal,
            'total_bytes'=>$rxTotal+$txTotal
        ]
    ];
}

try {
    $loginId = trim((string)($_GET['login_id'] ?? ''));
    $hours = (int)($_GET['hours'] ?? 720);
    if ($loginId === '' || !preg_match('/^\d+$/', $loginId)) throw new InvalidArgumentException('login_id invalido');

    try {
        $history = jrcBuildIxcHistory($loginId, $hours);
        if (!empty($history['points'])) {
            echo json_encode(['success'=>true,'history'=>$history], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
    } catch (Throwable $ixcError) {
        // fallback below
    }

    if (class_exists('WanTrafficHistory')) {
        $history = WanTrafficHistory::query($loginId, $hours);
        $history['source'] = 'ACS local';
        echo json_encode(['success'=>true,'history'=>$history], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    echo json_encode(['success'=>true,'history'=>[
        'login_id'=>(int)$loginId,'hours'=>$hours,'source'=>'sem dados','points'=>[],
        'summary'=>['samples'=>0,'rx_avg_mbps'=>0,'tx_avg_mbps'=>0,'rx_peak_mbps'=>0,'tx_peak_mbps'=>0,'rx_bytes'=>0,'tx_bytes'=>0,'total_bytes'=>0]
    ]]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'historico WAN indisponivel']);
}
''';

JS_PATCH=r'''
/* JRC_WAN_HISTORY_IXC_V1 */
(() => {
    function fmtBytes(v){
        const n=Math.max(0,Number(v)||0), u=['B','KB','MB','GB','TB']; let x=n,i=0;
        while(x>=1024&&i<u.length-1){x/=1024;i++;}
        return (x>=100?x.toFixed(0):x>=10?x.toFixed(1):x.toFixed(2))+' '+u[i];
    }

    const oldSvg = window.jrcWanHistorySvg;
    window.jrcWanHistorySvg = function(points){
        if(!Array.isArray(points)||!points.length){
            return '<div class="jrc-wan-history-empty">Nenhum histórico encontrado no IXC para este período.</div>';
        }
        const hasVolume = points.some(p => Number(p.rx_bytes||0)>0 || Number(p.tx_bytes||0)>0);
        if(!hasVolume && typeof oldSvg==='function') return oldSvg(points);

        const W=1000,H=270,L=70,R=980,T=18,B=220,pw=R-L,ph=B-T;
        const max=Math.max(1,...points.flatMap(p=>[Number(p.rx_bytes)||0,Number(p.tx_bytes)||0]));
        const x=i=>points.length===1?L+pw/2:L+(i/(points.length-1))*pw;
        const y=v=>B-(Math.max(0,Number(v)||0)/max)*ph;
        const barW=Math.max(5,Math.min(28,pw/Math.max(1,points.length)/3));
        let bars='';
        points.forEach((p,i)=>{
            const cx=x(i), rx=Number(p.rx_bytes)||0, tx=Number(p.tx_bytes)||0;
            bars += '<rect x="'+(cx-barW-1).toFixed(1)+'" y="'+y(rx).toFixed(1)+'" width="'+barW.toFixed(1)+'" height="'+(B-y(rx)).toFixed(1)+'" fill="rgba(56,189,248,.58)" stroke="#38bdf8"/>';
            bars += '<rect x="'+(cx+1).toFixed(1)+'" y="'+y(tx).toFixed(1)+'" width="'+barW.toFixed(1)+'" height="'+(B-y(tx)).toFixed(1)+'" fill="rgba(52,211,153,.58)" stroke="#34d399"/>';
        });
        const first=new Date(points[0].t).toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'});
        const last=new Date(points[points.length-1].t).toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'});
        return '<svg viewBox="0 0 '+W+' '+H+'" preserveAspectRatio="none" style="width:100%;height:270px;display:block">'+
            '<line x1="'+L+'" y1="'+T+'" x2="'+R+'" y2="'+T+'" stroke="#334155" stroke-dasharray="4 5"/>'+
            '<line x1="'+L+'" y1="'+((T+B)/2)+'" x2="'+R+'" y2="'+((T+B)/2)+'" stroke="#334155" stroke-dasharray="4 5"/>'+
            '<line x1="'+L+'" y1="'+B+'" x2="'+R+'" y2="'+B+'" stroke="#475569"/>'+
            '<text x="'+(L-8)+'" y="'+(T+4)+'" text-anchor="end" fill="#8190a5" font-size="12">'+fmtBytes(max)+'</text>'+
            '<text x="'+(L-8)+'" y="'+(((T+B)/2)+4)+'" text-anchor="end" fill="#8190a5" font-size="12">'+fmtBytes(max/2)+'</text>'+
            '<text x="'+(L-8)+'" y="'+(B+4)+'" text-anchor="end" fill="#8190a5" font-size="12">0</text>'+
            bars+
            '<text x="'+L+'" y="248" fill="#8190a5" font-size="11">'+first+'</text>'+
            '<text x="'+R+'" y="248" text-anchor="end" fill="#8190a5" font-size="11">'+last+'</text></svg>'+
            '<div class="jrc-wan-history-legend"><span><i class="rx"></i>Download</span><span><i class="tx"></i>Upload</span></div>';
    };

    const observer=new MutationObserver(()=>{
        const h=document.querySelector('#wan-content .jrc-wan-history');
        if(!h) return;
        const small=h.querySelector('.jrc-wan-history-head small');
        if(small) small.textContent='Histórico de consumo do IXC · dados já existentes do login';
        const labels=h.querySelectorAll('.jrc-wan-history-stat span');
        const names=['Download total','Pico download','Upload total','Pico upload','Recebido','Enviado'];
        labels.forEach((el,i)=>{if(names[i])el.textContent=names[i];});
    });
    observer.observe(document.documentElement,{childList:true,subtree:true});
})();
''';

def sha(b): return hashlib.sha256(b).hexdigest()

def check_php(data):
    exe=shutil.which('php')
    if not exe: raise RuntimeError('php ausente')
    with tempfile.NamedTemporaryFile(suffix='.php') as f:
        f.write(data); f.flush()
        r=subprocess.run([exe,'-l',f.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError((r.stderr or r.stdout)[:1600])

def check_js(data):
    exe=shutil.which('node')
    if not exe: raise RuntimeError('node ausente')
    with tempfile.NamedTemporaryFile(suffix='.js') as f:
        f.write(data); f.flush()
        r=subprocess.run([exe,'--check',f.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError((r.stderr or r.stdout)[:1600])

def atomic(path,data,meta):
    fd,name=tempfile.mkstemp(prefix='.jrc-history-ixc-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data); out.flush(); os.fsync(out.fileno())
            os.fchmod(out.fileno(),stat.S_IMODE(meta.st_mode))
            if os.geteuid()==0: os.fchown(out.fileno(),meta.st_uid,meta.st_gid)
        os.replace(name,path)
    finally:
        if os.path.exists(name): os.unlink(name)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--root',default='/var/www/gacs'); ap.add_argument('--backup-dir',default='/root')
    ap.add_argument('--dry-run',action='store_true')
    a=ap.parse_args()
    root=Path(a.root).resolve(); parent=Path(a.backup_dir).resolve()
    api=root/API_REL; js=root/JS_REL
    for p in [api,js]:
        if not p.is_file() or p.is_symlink(): raise RuntimeError(str(p)+' nao encontrado')

    with (parent/'.jrc-wan-history-ixc.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        api_before=api.read_bytes(); js_before=js.read_bytes()
        api_after=API.encode()
        text=js_before.decode('utf-8')
        js_after=js_before if VERSION in text else (text+('\n' if not text.endswith('\n') else '')+JS_PATCH).encode()
        check_php(api_after); check_js(js_after)
        print('VALIDADO: historico WAN passara a usar radusuarios_consumo do IXC.')
        print('Fallback local sera mantido se o IXC nao devolver dados.')
        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.'); return

        if api.read_bytes()!=api_before or js.read_bytes()!=js_before: raise RuntimeError('edicao concorrente detectada')
        backup=Path(tempfile.mkdtemp(prefix='gacs-wan-history-ixc-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',dir=str(parent)))
        os.chmod(backup,0o700)
        (backup/'api.php').write_bytes(api_before); (backup/'device-control-fix.js').write_bytes(js_before)
        (backup/'install.py').write_bytes(Path(__file__).read_bytes())
        atomic(api,api_after,api.stat()); atomic(js,js_after,js.stat())
        print('INSTALADO: historico WAN agora consulta o IXC primeiro.')
        print('Use 30 dias para comparar com o grafico do diagnostico IXC.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr); sys.exit(1)
