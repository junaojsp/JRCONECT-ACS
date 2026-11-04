import argparse,contextlib,http.client,pathlib,socket,subprocess,time,uuid,shutil
p=argparse.ArgumentParser();p.add_argument('--php',default='php');a=p.parse_args()
source=pathlib.Path(__file__).resolve().parents[1]
@contextlib.contextmanager
def test_directory():
 root=source.parent/('jrc-route-'+uuid.uuid4().hex);root.mkdir()
 try:yield root
 finally:
  assert root.resolve().parent==source.parent.resolve() and root.name.startswith('jrc-route-')
  shutil.rmtree(root)
with test_directory() as directory:
 root=pathlib.Path(directory);(root/'config').mkdir()
 
 for name in ['device-noc.php','dashboard-noc.php','devices-noc.php']:(root/name).write_bytes((source/name).read_bytes())
 (root/'config/config.php').write_text("<?php function requireLogin(){if(($_GET['auth']??'1')==='0'){http_response_code(401);exit;}}")
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 with (root/'server.log').open('w') as log:
  process=subprocess.Popen([a.php,'-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log)
  try:
   for _ in range(200):
    try:
     with socket.create_connection(('127.0.0.1',port),timeout=.2):break
    except OSError:time.sleep(.1)
   for query,status,location in [('id=test-device',302,'/device-detail.php?id=test-device'),('id=x%26next%3Dhttps%3A%2F%2Fexample.invalid',302,'/device-detail.php?id=x%26next%3Dhttps%3A%2F%2Fexample.invalid'),('',302,'/devices.php'),('id[]=x',302,'/devices.php'),('id=x&auth=0',401,None)]:
    conn=http.client.HTTPConnection('127.0.0.1',port,timeout=5);conn.request('GET','/device-noc.php?'+query);r=conn.getresponse();assert (r.status,r.getheader('Location'))==(status,location);r.read();conn.close()
   for path,expected in [('dashboard-noc.php','/dashboard.php'),('devices-noc.php','/devices.php')]:
    conn=http.client.HTTPConnection('127.0.0.1',port,timeout=5);conn.request('GET','/'+path);r=conn.getresponse();assert (r.status,r.getheader('Location'))==(302,expected);r.read();conn.close()
   print('PASS operational route: existing authenticated page, safe ID encoding, invalid input and denied session')
  finally:process.terminate();process.wait(timeout=10)
