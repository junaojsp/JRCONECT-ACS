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
    for path in ['api/dashboard-noc-data.php', 'lib/NocGenieACS.php', 'dashboard-noc.php']:
        subprocess.run(php + ['-l', str(ROOT / path)], check=True)
    upstream = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Upstream)
    threading.Thread(target=upstream.serve_forever, daemon=True).start()
    try:
        with test_directory(args.temp_dir) as temp:
            root = pathlib.Path(temp)
            for path in ['api/dashboard-noc-data.php', 'lib/NocGenieACS.php', 'lib/helpers.php']:
                (root / path).parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(ROOT / path, root / path)
            (root / 'config').mkdir()
            (root / 'config/config.php').write_text('''<?php
session_start();
$mode = $_GET['scenario'] ?? 'normal';
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
                    ]:
                        before = len(CALLS)
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
                            assert (s['optical_good'], s['optical_attention'], s['optical_critical'], s['optical_unavailable']) == (403, 804, 402, 804), s
                            assert len(data['issues']) == 8
                            assert [i['priority'] for i in data['issues']] == sorted(i['priority'] for i in data['issues'])
                        if mode == 'empty':
                            assert data['summary']['total'] == 0 and data['summary']['availability_percent'] is None
                        if mode == 'exact-page':
                            assert len(CALLS) - before == 3 and data['summary']['total'] == 200
                        print(f'PASS {mode}: HTTP {expected}, valid JSON (128M limit)')
                    log.flush()
                    log.seek(0)
                    normal_peak = next(line.split('NOC_TEST_PEAK normal=')[1].strip()
                                       for line in log if 'NOC_TEST_PEAK normal=' in line)
                    print('Normal snapshot peak PHP memory: ' + normal_peak + ' bytes')
                finally:
                    proc.terminate()
                    proc.wait(timeout=10)
    finally:
        upstream.shutdown()


if __name__ == '__main__':
    main()
