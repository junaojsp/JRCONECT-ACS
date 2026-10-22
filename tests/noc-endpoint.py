"""Isolated HTTP regression tests; no production DB, credentials or CPEs.
Usage: python tests/noc-endpoint.py --php /path/to/php [--curl-extension /path/to/php_curl.dll]
"""
import argparse
import base64
import datetime
import contextlib
import http.server
import json
import pathlib
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[1]
CALLS = []
IXC_CALLS = []
NOW = datetime.datetime.now(datetime.timezone.utc)


def device(index):
    return {
        '_id': f'device-{index:06}',
        '_deviceId': {'_SerialNumber': f'SERIAL-{index}', '_ProductClass': 'TEST', '_Manufacturer': 'TEST'},
        '_lastInform': (NOW - datetime.timedelta(seconds=60 if index % 2 == 0 else 600)).isoformat(),
        'VirtualParameters': {'RXPower': {'_value': [-24, -25, -28, -29, 0, 'invalid'][index % 6]}},
    }


class Upstream(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        assert self.path == '/webservice/v1/radpop_radio_cliente_fibra'
        assert self.headers['ixcsoft'] == 'listar'
        mode = base64.b64decode(self.headers['Authorization'].split()[1]).decode().split(':')[0]
        query = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        assert query['qtype'] == 'radpop_radio_cliente_fibra.id' and query['oper'] == '>'
        assert query['rp'] == '100' and query['sortorder'] == 'asc'
        IXC_CALLS.append((mode, query))
        start = (int(query['page']) - 1) * 100
        total = 0 if mode == 'ixc-empty' else 2500
        records = [{'id': str(i + 1), 'mac': f'SERIAL-{i}',
                    'sinal_rx': ['-30,5', -24, 0, -50, '-inf', ''][i % 6],
                    'data_sinal': '2026-10-21 21:00:00', 'password': 'SECRET', 'login': 'SECRET',
                    'id_transmissor': str(i % 3 + 1), 'slotno': '1', 'ponno': str(i % 7 + 1)}
                   for i in range(start, min(start + 100, total))]
        if mode == 'ixc-repeated' and start:
            records[0]['id'] = '1'
        if mode == 'ixc-truncated' and start:
            records = []
        data = {'total': str(total), 'registros': records}
        if mode == 'ixc-malformed':
            data = {'error': 'SECRET'}
        body = json.dumps(data).encode()
        self.send_response(503 if mode == 'ixc-unavailable' else 200)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query)
        mode = base64.b64decode(self.headers['Authorization'].split()[1]).decode().split(':')[0]
        CALLS.append((mode, query))
        assert urllib.parse.urlsplit(self.path).path == '/devices/'
        assert json.loads(query['sort'][0]) == {'_id': 1}
        assert int(query['limit'][0]) <= 100
        projection = query['projection'][0].split(',')
        assert len(projection) == 6 and '_lastInform' in projection
        assert all('Password' not in p and 'WLAN' not in p for p in projection)
        skip, limit = int(query['skip'][0]), int(query['limit'][0])
        code = 503 if mode == 'unavailable' or (mode == 'later-failure' and skip) else 200
        if mode == 'invalid-json':
            body = b'{broken'
        elif mode == 'object':
            body = b'{}'
        elif mode == 'oversized':
            body = b' ' * (4 * 1024 * 1024 + 1)
        elif mode == 'extra-devices':
            body = json.dumps([device(i) for i in range(101)]).encode()
        else:
            total = 0 if mode == 'empty' else 200 if mode == 'exact-page' else 2413
            body = json.dumps([device(i) for i in range(skip, min(total, skip + limit))]).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError, ConnectionAbortedError):
            pass


def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


@contextlib.contextmanager
def test_directory(parent):
    # Use inherited Windows ACLs so a sandboxed PHP child can read the fixture.
    parent = pathlib.Path(parent or tempfile.gettempdir()).resolve()
    root = parent / ('jrc-noc-test-' + uuid.uuid4().hex)
    root.mkdir()
    try:
        yield root
    finally:
        assert root.resolve().parent == parent and root.name.startswith('jrc-noc-test-')
        shutil.rmtree(root)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    parser.add_argument('--curl-extension')
    parser.add_argument('--temp-dir', help='Writable parent for isolated test files')
    args = parser.parse_args()
    php = [args.php]
    if args.curl_extension:
        php += ['-d', 'extension=' + str(pathlib.Path(args.curl_extension).resolve())]
    for path in ['api/dashboard-noc-data.php', 'lib/NocGenieACS.php', 'lib/NocIxcOptical.php', 'lib/NocHistory.php', 'lib/IXCConfig.php', 'dashboard-noc.php']:
        subprocess.run(php + ['-l', str(ROOT / path)], check=True)
    upstream = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Upstream)
    threading.Thread(target=upstream.serve_forever, daemon=True).start()
    try:
        with test_directory(args.temp_dir) as temp:
            root = pathlib.Path(temp)
            for path in ['api/dashboard-noc-data.php', 'lib/NocGenieACS.php', 'lib/NocIxcOptical.php', 'lib/NocHistory.php', 'lib/IXCConfig.php', 'lib/helpers.php']:
                (root / path).parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(ROOT / path, root / path)
            (root / 'config').mkdir()
            (root / 'config/config.php').write_text('''<?php
session_start();
$mode = $_GET['scenario'] ?? 'normal';
putenv('IXC_BASE_URL');
putenv('IXC_TOKEN');
putenv('NOC_CACHE_DIR=' . __DIR__ . '/../cache');
if (strpos($mode, 'ixc-') === 0) {
    putenv('IXC_BASE_URL=http://127.0.0.1:PORT');
    putenv('IXC_TOKEN=' . $mode . ':SECRET');
}
$_SESSION = $mode === 'unauthorized' ? [] : ['user_id' => 1];
require_once __DIR__ . '/../lib/helpers.php';
register_shutdown_function(static function () use ($mode) {
    error_log('NOC_TEST_PEAK ' . $mode . '=' . memory_get_peak_usage(true));
});
if ($mode === 'bootstrap-throw') { echo 'SECRET'; throw new RuntimeException('SECRET'); }
if ($mode === 'bootstrap-exit') { die('SECRET'); }
if ($mode === 'memory-fatal') { $huge = str_repeat('x', 256 * 1024 * 1024); }
class TestResult {
    public function fetch_assoc() {
        global $mode;
        return $mode === 'missing-credentials' ? null : [
            'count' => $mode === 'unconfigured' ? 0 : 1,
            'host' => '127.0.0.1', 'port' => PORT,
            'username' => $mode, 'password' => 'SECRET'
        ];
    }
}
class TestDB {
    public function query($sql) {
        if (($_GET['scenario'] ?? '') === 'database-throw') { throw new RuntimeException('SECRET'); }
        return new TestResult();
    }
}
function getDBConnection() { return new TestDB(); }
'''.replace('PORT', str(upstream.server_port)), encoding='utf-8')
            port = free_port()
            with (root / 'php.log').open('w+') as log:
                proc = subprocess.Popen(php + ['-d', 'memory_limit=128M', '-d', 'display_errors=0',
                                             '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=log, stderr=log)
                try:
                    for _ in range(100):
                        if proc.poll() is not None:
                            log.seek(0)
                            raise RuntimeError(log.read())
                        try:
                            with socket.create_connection(('127.0.0.1', port), timeout=.1):
                                break
                        except OSError:
                            time.sleep(.05)
                    for mode, expected in [
                        ('normal', 200), ('empty', 200), ('exact-page', 200),
                        ('unauthorized', 401), ('unconfigured', 503), ('missing-credentials', 503),
                        ('unavailable', 502), ('later-failure', 502), ('invalid-json', 502),
                        ('object', 502), ('oversized', 502), ('extra-devices', 502),
                        ('bootstrap-throw', 500), ('bootstrap-exit', 500), ('database-throw', 500),
                        ('memory-fatal', 500),
                        ('ixc-ok', 200), ('ixc-ok', 200), ('ixc-empty', 200),
                        ('ixc-unavailable', 200), ('ixc-malformed', 200),
                        ('ixc-repeated', 200), ('ixc-truncated', 200),
                    ]:
                        before = len(CALLS)
                        ixc_before = len(IXC_CALLS)
                        url = f'http://127.0.0.1:{port}/api/dashboard-noc-data.php?scenario={mode}'
                        try:
                            response = urllib.request.urlopen(url, timeout=60)
                        except urllib.error.HTTPError as error:
                            response = error
                        raw = response.read().decode()
                        assert response.code == expected, (mode, response.code, raw)
                        assert 'application/json' in response.headers['Content-Type']
                        assert 'no-store' in response.headers['Cache-Control']
                        assert 'Location' not in response.headers
                        assert 'SECRET' not in raw and 'password' not in raw.lower()
                        data = json.loads(raw)
                        assert data['success'] == (expected == 200), (mode, data)
                        if expected != 200:
                            assert 'summary' not in data  # Never expose a partial success.
                        if mode in ('unauthorized', 'unconfigured', 'missing-credentials'):
                            assert len(CALLS) == before
                        if mode == 'normal':
                            assert len(CALLS) - before == 25
                            assert [int(q['skip'][0]) for _, q in CALLS[before:]] == list(range(0, 2413, 100))
                            s = data['summary']
                            assert (s['total'], s['online'], s['offline']) == (2413, 1207, 1206), s
                            assert (s['optical_good'], s['optical_attention'], s['optical_critical'], s['optical_unavailable']) == (403, 402, 804, 804), s
                            assert data['history']['status'] == 'ok' and len(data['history']['points']) == 1
                            assert data['history']['points'][0]['total'] == 2413
                            assert len(data['issues']) == 8
                            assert [i['priority'] for i in data['issues']] == sorted(i['priority'] for i in data['issues'])
                        if mode == 'empty':
                            assert data['summary']['total'] == 0 and data['summary']['availability_percent'] is None
                        if mode == 'exact-page':
                            assert len(CALLS) - before == 3 and data['summary']['total'] == 200
                        if mode.startswith('ixc-'):
                            s = data['summary']
                            assert s['total'] == 2413  # IXC-only records never change the ACS inventory.
                            status = 'ok' if mode in ('ixc-ok', 'ixc-empty') else 'unavailable'
                            assert data['optical']['status'] == status, (mode, data['optical'])
                            assert data['capabilities']['ixc_optical_primary'] == (status == 'ok')
                        if mode == 'ixc-ok':
                            assert data['optical']['inventory_total'] == 2500
                            assert (s['optical_good'], s['optical_attention'], s['optical_critical'], s['optical_unavailable']) == (402, 0, 1207, 804), s
                            assert data['optical']['summary'] == {'total': 2500, 'good': 417, 'attention': 0, 'critical': 834, 'unavailable': 1249}
                            assert len(data['optical']['pons']) == 5
                            assert all(p['registered'] >= p['critical'] for p in data['optical']['pons'])
                            assert 'capacity' not in data['optical']['pons'][0]
                            assert (s['optical_from_ixc'], s['optical_from_tr069']) == (1207, 402), s
                            assert all(i['priority'] == 0 for i in data['issues'])
                            previous = any(m == mode for m, _ in IXC_CALLS[:ixc_before])
                            assert len(IXC_CALLS) - ixc_before == (0 if previous else 25)
                        print(f'PASS {mode}: HTTP {expected}, valid JSON (128M limit)')
                    log.flush()
                    log.seek(0)
                    normal_peak = next(line.split('NOC_TEST_PEAK normal=')[1].strip()
                                       for line in log if 'NOC_TEST_PEAK normal=' in line)
                    print('Normal snapshot peak PHP memory: ' + normal_peak + ' bytes')
                    log.seek(0)
                    ixc_peak = next(line.split('NOC_TEST_PEAK ixc-ok=')[1].strip()
                                    for line in log if 'NOC_TEST_PEAK ixc-ok=' in line)
                    print('IXC + ACS snapshot peak PHP memory: ' + ixc_peak + ' bytes')
                    for cache in (root / 'cache').glob('*/optical.json'):
                        raw = cache.read_text()
                        assert 'SECRET' not in raw and 'password' not in raw and 'login' not in raw
                    print('PASS private cache contains only sanitized optical data; cache hit avoids IXC requests')
                finally:
                    proc.terminate()
                    proc.wait(timeout=10)
    finally:
        upstream.shutdown()


if __name__ == '__main__':
    main()
