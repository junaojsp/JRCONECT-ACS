"""Concurrent cache regression: no real IXC requests or credentials."""
import argparse, json, pathlib, subprocess, time, shutil, uuid
parser=argparse.ArgumentParser()
parser.add_argument('--php', required=True)
parser.add_argument('--temp-dir', required=True)
args=parser.parse_args()
repo=pathlib.Path(__file__).resolve().parents[1]
parent=pathlib.Path(args.temp_dir).resolve()
root=parent/('noc-cache-test-'+uuid.uuid4().hex)
root.mkdir()
script=root/'worker.php'
script.write_text(r'''<?php
require $argv[1];
putenv('NOC_CACHE_DIR=' . $argv[2]);
$client = new App\NocIxcOptical(['base_url'=>'https://example.invalid','token'=>'synthetic']);
if ($argv[3] === 'read') {
    file_put_contents($argv[2].'/reader-ready','ready');
    $start=microtime(true);
    try { echo json_encode(['snapshot'=>$client->snapshot((int)$argv[4]),'elapsed'=>microtime(true)-$start]); }
    catch (Throwable $e) { echo json_encode(['snapshot'=>['reason'=>App\NocIxcOptical::failureReason($e)],'elapsed'=>microtime(true)-$start]); }
    exit;
}
$base = dirname((new ReflectionClass(App\NocIxcOptical::class))->getFileName());
$identity = substr(hash('sha256', 'v2' . 'https://example.invalid' . "\0" . 'synthetic' . "\0" . $base),0,32);
$dir = $argv[2] . '/jrc-noc-ixc-' . $identity;
mkdir($dir,0700,true);
$lock=fopen($dir.'/refresh.lock','c');flock($lock,LOCK_EX);
file_put_contents($argv[2].'/ready','ready');
for($i=0;$i<1500&&!is_file($argv[2].'/reader-ready');$i++)usleep(20000);
if ($argv[3] === 'publish') {
    usleep(500000);
    file_put_contents($dir.'/temp',json_encode(['version'=>2,'captured_at'=>time(),'devices'=>[],'inventory_total'=>17]));
    rename($dir.'/temp',$dir.'/optical.json');
    usleep(300000);
} else { usleep(1800000); }
flock($lock,LOCK_UN);fclose($lock);
''',encoding='utf-8')
try:
    for mode in ['publish','busy']:
        case=root/mode;case.mkdir()
        command=[args.php,str(script),str(repo/'lib/NocIxcOptical.php'),str(case)]
        holder=subprocess.Popen(command+[mode],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        try:
            for _ in range(1500):
                if (case/'ready').is_file(): break
                if holder.poll() is not None: raise RuntimeError(holder.communicate())
                time.sleep(.02)
            else: raise RuntimeError('Cache holder did not start')
            start=time.monotonic()
            result=subprocess.run(command+['read','2' if mode=='publish' else '1'],capture_output=True,check=True,timeout=40)
            envelope=json.loads(result.stdout)
            data=envelope['snapshot']
            if mode=='publish':
                assert data['inventory_total']==17,data
            else:
                assert data=={'reason':'refresh_busy'},data
                assert envelope['elapsed']<1.5
            holder.wait(timeout=40)
            assert holder.returncode==0,holder.communicate()
            print('PASS cache '+mode+': shared fresh result / bounded contention')
        finally:
            if holder.poll() is None: holder.terminate();holder.wait(timeout=40)
finally:
    assert root.resolve().parent==parent and root.name.startswith('noc-cache-test-')
    shutil.rmtree(root)
