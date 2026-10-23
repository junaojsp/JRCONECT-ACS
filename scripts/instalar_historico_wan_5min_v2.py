#!/usr/bin/env python3
"""Install WAN 5-minute history persistence + WAN tab history chart. Live 1s graph is preserved."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, re, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_WAN_HISTORY_V1'
LIB_REL='lib/WanTrafficHistory.php'
API_REL='api/get-wan-traffic-history.php'
STREAM_REL='api/stream-ixc-live-traffic.php'
JS_REL='assets/js/device-control-fix.js'
CSS_REL='assets/css/device-control-fix.css'

LIB = r'''<?php
declare(strict_types=1);

final class WanTrafficHistory
{
    private const BUCKET_SECONDS = 300;
    private const RETENTION_SECONDS = 2678400; // 31 days
    private const FLUSH_SECONDS = 10;
    private static array $states = [];
    private static bool $shutdownRegistered = false;

    private static function baseDir(): string
    {
        return '/var/lib/jrc-acs/wan-history';
    }

    private static function fileFor(string $loginId): string
    {
        if (!preg_match('/^\d+$/', $loginId)) {
            throw new InvalidArgumentException('login_id invalido');
        }
        return self::baseDir() . '/' . $loginId . '.json';
    }

    private static function load(string $loginId): array
    {
        if (isset(self::$states[$loginId])) return self::$states[$loginId];

        $path = self::fileFor($loginId);
        $data = ['version'=>1,'login_id'=>(int)$loginId,'buckets'=>[],'last'=>null];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded) && isset($decoded['buckets']) && is_array($decoded['buckets'])) {
                $data = $decoded;
            }
        }
        $data['_dirty'] = false;
        $data['_last_flush'] = microtime(true);
        self::$states[$loginId] = $data;

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function(): void {
                foreach (array_keys(self::$states) as $id) {
                    try { self::flush((string)$id, true); } catch (Throwable $e) {}
                }
            });
        }
        return self::$states[$loginId];
    }

    public static function record(string $loginId, float $rxBps, float $txBps, ?float $timestamp = null): void
    {
        $now = $timestamp ?? microtime(true);
        $rxBps = max(0.0, $rxBps);
        $txBps = max(0.0, $txBps);

        $state = self::load($loginId);
        $bucketStart = (int)(floor($now / self::BUCKET_SECONDS) * self::BUCKET_SECONDS);
        $key = (string)$bucketStart;

        if (!isset($state['buckets'][$key])) {
            $state['buckets'][$key] = [
                't'=>$bucketStart,
                'samples'=>0,
                'rx_sum'=>0.0,
                'tx_sum'=>0.0,
                'rx_peak'=>0.0,
                'tx_peak'=>0.0,
                'rx_bytes'=>0.0,
                'tx_bytes'=>0.0
            ];
        }

        $b =& $state['buckets'][$key];
        $b['samples']++;
        $b['rx_sum'] += $rxBps;
        $b['tx_sum'] += $txBps;
        $b['rx_peak'] = max((float)$b['rx_peak'], $rxBps);
        $b['tx_peak'] = max((float)$b['tx_peak'], $txBps);

        $last = $state['last'] ?? null;
        if (is_array($last) && isset($last['ts'],$last['rx'],$last['tx'])) {
            $dt = max(0.0, min(5.0, $now - (float)$last['ts']));
            if ($dt > 0) {
                $avgRx = (((float)$last['rx']) + $rxBps) / 2.0;
                $avgTx = (((float)$last['tx']) + $txBps) / 2.0;
                $b['rx_bytes'] += ($avgRx * $dt) / 8.0;
                $b['tx_bytes'] += ($avgTx * $dt) / 8.0;
            }
        }
        $state['last'] = ['ts'=>$now,'rx'=>$rxBps,'tx'=>$txBps];

        $cutoff = time() - self::RETENTION_SECONDS;
        foreach ($state['buckets'] as $k => $row) {
            if ((int)($row['t'] ?? 0) < $cutoff) unset($state['buckets'][$k]);
        }

        $state['_dirty'] = true;
        self::$states[$loginId] = $state;
        if (($now - (float)($state['_last_flush'] ?? 0)) >= self::FLUSH_SECONDS) {
            self::flush($loginId);
        }
    }

    public static function feedNativeChunk(string $loginId, string $chunk): void
    {
        static $buffers = [];
        $buffers[$loginId] = ($buffers[$loginId] ?? '') . $chunk;
        while (preg_match('/^(.*?)(?:\r\n\r\n|\n\n|\r\r)/s', $buffers[$loginId], $m)) {
            $block = $m[1];
            $buffers[$loginId] = substr($buffers[$loginId], strlen($m[0]));
            $event = '';
            $data = [];
            foreach (preg_split('/\r\n|\r|\n/', trim($block)) ?: [] as $line) {
                if ($line === '' || str_starts_with($line, ':')) continue;
                [$field,$value] = array_pad(explode(':',$line,2),2,'');
                if (trim($field)==='event') $event=trim($value);
                elseif (trim($field)==='data') $data[]=ltrim($value);
            }
            if (strtolower($event) !== 'trafego' || !$data) continue;
            $json = json_decode(implode("\n",$data), true);
            if (!is_array($json) || !isset($json['rx'],$json['tx'])) continue;
            if (!is_numeric($json['rx']) || !is_numeric($json['tx'])) continue;
            self::record($loginId, (float)$json['rx'], (float)$json['tx']);
        }
        if (strlen($buffers[$loginId]) > 131072) $buffers[$loginId] = substr($buffers[$loginId], -65536);
    }

    public static function flush(string $loginId, bool $force = false): void
    {
        if (!isset(self::$states[$loginId])) return;
        $state = self::$states[$loginId];
        if (!$force && empty($state['_dirty'])) return;

        $dir = self::baseDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('falha ao criar diretorio WAN history');
        }

        unset($state['_dirty'],$state['_last_flush']);
        $path = self::fileFor($loginId);
        $tmp = $path . '.tmp.' . getmypid();
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) throw new RuntimeException('falha json WAN history');
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('falha escrita WAN history');
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('falha rename WAN history');
        }
        $state['_dirty'] = false;
        $state['_last_flush'] = microtime(true);
        self::$states[$loginId] = $state;
    }

    public static function query(string $loginId, int $hours): array
    {
        $hours = max(1, min(720, $hours));
        $path = self::fileFor($loginId);
        $data = ['buckets'=>[]];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) $data = $decoded;
        }
        $cutoff = time() - ($hours * 3600);
        $points = [];
        $rxBytes=$txBytes=$rxWeighted=$txWeighted=0.0;
        $samples=0; $rxPeak=$txPeak=0.0;

        foreach (($data['buckets'] ?? []) as $row) {
            $t=(int)($row['t'] ?? 0);
            if ($t < $cutoff) continue;
            $n=max(1,(int)($row['samples'] ?? 0));
            $rxAvg=((float)($row['rx_sum'] ?? 0))/$n;
            $txAvg=((float)($row['tx_sum'] ?? 0))/$n;
            $rxP=(float)($row['rx_peak'] ?? 0);
            $txP=(float)($row['tx_peak'] ?? 0);
            $points[]=[
                't'=>$t*1000,
                'rx_mbps'=>$rxAvg/1000000,
                'tx_mbps'=>$txAvg/1000000,
                'rx_peak_mbps'=>$rxP/1000000,
                'tx_peak_mbps'=>$txP/1000000,
                'samples'=>$n
            ];
            $samples += $n;
            $rxWeighted += $rxAvg*$n;
            $txWeighted += $txAvg*$n;
            $rxPeak=max($rxPeak,$rxP); $txPeak=max($txPeak,$txP);
            $rxBytes += (float)($row['rx_bytes'] ?? 0);
            $txBytes += (float)($row['tx_bytes'] ?? 0);
        }
        usort($points, static fn($a,$b)=>$a['t']<=>$b['t']);
        return [
            'login_id'=>(int)$loginId,'hours'=>$hours,'bucket_seconds'=>self::BUCKET_SECONDS,
            'points'=>$points,
            'summary'=>[
                'samples'=>$samples,
                'rx_avg_mbps'=>$samples ? ($rxWeighted/$samples)/1000000 : 0,
                'tx_avg_mbps'=>$samples ? ($txWeighted/$samples)/1000000 : 0,
                'rx_peak_mbps'=>$rxPeak/1000000,
                'tx_peak_mbps'=>$txPeak/1000000,
                'rx_bytes'=>$rxBytes,'tx_bytes'=>$txBytes,'total_bytes'=>$rxBytes+$txBytes
            ]
        ];
    }
}
'''

API = r'''<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/WanTrafficHistory.php';

if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $loginId = trim((string)($_GET['login_id'] ?? ''));
    $hours = (int)($_GET['hours'] ?? 24);
    if ($loginId === '' || !preg_match('/^\d+$/', $loginId)) {
        throw new InvalidArgumentException('login_id invalido');
    }
    echo json_encode(['success'=>true,'history'=>WanTrafficHistory::query($loginId,$hours)],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'historico WAN indisponivel']);
}
'''

CSS = r'''
/* JRC_WAN_HISTORY_V1 */
#wan-content .jrc-wan-history{margin-top:14px;padding:16px;border:1px solid #2c323a;border-radius:12px;background:#15181e}
#wan-content .jrc-wan-history-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-bottom:12px;border-bottom:1px solid #2b3038}
#wan-content .jrc-wan-history-head h5{margin:0;color:#f4f7fa;font-size:14px}
#wan-content .jrc-wan-history-head small{display:block;margin-top:3px;color:#7f8da0;font-size:9px}
#wan-content .jrc-wan-history-periods{display:flex;gap:6px}
#wan-content .jrc-wan-history-periods button{height:32px;padding:0 10px;border:1px solid #343b46;border-radius:7px;background:#1a1e25;color:#aeb9c8;font-size:9px;font-weight:700}
#wan-content .jrc-wan-history-periods button.active{color:#fff;border-color:#596575;background:#262c35}
#wan-content .jrc-wan-history-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin:12px 0}
#wan-content .jrc-wan-history-stat{padding:10px 11px;border:1px solid #2c323a;border-radius:9px;background:#181c22}
#wan-content .jrc-wan-history-stat span{display:block;color:#7f8da0;font-size:8px}
#wan-content .jrc-wan-history-stat strong{display:block;margin-top:5px;color:#f2f6fa;font-size:12px}
#wan-content .jrc-wan-history-chart{min-height:260px;width:100%}
#wan-content .jrc-wan-history-empty{min-height:220px;display:grid;place-items:center;color:#7f8da0;font-size:10px}
#wan-content .jrc-wan-history-legend{display:flex;justify-content:center;gap:14px;padding-top:7px;color:#8d9bae;font-size:9px}
#wan-content .jrc-wan-history-legend i{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:4px}
#wan-content .jrc-wan-history-legend .rx{background:#38bdf8}.jrc-wan-history-legend .tx{background:#34d399}
@media(max-width:1200px){#wan-content .jrc-wan-history-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:760px){#wan-content .jrc-wan-history-head{align-items:flex-start;flex-direction:column}.jrc-wan-history-periods{flex-wrap:wrap}#wan-content .jrc-wan-history-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
'''

JS = r'''
    /* JRC_WAN_HISTORY_V1 */
    function jrcWanHistFmtBytes(v){
        const n=Math.max(0,Number(v)||0), u=['B','KB','MB','GB','TB'];
        let x=n,i=0; while(x>=1024&&i<u.length-1){x/=1024;i++;}
        return (x>=100?x.toFixed(0):x>=10?x.toFixed(1):x.toFixed(2))+' '+u[i];
    }
    function jrcWanHistFmtMbps(v){const n=Math.max(0,Number(v)||0);return (n>=100?n.toFixed(0):n>=10?n.toFixed(1):n.toFixed(2))+' Mbps';}
    function jrcWanHistorySvg(points){
        if(!Array.isArray(points)||!points.length) return '<div class="jrc-wan-history-empty">Histórico começará a aparecer após a primeira consolidação de 5 minutos.</div>';
        const W=1000,H=270,L=60,R=980,T=18,B=220,pw=R-L,ph=B-T;
        const max=Math.max(.01,...points.flatMap(p=>[Number(p.rx_mbps)||0,Number(p.tx_mbps)||0,Number(p.rx_peak_mbps)||0,Number(p.tx_peak_mbps)||0]));
        const x=i=>points.length===1?L+pw/2:L+(i/(points.length-1))*pw;
        const y=v=>B-(Math.max(0,Number(v)||0)/max)*ph;
        const path=f=>points.map((p,i)=>(i?'L ':'M ')+x(i).toFixed(1)+' '+y(p[f]).toFixed(1)).join(' ');
        const first=new Date(points[0].t).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
        const last=new Date(points[points.length-1].t).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
        return '<svg viewBox="0 0 '+W+' '+H+'" preserveAspectRatio="none" style="width:100%;height:270px;display:block">'+
        '<line x1="'+L+'" y1="'+T+'" x2="'+R+'" y2="'+T+'" stroke="#334155" stroke-dasharray="4 5"/>'+
        '<line x1="'+L+'" y1="'+((T+B)/2)+'" x2="'+R+'" y2="'+((T+B)/2)+'" stroke="#334155" stroke-dasharray="4 5"/>'+
        '<line x1="'+L+'" y1="'+B+'" x2="'+R+'" y2="'+B+'" stroke="#475569"/>'+
        '<text x="'+(L-8)+'" y="'+(T+4)+'" text-anchor="end" fill="#8190a5" font-size="12">'+max.toFixed(1)+' Mbps</text>'+
        '<text x="'+(L-8)+'" y="'+(((T+B)/2)+4)+'" text-anchor="end" fill="#8190a5" font-size="12">'+(max/2).toFixed(1)+' Mbps</text>'+
        '<text x="'+(L-8)+'" y="'+(B+4)+'" text-anchor="end" fill="#8190a5" font-size="12">0</text>'+
        '<path d="'+path('rx_mbps')+'" fill="none" stroke="#38bdf8" stroke-width="3" vector-effect="non-scaling-stroke"/>'+
        '<path d="'+path('tx_mbps')+'" fill="none" stroke="#34d399" stroke-width="3" vector-effect="non-scaling-stroke"/>'+
        '<text x="'+L+'" y="248" fill="#8190a5" font-size="11">'+first+'</text>'+
        '<text x="'+R+'" y="248" text-anchor="end" fill="#8190a5" font-size="11">'+last+'</text></svg>'+
        '<div class="jrc-wan-history-legend"><span><i class="rx"></i>Download médio</span><span><i class="tx"></i>Upload médio</span></div>';
    }
    async function jrcLoadWanHistory(hours=24){
        const id=String(window.JRC_WAN_LOGIN_ID||'');
        const host=document.querySelector('#wan-content .jrc-wan-history');
        if(!host||!/^[0-9]+$/.test(id)) return;
        host.dataset.hours=String(hours);
        host.querySelectorAll('[data-wan-hours]').forEach(b=>b.classList.toggle('active',Number(b.dataset.wanHours)===Number(hours)));
        try{
            const r=await fetch('/api/get-wan-traffic-history.php?login_id='+encodeURIComponent(id)+'&hours='+encodeURIComponent(hours),{cache:'no-store'});
            const j=await r.json(); if(!j?.success) throw new Error('history');
            const h=j.history||{}, s=h.summary||{};
            const vals={
                'rxavg':jrcWanHistFmtMbps(s.rx_avg_mbps),'rxpeak':jrcWanHistFmtMbps(s.rx_peak_mbps),
                'txavg':jrcWanHistFmtMbps(s.tx_avg_mbps),'txpeak':jrcWanHistFmtMbps(s.tx_peak_mbps),
                'rxbytes':jrcWanHistFmtBytes(s.rx_bytes),'txbytes':jrcWanHistFmtBytes(s.tx_bytes)
            };
            Object.entries(vals).forEach(([k,v])=>{const e=host.querySelector('[data-wan-stat="'+k+'"]');if(e)e.textContent=v;});
            const chart=host.querySelector('.jrc-wan-history-chart'); if(chart) chart.innerHTML=jrcWanHistorySvg(h.points||[]);
        }catch(_){
            const chart=host.querySelector('.jrc-wan-history-chart');if(chart)chart.innerHTML='<div class="jrc-wan-history-empty">Histórico ainda indisponível.</div>';
        }
    }
    function jrcEnsureWanHistory(){
        const page=document.querySelector('#wan-content .acs-wan-page');
        if(!page||page.querySelector('.jrc-wan-history')) return;
        const id=String(traffic.ixcSseLoginId||''); if(!/^[0-9]+$/.test(id)) return;
        const node=document.createElement('section');
        node.className='jrc-wan-history';
        node.innerHTML='<div class="jrc-wan-history-head"><div><h5>Histórico de tráfego</h5><small>Consolidação a cada 5 minutos · retenção de 30 dias</small></div>'+
          '<div class="jrc-wan-history-periods"><button data-wan-hours="1">1 h</button><button class="active" data-wan-hours="24">24 h</button><button data-wan-hours="168">7 dias</button><button data-wan-hours="720">30 dias</button></div></div>'+
          '<div class="jrc-wan-history-stats">'+
          '<div class="jrc-wan-history-stat"><span>Média download</span><strong data-wan-stat="rxavg">--</strong></div>'+
          '<div class="jrc-wan-history-stat"><span>Pico download</span><strong data-wan-stat="rxpeak">--</strong></div>'+
          '<div class="jrc-wan-history-stat"><span>Média upload</span><strong data-wan-stat="txavg">--</strong></div>'+
          '<div class="jrc-wan-history-stat"><span>Pico upload</span><strong data-wan-stat="txpeak">--</strong></div>'+
          '<div class="jrc-wan-history-stat"><span>Recebido</span><strong data-wan-stat="rxbytes">--</strong></div>'+
          '<div class="jrc-wan-history-stat"><span>Enviado</span><strong data-wan-stat="txbytes">--</strong></div></div>'+
          '<div class="jrc-wan-history-chart"><div class="jrc-wan-history-empty">Carregando histórico...</div></div>';
        const foot=page.querySelector('.acs-wan-footnote'); if(foot) page.insertBefore(node,foot); else page.appendChild(node);
        node.querySelectorAll('[data-wan-hours]').forEach(b=>b.addEventListener('click',()=>jrcLoadWanHistory(Number(b.dataset.wanHours)||24)));
        jrcLoadWanHistory(24);
    }
    setInterval(()=>{jrcEnsureWanHistory();},1000);
    setInterval(()=>{const h=document.querySelector('#wan-content .jrc-wan-history');if(h)jrcLoadWanHistory(Number(h.dataset.hours)||24);},30000);
'''

def sha(b): return hashlib.sha256(b).hexdigest()

def run_check(cmd, data, suffix):
    exe=shutil.which(cmd[0])
    if not exe: raise RuntimeError(cmd[0]+' ausente')
    with tempfile.NamedTemporaryFile(suffix=suffix) as f:
        f.write(data); f.flush()
        r=subprocess.run([exe]+cmd[1:]+[f.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError((r.stderr or r.stdout)[:1600])

def patch_stream(before):
    text=before.decode('utf-8')
    if VERSION in text: return before
    req="require_once __DIR__ . '/../lib/IXCConfig.php';"
    if req not in text: raise RuntimeError('IXCConfig include nao encontrado no stream')
    text=text.replace(req,req+"\nrequire_once __DIR__ . '/../lib/WanTrafficHistory.php';\n/* "+VERSION+" */",1)

    # Native transparent proxy: record while preserving exact upstream bytes.
    native="CURLOPT_WRITEFUNCTION => static function($ch, string $chunk): int {"
    if native in text:
        text=text.replace(native,
            "CURLOPT_WRITEFUNCTION => static function($ch, string $chunk) use ($loginId): int {\n            WanTrafficHistory::feedNativeChunk($loginId, $chunk);",
            1)
        return text.encode()

    # Parsed proxy: record after rx/tx validation.
    needle="$txBps = max(0.0, (float)$json['tx']);"
    if needle in text:
        text=text.replace(needle,needle+"\n                WanTrafficHistory::record($loginId, $rxBps, $txBps);",1)
        return text.encode()
    raise RuntimeError('formato do stream IXC nao reconhecido')

def patch_js(before):
    text=before.decode('utf-8')
    if VERSION in text: return before

    # Localiza a linha real que abre o SSE IXC e descobre a expressao do login_id.
    lines=text.splitlines(True)
    candidates=[(i,line) for i,line in enumerate(lines) if 'stream-ixc-live-traffic.php?login_id=' in line]
    if not candidates:
        raise RuntimeError('URL stream-ixc-live-traffic.php?login_id= nao encontrada')
    if len(candidates)>4:
        raise RuntimeError('multiplas URLs SSE IXC inesperadas')

    target_i=None
    expr=None
    for i,line in candidates:
        m=re.search(r'encodeURIComponent\\(([^)]+)\\)', line)
        if m:
            target_i=i
            expr=m.group(1).strip()
            break
        m=re.search(r'login_id=\\$\\{encodeURIComponent\\(([^)]+)\\)\\}', line)
        if m:
            target_i=i
            expr=m.group(1).strip()
            break
    if target_i is None or not expr:
        raise RuntimeError('nao foi possivel identificar a variavel do login_id no SSE IXC')

    # Exponha somente o ID numerico para o card de historico; nao altera EventSource.
    indent=re.match(r'^(\\s*)', lines[target_i]).group(1)
    marker=indent+"window.JRC_WAN_LOGIN_ID = String("+expr+" || '');\\n"
    if 'window.JRC_WAN_LOGIN_ID' not in text:
        lines.insert(target_i, marker)
        text=''.join(lines)

    # Injeta o UI de historico dentro da mesma IIFE quando houver uma.
    end=text.rfind('})();')
    if end>=0:
        return (text[:end]+JS+"\\n"+text[end:]).encode()

    # Fallback para arquivo sem IIFE: anexa no final.
    return (text+JS+"\\n").encode()

def patch_css(before):
    text=before.decode('utf-8')
    if VERSION in text: return before
    return (text+("" if text.endswith("\n") else "\n")+CSS).encode()

def atomic(path,data,mode=0o644,uid=None,gid=None):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,name=tempfile.mkstemp(prefix='.jrc-wan-history-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data); f.flush(); os.fsync(f.fileno()); os.fchmod(f.fileno(),mode)
            if os.geteuid()==0 and uid is not None: os.fchown(f.fileno(),uid,gid)
        os.replace(name,path)
    finally:
        if os.path.exists(name): os.unlink(name)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--root',default='/var/www/gacs'); ap.add_argument('--backup-dir',default='/root')
    ap.add_argument('--dry-run',action='store_true')
    a=ap.parse_args()
    root=Path(a.root).resolve(); parent=Path(a.backup_dir).resolve()
    paths={r:root/r for r in [STREAM_REL,JS_REL,CSS_REL]}
    for r,p in paths.items():
        if not p.is_file() or p.is_symlink(): raise RuntimeError(r+' nao encontrado')
    with (parent/'.jrc-wan-history.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        before={r:p.read_bytes() for r,p in paths.items()}
        after={
          STREAM_REL:patch_stream(before[STREAM_REL]),
          JS_REL:patch_js(before[JS_REL]),
          CSS_REL:patch_css(before[CSS_REL])
        }
        lib=LIB.encode(); api=API.encode()
        run_check(['php','-l'],lib,'.php'); run_check(['php','-l'],api,'.php'); run_check(['php','-l'],after[STREAM_REL],'.php')
        run_check(['node','--check'],after[JS_REL],'.js')
        print('VALIDADO: historico WAN 5min + API + grafico 1h/24h/7d/30d.')
        print('Ao vivo de 1s permanece inalterado.')
        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.'); return
        for r,p in paths.items():
            if p.read_bytes()!=before[r]: raise RuntimeError('edicao concorrente: '+r)
        backup=Path(tempfile.mkdtemp(prefix='gacs-wan-history-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',dir=str(parent)))
        os.chmod(backup,0o700)
        for r,p in paths.items():
            target=backup/r; target.parent.mkdir(parents=True,exist_ok=True); target.write_bytes(before[r])
        (backup/'manifest.json').write_text(json.dumps({'version':VERSION,'root':str(root)},indent=2))
        (backup/'install.py').write_bytes(Path(__file__).read_bytes())
        for r,p in paths.items():
            st=p.stat(); atomic(p,after[r],stat.S_IMODE(st.st_mode),st.st_uid,st.st_gid)
        atomic(root/LIB_REL,lib); atomic(root/API_REL,api)
        hist=Path('/var/lib/jrc-acs/wan-history'); hist.mkdir(parents=True,exist_ok=True); os.chmod(hist,0o750)
        # nginx/php-fpm commonly uses www-data on this host.
        try:
            import pwd,grp
            u=pwd.getpwnam('www-data'); os.chown(hist,u.pw_uid,u.pw_gid)
        except Exception: pass
        ptr=parent/'gacs-wan-history-ultimo'; ptr.write_text(str(backup)+'\n'); os.chmod(ptr,0o600)
        print('INSTALADO: historico WAN habilitado.')
        print('Buckets: 5 minutos | Retencao: 31 dias | UI: 1h/24h/7d/30d.')
        print('O historico comeca a acumular a partir de agora.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr); sys.exit(1)
