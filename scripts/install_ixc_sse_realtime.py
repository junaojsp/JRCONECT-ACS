#!/usr/bin/env python3
"""Install IXC continuous SSE traffic on the existing WAN card without changing layout."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_IXC_SSE_REALTIME_V1'
JS_REL='assets/js/device-control-fix.js'
API_REL='api/stream-ixc-live-traffic.php'

ENDPOINT = r'''<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/IXCConfig.php';

if (function_exists('requireLogin')) requireLogin();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@set_time_limit(70);
ignore_user_abort(false);

function streamFlush(): void
{
    while (function_exists('ob_get_level') && ob_get_level() > 0) @ob_end_flush();
    @flush();
}

function streamEvent(string $event, array $payload): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    streamFlush();
}

function parseUpstreamEvent(string $block): ?array
{
    $type = '';
    $data = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($block)) ?: [] as $line) {
        if ($line === '' || str_starts_with($line, ':')) continue;
        [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
        $field = trim($field);
        $value = ltrim($value);
        if ($field === 'event') $type = $value;
        elseif ($field === 'data') $data[] = $value;
    }
    if (!$data) return null;
    return ['event' => $type, 'data' => implode("\n", $data)];
}

$loginId = trim((string)($_GET['login_id'] ?? ''));
if ($loginId === '' || !preg_match('/^\d+$/', $loginId)) {
    streamEvent('error', ['success' => false, 'message' => 'login_id IXC invalido.']);
    exit;
}

try {
    $cfg = getIxcConfig();
    $url = rtrim((string)$cfg['base_url'], '/') . '/aplicativo/radusuarios/rel_22021.php?trafego=' . rawurlencode($loginId);
    $token = (string)$cfg['token'];
    $started = microtime(true);
    $lastHeartbeat = 0.0;
    $buffer = '';
    $sent = 0;

    echo "retry: 1500\n";
    echo ": jrconect ixcsse start\n\n";
    streamFlush();

    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('curl_init_failed');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: text/event-stream',
            'Cache-Control: no-cache',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
            'X-Requested-With: XMLHttpRequest',
        ],
        CURLOPT_WRITEFUNCTION => static function($ch, string $chunk) use (&$buffer, &$sent, $loginId): int {
            $buffer .= $chunk;
            while (preg_match('/^(.*?)(?:\r\n\r\n|\n\n|\r\r)/s', $buffer, $m)) {
                $block = $m[1];
                $buffer = substr($buffer, strlen($m[0]));
                $event = parseUpstreamEvent($block);
                if (!$event) continue;
                $json = json_decode($event['data'], true);
                if (!is_array($json) || strtolower((string)$event['event']) !== 'trafego') continue;
                if (!isset($json['rx'], $json['tx']) || !is_numeric($json['rx']) || !is_numeric($json['tx'])) continue;

                $rxBps = max(0.0, (float)$json['rx']);
                $txBps = max(0.0, (float)$json['tx']);
                streamEvent('traffic', [
                    'success' => true,
                    'source' => 'IXC EventSource',
                    'login_id' => (int)$loginId,
                    'download_mbps' => $rxBps / 1000000,
                    'upload_mbps' => $txBps / 1000000,
                    'download_bps' => $rxBps,
                    'upload_bps' => $txBps,
                    'sample_time' => date(DATE_ATOM),
                ]);
                $sent++;
                if (connection_aborted()) return 0;
            }
            if (strlen($buffer) > 65536) $buffer = substr($buffer, -65536);
            return strlen($chunk);
        },
        CURLOPT_NOPROGRESS => false,
        CURLOPT_XFERINFOFUNCTION => static function($ch, float $dltotal, float $dlnow, float $ultotal, float $ulnow) use ($started, &$lastHeartbeat): int {
            $now = microtime(true);
            if ($now - $lastHeartbeat >= 10) {
                echo ': heartbeat ' . time() . "\n\n";
                streamFlush();
                $lastHeartbeat = $now;
            }
            if (connection_aborted() || ($now - $started) >= 55) return 1;
            return 0;
        },
    ]);

    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!connection_aborted()) {
        streamEvent('end', [
            'success' => true,
            'source' => 'IXC EventSource',
            'http_code' => $http,
            'samples' => $sent,
            'reconnect' => true,
            'message' => $sent > 0 ? 'Janela SSE concluida; reconectando.' : ($error !== '' ? $error : 'Stream sem amostras; reconectando.'),
        ]);
    }
} catch (Throwable $e) {
    if (!connection_aborted()) {
        streamEvent('error', ['success' => false, 'message' => 'Falha ao abrir stream IXC.', 'reconnect' => true]);
    }
}
'''

SSE_HELPERS = r'''    /* JRC_IXC_SSE_REALTIME_V1 */
    window.JRC_IXC_SSE_REALTIME = '20261023-1';

    function jrcStopIxcSse() {
        if (traffic.ixcSseRetryTimer) {
            clearTimeout(traffic.ixcSseRetryTimer);
            traffic.ixcSseRetryTimer = null;
        }
        if (traffic.ixcSse) {
            try { traffic.ixcSse.close(); } catch (_) {}
            traffic.ixcSse = null;
        }
        traffic.ixcSseConnected = false;
    }

    function jrcPaintIxcSseSample(sample) {
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

        setLiveRate('live-rx', down);
        setLiveRate('live-tx', up);
        setMonitorText('jr-monitor-source', 'IXC EventSource + IXC/RADIUS');
        setMonitorText('jr-monitor-live-source', 'IXC EventSource');
        setMonitorText('jr-source-live-badge', 'IXC AO VIVO');
        setMonitorText('jr-source-live-state', 'Stream IXC conectado');
        setMonitorText('bandwidth-sample-status', 'IXC AO VIVO • ' + new Date(at).toLocaleTimeString('pt-BR'));

        jrcWanNodes('bandwidth-bars').forEach(node => {
            node.innerHTML = chartHtml(traffic.samples);
        });
        return true;
    }

    function jrcScheduleIxcSse(loginId, delay = 2500) {
        if (!loginId || !jrcWanActive()) return;
        if (traffic.ixcSseRetryTimer) clearTimeout(traffic.ixcSseRetryTimer);
        traffic.ixcSseRetryTimer = setTimeout(() => {
            traffic.ixcSseRetryTimer = null;
            jrcStartIxcSse(loginId);
        }, delay);
    }

    function jrcStartIxcSse(loginId) {
        const id = Number(loginId);
        if (!Number.isInteger(id) || id < 1 || !jrcWanActive() || typeof EventSource === 'undefined') return false;

        if (traffic.ixcSse && traffic.ixcSseLoginId === id &&
            traffic.ixcSse.readyState !== EventSource.CLOSED) {
            return true;
        }

        jrcStopIxcSse();
        traffic.ixcSseLoginId = id;
        setMonitorText('bandwidth-sample-status', 'Conectando ao stream ao vivo do IXC...');

        const source = new EventSource('/api/stream-ixc-live-traffic.php?login_id=' + encodeURIComponent(id));
        traffic.ixcSse = source;

        source.onopen = () => {
            if (traffic.ixcSse !== source) return;
            traffic.ixcSseConnected = true;
            setMonitorText('bandwidth-sample-status', 'IXC AO VIVO • stream conectado');
        };

        source.addEventListener('traffic', event => {
            if (traffic.ixcSse !== source) return;
            let data;
            try { data = JSON.parse(event.data); } catch (_) { return; }
            jrcPaintIxcSseSample(data);
        });

        source.addEventListener('end', () => {
            if (traffic.ixcSse !== source) return;
            source.close();
            traffic.ixcSse = null;
            traffic.ixcSseConnected = false;
            if (traffic.ixcSseLast) {
                setMonitorText('bandwidth-sample-status', 'IXC AO VIVO • reconectando stream...');
            }
            jrcScheduleIxcSse(id, 700);
        });

        source.onerror = () => {
            if (traffic.ixcSse !== source) return;
            source.close();
            traffic.ixcSse = null;
            traffic.ixcSseConnected = false;
            if (traffic.ixcSseLast) jrcPaintIxcSseSample(traffic.ixcSseLast);
            setMonitorText('jr-source-live-state', 'Stream IXC indisponivel; fallback ativo');
            setMonitorText('bandwidth-sample-status', 'Stream IXC indisponivel; usando fallback NE8000/TR-069.');
            jrcScheduleIxcSse(id, 5000);
        };
        return true;
    }

'''

def sha(data): return hashlib.sha256(data).hexdigest()

def once(text, old, new, label):
    count=text.count(old)
    if count!=1: raise RuntimeError(f'Trecho {label} esperado 1x, encontrado {count}x.')
    return text.replace(old,new,1)

def patch_js(before):
    text=before.decode('utf-8')
    nl='\r\n' if '\r\n' in text else '\n'
    text=text.replace('\r\n','\n')
    if VERSION in text: return None
    if 'JRC_WAN_OVERVIEW_FIX_V1' not in text:
        raise RuntimeError('A correcao WAN overview v1 nao foi localizada.')

    anchor='    async function jrcWanJson(url) {\n'
    text=once(text, anchor, SSE_HELPERS+anchor, 'helpers SSE')

    radius_line='            traffic.ixcLoginId=data.ixc_login_id || traffic.ixcLoginId || null;\n'
    text=once(text, radius_line,
        radius_line+"            if (traffic.ixcLoginId) jrcStartIxcSse(traffic.ixcLoginId);\n",
        'inicio SSE apos RADIUS')

    live_guard='        if(!jrcWanActive() || traffic.livePolling || !window.DEVICE_ID) return;\n'
    text=once(text, live_guard,
        live_guard+"        if (traffic.ixcSse && traffic.ixcSse.readyState !== EventSource.CLOSED) return;\n",
        'fallback guard')

    dom='    document.addEventListener(\'DOMContentLoaded\', () => {\n'
    lifecycle="""    window.addEventListener('pagehide', () => jrcStopIxcSse());
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            jrcStopIxcSse();
        } else if (traffic.ixcLoginId) {
            jrcStartIxcSse(traffic.ixcLoginId);
        }
    });

"""
    text=once(text,dom,lifecycle+dom,'lifecycle SSE')

    return text.replace('\n',nl).encode('utf-8')

def atomic(path,data,metadata=None):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,name=tempfile.mkstemp(prefix='.jrc-ixc-sse-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data); out.flush(); os.fsync(out.fileno())
            if metadata:
                os.fchmod(out.fileno(),stat.S_IMODE(metadata.st_mode))
                if os.geteuid()==0: os.fchown(out.fileno(),metadata.st_uid,metadata.st_gid)
            else:
                os.fchmod(out.fileno(),0o644)
        os.replace(name,path)
    finally:
        if os.path.exists(name): os.unlink(name)

def check_js(data):
    node=shutil.which('node')
    if not node: raise RuntimeError('Node.js ausente para validar JavaScript.')
    with tempfile.NamedTemporaryFile(suffix='.js') as tmp:
        tmp.write(data); tmp.flush()
        r=subprocess.run([node,'--check',tmp.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError('Sintaxe JS invalida: '+r.stderr[:1200])

def check_php(data):
    php=shutil.which('php')
    if not php: raise RuntimeError('PHP CLI ausente para validar endpoint SSE.')
    with tempfile.NamedTemporaryFile(suffix='.php') as tmp:
        tmp.write(data); tmp.flush()
        r=subprocess.run([php,'-l',tmp.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError('Sintaxe PHP invalida: '+(r.stderr or r.stdout)[:1200])

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--root',default='/var/www/gacs')
    p.add_argument('--backup-dir',default='/root')
    m=p.add_mutually_exclusive_group()
    m.add_argument('--dry-run',action='store_true')
    m.add_argument('--rollback',type=Path)
    a=p.parse_args()
    parent=a.rollback.resolve().parent if a.rollback else Path(a.backup_dir).resolve()
    with (parent/'.jrc-ixc-sse-realtime.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        if a.rollback:
            b=a.rollback.resolve(); meta=json.loads((b/'manifest.json').read_text())
            if meta.get('version')!=VERSION: raise RuntimeError('Backup incompativel.')
            root=Path(meta['root']); js=root/JS_REL; api=root/API_REL
            if sha(js.read_bytes())!=meta['js_after']: raise RuntimeError('JS mudou depois da instalacao; rollback cancelado.')
            atomic(js,(b/'device-control-fix.js').read_bytes(),js.stat())
            if meta.get('api_existed'):
                atomic(api,(b/'stream-ixc-live-traffic.php').read_bytes(),api.stat() if api.exists() else None)
            elif api.exists():
                api.unlink()
            print('RESTAURADO: stream IXC SSE removido e JS anterior restaurado.'); return

        root=Path(a.root).resolve(); js=root/JS_REL; api=root/API_REL
        if not js.is_file() or js.is_symlink(): raise RuntimeError('device-control-fix.js nao encontrado.')
        before=js.read_bytes(); after=patch_js(before)
        if after is None: print('JA INSTALADO: IXC SSE realtime.'); return
        endpoint=ENDPOINT.encode('utf-8')
        check_js(before); check_js(after); check_php(endpoint)
        print('VALIDADO: SSE IXC continuo + fallback atual; layout preservado.')
        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.'); return
        if js.read_bytes()!=before: raise RuntimeError('Edicao concorrente detectada.')
        backup=Path(tempfile.mkdtemp(prefix='gacs-ixc-sse-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',dir=str(parent)))
        os.chmod(backup,0o700)
        shutil.copy2(js,backup/'device-control-fix.js')
        api_existed=api.exists()
        if api_existed: shutil.copy2(api,backup/'stream-ixc-live-traffic.php')
        shutil.copy2(Path(__file__).resolve(),backup/'install.py')
        meta={'version':VERSION,'root':str(root),'api_existed':api_existed,'js_before':sha(before),'js_after':sha(after)}
        (backup/'manifest.json').write_text(json.dumps(meta,indent=2))
        (backup/'changes.diff').write_text(''.join(difflib.unified_diff(before.decode().splitlines(True),after.decode().splitlines(True),fromfile=JS_REL,tofile=JS_REL)))
        atomic(api,endpoint,api.stat() if api.exists() else None)
        atomic(js,after,js.stat())
        ptr=parent/'gacs-ixc-sse-ultimo'; ptr.write_text(str(backup)+'\n'); os.chmod(ptr,0o600)
        print('INSTALADO: trafego WAN via IXC EventSource em tempo real.')
        print('Fallback preservado: NE8000 e TR-069.')
        print('Layout preservado. Backup: '+str(backup))

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr); sys.exit(1)
