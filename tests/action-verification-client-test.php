<?php
// Execute with php -n: curl and the DB are simulated; no network calls are made.
if (extension_loaded('curl')) { fwrite(STDERR,"Run this test with php -n.\n"); exit(1); }
foreach (['CURLOPT_URL','CURLOPT_RETURNTRANSFER','CURLOPT_TIMEOUT','CURLOPT_CONNECTTIMEOUT','CURLOPT_USERPWD','CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_HTTPHEADER','CURLOPT_CUSTOMREQUEST','CURLINFO_HTTP_CODE'] as $i=>$name) define($name,$i+1);
define('MYSQLI_ASSOC',1);
$httpCode=200; $calls=[]; $audit=[]; $curlBody=null; $verificationRow=null;
function curl_init($url=null) { return (object)['options'=>[CURLOPT_URL=>$url]]; }
function curl_setopt($ch,$option,$value) { $ch->options[$option]=$value; return true; }
function curl_setopt_array($ch,$options) { foreach ($options as $option=>$value) curl_setopt($ch,$option,$value); return true; }
function curl_exec($ch) { global $calls,$curlBody; $calls[]=$ch->options; return $curlBody ?? '{"_id":"aaaaaaaaaaaaaaaaaaaaaaaa"}'; }
function curl_getinfo($ch,$option) { global $httpCode; return $httpCode; }
function curl_error($ch) { return ''; }
function curl_close($ch) {}
class VerificationFakeResult {
    private $rows;
    function __construct($rows=[['device_id'=>'ONU']]) { $this->rows=$rows; }
    function fetch_assoc() { return $this->rows[0] ?? null; }
    function fetch_all($mode) { return $this->rows; }
}
class VerificationFakeStatement {
    private $sql; private $values=[];
    function __construct($sql) { $this->sql=$sql; }
    function bind_param($types,&...$values) { $this->values=&$values; }
    function execute() { global $audit; $audit[]=[$this->sql,$this->values]; return true; }
    function get_result() { return new VerificationFakeResult(); }
    function close() {}
}
class VerificationFakeDB {
    public $insert_id=1;
    function prepare($sql) { return new VerificationFakeStatement($sql); }
    function query($sql) {
        global $verificationRow;
        return new VerificationFakeResult(str_contains($sql,'device_action_verification') ? [$verificationRow] : [['host'=>'mock','port'=>7557]]);
    }
}
function getDBConnection() { return new VerificationFakeDB(); }
require_once __DIR__.'/../lib/TrackedGenieACS.php';
function clientCheck($condition,$message) { if (!$condition) throw new RuntimeException($message); }
$client=new App\TrackedGenieACS('mock',7557);
$parameters=[['Device.WiFi.SSID.1.SSID','SSID_SENTINEL','xsd:string'],['Device.WiFi.AccessPoint.1.Security.KeyPassphrase','PASSWORD_SENTINEL','xsd:string']];
$result=$client->setParameterValues('ONU',$parameters);
clientCheck($result['success'] && $result['http_code']===200 && $result['action_history_id']===1,'Preserve write response');
clientCheck(count($calls)===2,'One write and one read');
$write=json_decode($calls[0][CURLOPT_POSTFIELDS],true);
$read=json_decode($calls[1][CURLOPT_POSTFIELDS],true);
clientCheck($write['name']==='setParameterValues' && $write['parameterValues']===$parameters,'Unchanged payload');
clientCheck($read===['name'=>'getParameterValues','parameterNames'=>['Device.WiFi.SSID.1.SSID']],'Read only allowed fields');
clientCheck(!str_contains(json_encode($audit),'PASSWORD_SENTINEL') && !str_contains(json_encode($audit),'SSID_SENTINEL'),'No raw values in DB');
$httpCode=202; $calls=[];
$client->setParameterValues('ONU',$parameters);
clientCheck(count($calls)===2,'Queued write receives one read');
$httpCode=400; $calls=[];
$result=$client->setParameterValues('ONU',$parameters);
clientCheck(!$result['success'] && count($calls)===1,'Failed write does not schedule read');
$httpCode=200; $calls=[];
$client->setParameterValues('ONU',[['Device.WiFi.AccessPoint.1.Security.KeyPassphrase','PASSWORD_SENTINEL','xsd:string']]);
clientCheck(count($calls)===1,'Secret-only change never requests a credential read');
$fields=acsVerificationExpected($parameters)['fields'];
$verificationRow=['action_id'=>1,'expected_json'=>json_encode($fields),'excluded_count'=>1,'status'=>'pending',
    'read_started_ms'=>(int)floor(microtime(true)*1000)-10000,'read_accepted'=>1,'matched_count'=>0,'read_task_id'=>str_repeat('a',24)];
$calls=[];
$history=acsVerificationHistory(new VerificationFakeDB(),[['id'=>1]],'ONU',[str_repeat('a',24)=>true],[]);
clientCheck($history[1]['status']==='pending' && count($calls)===0,'Pending read cannot confirm from cache');
$fresh=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
$curlBody=json_encode([['Device'=>['WiFi'=>['SSID'=>[1=>['SSID'=>['_value'=>'SSID_SENTINEL','_timestamp'=>$fresh]]]]]]]);
$history=acsVerificationHistory(new VerificationFakeDB(),[['id'=>1]],'ONU',[],[]);
clientCheck($history[1]['status']==='confirmed' && $history[1]['matched']===1,'Queue disappearance requires a fresh matching value');
$calls=[];
$history=acsVerificationHistory(new VerificationFakeDB(),[['id'=>1]],'ONU',[],['ONU:task_'.str_repeat('a',24)=>true]);
clientCheck($history[1]['status']==='unavailable' && count($calls)===0,'Faulty read cannot confirm');
echo "Tracked client verification tests passed.\n";
