#!/usr/bin/env python3
"""Keep browser SSE open while the ACS transparently reconnects to IXC upstream."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path

VERSION='JRC_IXC_SSE_PERSISTENT_V1'
JS_REL='assets/js/device-control-fix.js'
API_REL='api/stream-ixc-live-traffic.php'

ENDPOINT=r'''<?php
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
@ini_set('implicit_flush', '1');
@set_time_limit(0);
ignore_user_abort(false);

function jrcSseFlush(): void
{
    while (function_exists('ob_get_level') && ob_get_level() > 0) @ob_end_flush();
    @flush();
}

function jrcSseEvent(string $event, array $payload): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    jrcSseFlush();
}

function jrcSseParse(string $block): ?array
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
    jrcSseEvent('error', ['success'=>false, 'message'=>'login_id IXC invalido.']);
    exit;
}

try {
    $cfg = getIxcConfig();
    $url = rtrim((string)$cfg['base_url'], '/') .
        '/aplicativo/radusuarios/rel_22021.php?trafego=' . rawurlencode($loginId);
    $token = (string)$cfg['token'];

    echo "retry: 1500\n";
    echo ": jrconect persistent sse\n\n";
    jrcSseFlush();

    $attempt = 0;
    while (!connection_aborted()) {
        $attempt++;
        $buffer = '';
        $receivedThisConnection = 0;
        $lastHeartbeat = microtime(true);

        $ch = curl_init($url);
        if ($ch === false) {
            echo ': upstream init failed ' . time() . "\n\n";
            jrcSseFlush();
            sleep(2);
            continue;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTPHEADER => [
                'Accept: text/event-stream',
                'Cache-Control: no-cache',
                'ixcsoft: listar',
                'Authorization: Basic ' . base64_encode($token),
                'X-Requested-With: XMLHttpRequest',
            ],
            CURLOPT_WRITEFUNCTION => static function($ch, string $chunk) use (&$buffer, &$receivedThisConnection, $loginId): int {
                $buffer .= $chunk;

                while (preg_match('/^(.*?)(?:\r\n\r\n|\n\n|\r\r)/s', $buffer, $m)) {
                    $block = $m[1];
                    $buffer = substr($buffer, strlen($m[0]));
                    $event = jrcSseParse($block);
                    if (!$event) continue;

                    $json = json_decode($event['data'], true);
                    if (!is_array($json) || strtolower((string)$event['event']) !== 'trafego') continue;
                    if (!isset($json['rx'], $json['tx']) || !is_numeric($json['rx']) || !is_numeric($json['tx'])) continue;

                    $rxBps = max(0.0, (float)$json['rx']);
                    $txBps = max(0.0, (float)$json['tx']);

                    jrcSseEvent('traffic', [
                        'success' => true,
                        'source' => 'IXC EventSource',
                        'login_id' => (int)$loginId,
                        'download_mbps' => $rxBps / 1000000,
                        'upload_mbps' => $txBps / 1000000,
                        'download_bps' => $rxBps,
                        'upload_bps' => $txBps,
                        'sample_time' => date(DATE_ATOM),
                    ]);
                    $receivedThisConnection++;

                    if (connection_aborted()) return 0;
                }

                if (strlen($buffer) > 65536) $buffer = substr($buffer, -65536);
                return strlen($chunk);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function($ch, float $dltotal, float $dlnow, float $ultotal, float $ulnow) use (&$lastHeartbeat): int {
                $now = microtime(true);
                if ($now - $lastHeartbeat >= 10) {
                    echo ': heartbeat ' . time() . "\n\n";
                    jrcSseFlush();
                    $lastHeartbeat = $now;
                }
                return connection_aborted() ? 1 : 0;
            },
        ]);

        if (defined('CURLOPT_TCP_KEEPALIVE')) curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        if (defined('CURLOPT_TCP_KEEPIDLE')) curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 30);
        if (defined('CURLOPT_TCP_KEEPINTVL')) curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 15);

        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (connection_aborted()) break;

        // Keep the browser-side EventSource alive. Only the upstream IXC
        // connection is recreated here.
        echo ': upstream reconnect attempt=' . $attempt .
            ' http=' . $http .
            ' samples=' . $receivedThisConnection .
            ($error !== '' ? ' error=' . preg_replace('/[\r\n]+/', ' ', $error) : '') .
            "\n\n";
        jrcSseFlush();

        // Fast reconnect after a normal upstream close; a slightly longer
        // pause when no samples were received avoids a tight retry loop.
        usleep($receivedThisConnection > 0 ? 300000 : 1500000);
    }
} catch (Throwable $e) {
    if (!connection_aborted()) {
        jrcSseEvent('error', ['success'=>false, 'message'=>'Falha no proxy persistente do IXC.']);
    }
}
'''

def sha(data): return hashlib.sha256(data).hexdigest()

def patch_js(before):
    text=before.decode('utf-8')
    nl='\r\n' if '\r\n' in text else '\n'
    text=text.replace('\r\n','\n')
    if VERSION in text: return None
    if 'JRC_IXC_SSE_REALTIME_V1' not in text:
        raise RuntimeError('SSE IXC realtime v1 nao localizado.')

    # Native EventSource reconnect must remain in charge. Do not close the
    # browser stream on transient transport errors.
    old="""        source.addEventListener('end', () => {
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
        };"""
    new="""        source.addEventListener('end', () => {
            // Endpoint persistente nao encerra a janela por tempo. Mantemos
            // este listener apenas por compatibilidade com uma versao antiga.
            if (traffic.ixcSse !== source) return;
            if (traffic.ixcSseLast) {
                setMonitorText('bandwidth-sample-status', 'IXC AO VIVO • mantendo ultima amostra');
            }
        });

        source.onerror = () => {
            if (traffic.ixcSse !== source) return;
            traffic.ixcSseConnected = false;
            // EventSource possui reconexao nativa. Nao fechar, nao apagar
            // amostras e nao reiniciar manualmente a conexao.
            if (traffic.ixcSseLast) jrcPaintIxcSseSample(traffic.ixcSseLast);
            setMonitorText('jr-source-live-state', 'IXC AO VIVO • reconexao automatica');
            setMonitorText('bandwidth-sample-status', 'IXC AO VIVO • ultima amostra mantida');
        };"""
    count=text.count(old)
    if count!=1:
        raise RuntimeError(f'Bloco EventSource esperado 1x, encontrado {count}x.')
    text=text.replace(old,new,1)

    marker="    /* "+VERSION+" */\n"
    anchor="    function jrcStopIxcSse() {\n"
    if text.count(anchor)!=1: raise RuntimeError('Ancora SSE nao localizada.')
    text=text.replace(anchor,marker+anchor,1)

    return text.replace('\n',nl).encode('utf-8')

def check_js(data):
    node=shutil.which('node')
    if not node: raise RuntimeError('Node.js ausente.')
    with tempfile.NamedTemporaryFile(suffix='.js') as f:
        f.write(data); f.flush()
        r=subprocess.run([node,'--check',f.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError('Sintaxe JS invalida: '+r.stderr[:1000])

def check_php(data):
    php=shutil.which('php')
    if not php: raise RuntimeError('PHP CLI ausente.')
    with tempfile.NamedTemporaryFile(suffix='.php') as f:
        f.write(data); f.flush()
        r=subprocess.run([php,'-l',f.name],capture_output=True,text=True)
    if r.returncode: raise RuntimeError('Sintaxe PHP invalida: '+(r.stderr or r.stdout)[:1000])

def atomic(path,data,meta=None):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,name=tempfile.mkstemp(prefix='.jrc-ixc-persistent-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data); out.flush(); os.fsync(out.fileno())
            if meta:
                os.fchmod(out.fileno(),stat.S_IMODE(meta.st_mode))
                if os.geteuid()==0: os.fchown(out.fileno(),meta.st_uid,meta.st_gid)
            else:
                os.fchmod(out.fileno(),0o644)
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

    with (parent/'.jrc-ixc-sse-persistent.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)

        if a.rollback:
            b=a.rollback.resolve(); meta=json.loads((b/'manifest.json').read_text())
            if meta.get('version')!=VERSION: raise RuntimeError('Backup incompativel.')
            root=Path(meta['root']); js=root/JS_REL; api=root/API_REL
            if sha(js.read_bytes())!=meta['js_after'] or sha(api.read_bytes())!=meta['api_after']:
                raise RuntimeError('Arquivos mudaram depois da instalacao; rollback cancelado.')
            check_js((b/'device-control-fix.js').read_bytes())
            check_php((b/'stream-ixc-live-traffic.php').read_bytes())
            atomic(js,(b/'device-control-fix.js').read_bytes(),js.stat())
            atomic(api,(b/'stream-ixc-live-traffic.php').read_bytes(),api.stat())
            print('RESTAURADO: transporte SSE anterior.'); return

        root=Path(a.root).resolve(); js=root/JS_REL; api=root/API_REL
        if not js.is_file() or js.is_symlink(): raise RuntimeError('device-control-fix.js nao encontrado.')
        if not api.is_file() or api.is_symlink(): raise RuntimeError('endpoint SSE nao encontrado.')

        js_before=js.read_bytes(); api_before=api.read_bytes()
        js_after=patch_js(js_before)
        if js_after is None: print('JA INSTALADO: SSE persistente.'); return
        api_after=ENDPOINT.encode('utf-8')

        check_js(js_before); check_js(js_after); check_php(api_before); check_php(api_after)
        print('VALIDADO: conexao SSE do navegador sera persistente; reconexao IXC ocorrera dentro do ACS.')
        if a.dry_run:
            print('SIMULACAO OK: nenhum arquivo alterado.'); return

        if js.read_bytes()!=js_before or api.read_bytes()!=api_before:
            raise RuntimeError('Edicao concorrente detectada.')

        backup=Path(tempfile.mkdtemp(prefix='gacs-ixc-persistent-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-',dir=str(parent)))
        os.chmod(backup,0o700)
        shutil.copy2(js,backup/'device-control-fix.js')
        shutil.copy2(api,backup/'stream-ixc-live-traffic.php')
        shutil.copy2(Path(__file__).resolve(),backup/'install.py')

        meta={
            'version':VERSION,'root':str(root),
            'js_before':sha(js_before),'js_after':sha(js_after),
            'api_before':sha(api_before),'api_after':sha(api_after)
        }
        (backup/'manifest.json').write_text(json.dumps(meta,indent=2))
        (backup/'js.diff').write_text(''.join(difflib.unified_diff(js_before.decode().splitlines(True),js_after.decode().splitlines(True),fromfile=JS_REL,tofile=JS_REL)))
        (backup/'api.diff').write_text(''.join(difflib.unified_diff(api_before.decode().splitlines(True),api_after.decode().splitlines(True),fromfile=API_REL,tofile=API_REL)))

        atomic(api,api_after,api.stat())
        atomic(js,js_after,js.stat())

        ptr=parent/'gacs-ixc-persistent-ultimo'
        ptr.write_text(str(backup)+'\n'); os.chmod(ptr,0o600)

        print('INSTALADO: browser mantem SSE aberto; ACS reconecta ao IXC internamente.')
        print('Grafico, layout, media de 3s e fallbacks preservados.')
        print('Backup: '+str(backup))

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('CANCELADO: '+str(e),file=sys.stderr); sys.exit(1)
