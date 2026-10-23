#!/usr/bin/env python3
"""Targeted WAN overview fix. Preserves local code, optical history and APIs."""
import argparse, datetime, difflib, fcntl, hashlib, json, os, re, shutil, stat, subprocess, sys, tempfile
from pathlib import Path
VERSION = 'JRC_WAN_OVERVIEW_FIX_V1'
REL = 'assets/js/device-control-fix.js'

HELPERS = '    /* JRC_WAN_OVERVIEW_FIX_V1 */\n    window.JRC_WAN_OVERVIEW_FIX = \'20261023-1\';\n    function jrcWanNodes(id) {\n        if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(id)) return [];\n        return Array.from(document.querySelectorAll(\'[id="\' + id + \'"]\'));\n    }\n    function jrcWanVisible(node) {\n        return !!node && node.getClientRects().length > 0 &&\n            getComputedStyle(node).visibility !== \'hidden\';\n    }\n    function jrcWanElement(id) {\n        const nodes = jrcWanNodes(id);\n        return nodes.find(jrcWanVisible) || nodes[0] || null;\n    }\n    function jrcWanActive() {\n        return !document.hidden && (monitoringActive() ||\n            jrcWanNodes(\'bandwidth-bars\').some(jrcWanVisible));\n    }\n    function jrcWanNumber(value) {\n        if (typeof value !== \'number\' && typeof value !== \'string\') return null;\n        if (typeof value === \'string\' && value.trim() === \'\') return null;\n        const number = Number(value);\n        return Number.isFinite(number) && number >= 0 ? number : null;\n    }\n    async function jrcWanJson(url) {\n        const allowed = [\'/api/get-radius-session.php\', \'/api/get-ixc-live-traffic.php\',\n            \'/api/get-ne-live-traffic.php\', \'/api/get-tr069-live-traffic.php\'];\n        const parsed = new URL(url, window.location.href);\n        if (parsed.origin !== window.location.origin || !allowed.includes(parsed.pathname)) {\n            return {success:false, available:false, message:\'Endpoint WAN inesperado.\'};\n        }\n        const controller = new AbortController();\n        const timer = setTimeout(() => controller.abort(), 15000);\n        try {\n            const response = await fetch(parsed.href, {credentials:\'same-origin\',\n                cache:\'no-store\', signal:controller.signal, headers:{Accept:\'application/json\'}});\n            if (!response.ok) return {success:false, available:false, message:\'HTTP \' + response.status};\n            const data = await response.json();\n            if (!data || typeof data !== \'object\' || Array.isArray(data)) {\n                return {success:false, available:false, message:\'Resposta WAN invalida.\'};\n            }\n            return data;\n        } catch (error) {\n            return {success:false, available:false, message:error.name === \'AbortError\'\n                ? \'Tempo limite de 15s.\' : \'Falha de rede ou resposta nao JSON.\'};\n        } finally { clearTimeout(timer); }\n    }\n\n'
SET_TEXT = "    function setMonitorText(id,value) {\n        jrcWanNodes(id).forEach(node => { node.textContent = value ?? 'N/D'; });\n    }"
SET_RATE = "    function setLiveRate(prefix, valueMbps) {\n        const value = jrcWanNumber(valueMbps);\n        // The overview has a static Mbps label. Never display Kbps as Mbps.\n        setMonitorText(prefix + '-mbps', value === null ? '--' :\n            value.toFixed(value > 0 && value < 0.01 ? 4 : 2));\n        setMonitorText(prefix + '-unit', 'Mbps');\n    }"
FETCH_LIVE = "            const reasons = [];\n            const fetchLive = async url => {\n                const payload = await jrcWanJson(url);\n                const label = new URL(url, window.location.href).pathname\n                    .replace('/api/get-', '').replace('.php', '');\n                if (!payload.success || !payload.available || !payload.live ||\n                    jrcWanNumber(payload.live.download_mbps) === null ||\n                    jrcWanNumber(payload.live.upload_mbps) === null) {\n                    reasons.push(label + ': ' + (payload.message || 'sem amostra valida'));\n                    return {...payload, available:false};\n                }\n                return payload;\n            };"


def sha(data):
    return hashlib.sha256(data).hexdigest()

def block(text, name, assignment=False):
    lead = r'    window\.' + re.escape(name) + r'\s*=\s*(?:async\s+)?function[^\n]*\{' if assignment else r'    (?:async\s+)?function\s+' + re.escape(name) + r'\([^\n]*\)\s*\{'
    hits = list(re.finditer(r'(?m)^' + lead + r'[^\S\n]*$', text))
    if len(hits) != 1:
        raise RuntimeError('Estrutura diferente: ' + name + '. Nada foi substituido por inteiro.')
    start = hits[0].start()
    end = re.search(r'(?m)^    };?[ \t]*\r?$', text[hits[0].end():])
    if not end: raise RuntimeError('Fechamento nao reconhecido: ' + name)
    stop = hits[0].end() + end.end()
    return start, stop, text[start:stop]

def once(text, old, new):
    if text.count(old) != 1:
        raise RuntimeError('Versao local diferente no trecho: ' + old[:85])
    return text.replace(old, new, 1)

def change(text, name, callback, assignment=False):
    start, end, old = block(text, name, assignment)
    return text[:start] + callback(old) + text[end:]

def patch(original):
    text = original.decode('utf-8')
    newline = '\r\n' if '\r\n' in text else '\n'
    text = text.replace('\r\n', '\n')
    if VERSION in text: return None
    if not text.lstrip().startswith('/*') or "'use strict';" not in text:
        raise RuntimeError('Arquivo de controles nao reconhecido.')
    for token in ['traffic.livePolling', 'traffic.lastPoll', 'chartHtml', 'monitoring-tab']:
        if token not in text: raise RuntimeError('Estrutura local incompleta: ' + token)
    start, _, old = block(text, 'monitoringActive')
    if "getElementById('monitoring')" not in old:
        raise RuntimeError('Regra de ativacao diferente. Cancelado.')
    text = text[:start] + HELPERS + text[start:]
    text = change(text, 'setMonitorText', lambda old: SET_TEXT)
    text = change(text, 'setLiveRate', lambda old: SET_RATE)

    def live(old):
        old = once(old, '!monitoringActive()', '!jrcWanActive()')
        old = once(old, 'now-traffic.liveLastPoll<1000',
                   'now-traffic.liveLastPoll<(monitoringActive()?1000:5000)')
        pat = r'(?ms)^            const fetchLive\s*=\s*async url=>\{.*?^            };'
        if len(re.findall(pat, old)) != 1: raise RuntimeError('fetchLive diferente da versao revisada.')
        old = re.sub(pat, lambda m: FETCH_LIVE, old, count=1)
        old = once(old, '        try{', "        try{\n            setMonitorText('bandwidth-sample-status','Consultando trafego WAN...');")
        old = once(old, "                        : 'Aguardando amostra confiavel do IXC/BRAS.';".replace('confiavel', 'confi\u00e1vel'),
                   "                        : (reasons.join(' | ') || 'Nenhuma amostra WAN valida.');")
        return old
    text = change(text, 'updateTr069LiveTraffic', live, True)

    def radius(old):
        old = once(old, '!monitoringActive()', '!jrcWanActive()')
        pat = r"(?m)^            const r=await fetch\('/api/get-radius-session\.php\?device_id='[^\n]*\);\n            const data=await r\.json\(\);"
        if len(re.findall(pat, old)) != 1: raise RuntimeError('Consulta RADIUS diferente da versao revisada.')
        old = re.sub(pat, "            const data=await jrcWanJson('/api/get-radius-session.php?device_id='+encodeURIComponent(window.DEVICE_ID));", old, count=1)
        old = once(old, '                traffic.online=false; traffic.latestSession=null;',
                   "                traffic.online=false; traffic.latestSession=null; traffic.ixcLoginId=null;\n                setMonitorText('bandwidth-sample-status','IXC/RADIUS: '+(data?.message || 'sessao online nao localizada.'));\n                setMonitorText('live-rx-mbps','--'); setMonitorText('live-tx-mbps','--');")
        old = once(old, "            if(traffic.ixcLoginId || s.username) loadIxcReplicaReport();",
                   "            if(monitoringActive() && (traffic.ixcLoginId || s.username)) loadIxcReplicaReport();")
        return old
    text = change(text, 'updateRadiusBandwidthSample', radius, True)
    # Do not enable automatic ping or report work on the overview.
    text = change(text, 'loadIxcReplicaReport', lambda old: once(old,
        '    async function loadIxcReplicaReport(force=false) {',
        '    async function loadIxcReplicaReport(force=false) {\n        if (!monitoringActive()) return;'))
    for ident in ('bandwidth-bars', 'bandwidth-sample-status'):
        old = "document.getElementById('" + ident + "')"
        if old not in text: raise RuntimeError('Identificador WAN nao encontrado: ' + ident)
        text = text.replace(old, "jrcWanElement('" + ident + "')")
    return text.replace('\n', newline).encode('utf-8')

def atomic(path, data, metadata):
    fd, name = tempfile.mkstemp(prefix='.jrc-wan-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data); out.flush(); os.fsync(out.fileno())
            os.fchmod(out.fileno(), stat.S_IMODE(metadata.st_mode))
            if os.geteuid() == 0: os.fchown(out.fileno(), metadata.st_uid, metadata.st_gid)
        os.replace(name, path)
    finally:
        if os.path.exists(name): os.unlink(name)

def check(data):
    node = shutil.which('node')
    if not node: raise RuntimeError('Node.js ausente para validar sintaxe. Nenhuma alteracao aplicada.')
    with tempfile.NamedTemporaryFile(suffix='.js') as tmp:
        tmp.write(data); tmp.flush()
        result = subprocess.run([node, '--check', tmp.name], capture_output=True, text=True)
    if result.returncode: raise RuntimeError('Sintaxe JavaScript invalida: ' + result.stderr[:1200])

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/var/www/gacs')
    parser.add_argument('--backup-dir', default='/root')
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--dry-run', action='store_true')
    mode.add_argument('--rollback', type=Path)
    args = parser.parse_args()
    parent = args.rollback.resolve().parent if args.rollback else Path(args.backup_dir).resolve()
    if not parent.is_dir(): raise RuntimeError('Pasta de backup inexistente.')
    with (parent / '.jrc-wan-overview.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args.rollback:
            backup = args.rollback.resolve()
            meta = json.loads((backup / 'manifest.json').read_text())
            if meta.get('version') != VERSION or meta.get('file') != REL:
                raise RuntimeError('Backup incompativel.')
            path = Path(meta['root']) / REL
            before = (backup / 'original.js').read_bytes()
            if path.is_symlink() or sha(path.read_bytes()) != meta['after'] or sha(before) != meta['before']:
                raise RuntimeError('Reversao cancelada: arquivo alterado depois da instalacao ou backup invalido.')
            check(before); atomic(path, before, path.stat())
            shutil.copystat(backup / 'original.js', path)
            print('RESTAURADO: apenas device-control-fix.js.'); return
        root = Path(args.root).resolve()
        path = root / REL
        if not path.is_file() or path.is_symlink() or root not in path.resolve().parents:
            raise RuntimeError('Arquivo regular esperado nao encontrado: ' + str(path))
        before = path.read_bytes(); after = patch(before)
        if after is None: print('JA INSTALADO: nenhuma alteracao.'); return
        check(before); check(after)
        print('VALIDADO: ' + REL + ' | demais arquivos preservados.')
        if args.dry_run: print('SIMULACAO OK: nenhum arquivo da aplicacao alterado.'); return
        if path.read_bytes() != before: raise RuntimeError('Edicao concorrente detectada.')
        backup = Path(tempfile.mkdtemp(prefix='gacs-wan-overview-' + datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + '-', dir=str(parent)))
        os.chmod(backup, 0o700)
        shutil.copy2(path, backup / 'original.js')
        if (backup / 'original.js').read_bytes() != before: raise RuntimeError('Arquivo mudou durante backup.')
        shutil.copy2(Path(__file__).resolve(), backup / 'install.py')
        meta = dict(version=VERSION, root=str(root), file=REL, before=sha(before), after=sha(after))
        (backup / 'manifest.json').write_text(json.dumps(meta, indent=2))
        diff = ''.join(difflib.unified_diff(before.decode().splitlines(True), after.decode().splitlines(True), fromfile=REL, tofile=REL))
        (backup / 'changes.diff').write_text(diff)
        if path.read_bytes() != before: raise RuntimeError('Arquivo mudou antes da instalacao.')
        atomic(path, after, path.stat())
        pointer = parent / 'gacs-wan-overview-ultimo'
        pointer.write_text(str(backup) + '\n'); os.chmod(pointer, 0o600)
        print('INSTALADO: monitor WAN habilitado na Visao geral e na aba Monitoramento.')
        print('Backup: ' + str(backup))
        print('Sem alteracoes no optico, APIs, Wi-Fi ou configuracao dos equipamentos.')
        print('Atualize a pagina com Ctrl+F5. Valores dependem das fontes existentes.')

if __name__ == '__main__':
    try: main()
    except Exception as error:
        print('CANCELADO: ' + str(error), file=sys.stderr); sys.exit(1)
