<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
requireLogin();

use App\GenieACS;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');

function dcReply(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function dcRole(): string {
    return (string)($_SESSION['role'] ?? '');
}
function dcCanWifi(): bool {
    return in_array(dcRole(), ['admin','noc','atendimento'], true);
}
function dcCanAdmin(): bool {
    return in_array(dcRole(), ['admin','noc'], true);
}
function dcBool(mixed $v): ?bool {
    if (in_array($v, [true,1,'1','true','True'], true)) return true;
    if (in_array($v, [false,0,'0','false','False'], true)) return false;
    return null;
}
function dcReadable(mixed $v): bool {
    if (!is_scalar($v) || is_bool($v)) return false;
    $s = trim((string)$v);
    return $s !== '' && !in_array(strtoupper($s), ['N/A','N/D','NULL','[OCULTO]'], true)
        && !preg_match('/^[*\x{2022}]+$/u', $s);
}
function dcFlatten(array $device): array {
    $nodes = [];
    $walk = function(array $node, string $path, int $depth) use (&$walk, &$nodes): void {
        if ($depth > 32 || count($nodes) > 40000) return;
        $nodes[$path] = $node;
        foreach ($node as $k => $v) {
            if (str_starts_with((string)$k, '_') || !is_array($v)) continue;
            $walk($v, $path === '' ? (string)$k : $path . '.' . $k, $depth + 1);
        }
    };
    foreach (['Device','InternetGatewayDevice','VirtualParameters'] as $root) {
        if (isset($device[$root]) && is_array($device[$root])) $walk($device[$root], $root, 0);
    }
    return $nodes;
}
function dcValue(array $nodes, string $path): mixed {
    return $nodes[$path]['_value'] ?? null;
}
function dcWritable(array $nodes, string $path): bool {
    return isset($nodes[$path]) && dcBool($nodes[$path]['_writable'] ?? null) === true;
}
function dcFirst(array $nodes, array $paths, bool $requireReadable = false): ?string {
    foreach ($paths as $p) {
        if (!isset($nodes[$p])) continue;
        if (!$requireReadable || dcReadable(dcValue($nodes,$p))) return $p;
    }
    return null;
}
function dcBandLabel(mixed $band, mixed $channel, mixed $standard, int $index): string {
    $b = strtolower(trim((string)$band));
    if (str_contains($b,'5')) return 'Wi-Fi 5 GHz';
    if (str_contains($b,'2.4') || str_contains($b,'2g')) return 'Wi-Fi 2.4 GHz';
    $ch = is_numeric($channel) ? (int)$channel : 0;
    if ($ch > 14) return 'Wi-Fi 5 GHz';
    if ($ch >= 1 && $ch <= 14) return 'Wi-Fi 2.4 GHz';
    $s = strtolower((string)$standard);
    if (preg_match('/(^|[^a-z])(ac|ax)([^a-z]|$)/', $s)) return 'Wi-Fi 5 GHz';
    return 'Rede Wi-Fi ' . $index;
}
function dcWifi(array $nodes): array {
    $out=[]; $seen=[]; $n=0;
    foreach (array_keys($nodes) as $path) {
        if (!preg_match('~^(InternetGatewayDevice\.LANDevice\.\d+\.WLANConfiguration\.(\d+))(?:\.|$)~', $path, $m)) continue;
        $base=$m[1]; if(isset($seen[$base])) continue; $seen[$base]=1; $n++;
        $ssidPath=dcFirst($nodes,[$base.'.SSID']);
        $passCandidates=[$base.'.KeyPassphrase',$base.'.PreSharedKey.1.KeyPassphrase',$base.'.PreSharedKey.1.PreSharedKey'];
        $passRead=dcFirst($nodes,$passCandidates,true) ?? dcFirst($nodes,$passCandidates);
        $passWrite=null; foreach($passCandidates as $p){ if(dcWritable($nodes,$p)){ $passWrite=$p; break; } }
        $channel=dcValue($nodes,$base.'.Channel');
        $band=dcValue($nodes,$base.'.OperatingFrequencyBand') ?? dcValue($nodes,$base.'.X_FH_OperatingFrequencyBand') ?? dcValue($nodes,$base.'.X_HW_FrequencyBand');
        $standard=dcValue($nodes,$base.'.Standard');
        $out[]=[
            'id'=>$base,'standard'=>'TR-098','label'=>dcBandLabel($band,$channel,$standard,$n),
            'enabled'=>dcBool(dcValue($nodes,$base.'.Enable')),'ssid'=>dcValue($nodes,$ssidPath ?? ''),
            'ssid_writable'=>$ssidPath ? dcWritable($nodes,$ssidPath) : false,
            'password'=>$passRead && dcReadable(dcValue($nodes,$passRead)) ? (string)dcValue($nodes,$passRead) : null,
            'password_state'=>$passRead ? (dcReadable(dcValue($nodes,$passRead)) ? 'available' : 'concealed') : 'absent',
            'password_writable'=>$passWrite !== null,'channel'=>$channel,
            'auto_channel'=>dcBool(dcValue($nodes,$base.'.AutoChannelEnable')),
            'security'=>dcValue($nodes,$base.'.BeaconType') ?? dcValue($nodes,$base.'.BasicEncryptionModes'),
        ];
    }
    foreach (array_keys($nodes) as $path) {
        if (!preg_match('~^(Device\.WiFi\.SSID\.(\d+))(?:\.|$)~',$path,$m)) continue;
        $base=$m[1]; if(isset($seen[$base])) continue; $seen[$base]=1; $n++;
        $ap=null;
        foreach(array_keys($nodes) as $p){
            if(!preg_match('~^(Device\.WiFi\.AccessPoint\.\d+)(?:\.|$)~',$p,$am)) continue;
            $candidate=$am[1];
            if(rtrim((string)dcValue($nodes,$candidate.'.SSIDReference'),'.')===$base){$ap=$candidate;break;}
        }
        $radio=null; $lower=(string)dcValue($nodes,$base.'.LowerLayers');
        foreach(array_filter(array_map('trim',explode(',',$lower))) as $p){
            $p=rtrim($p,'.'); if(preg_match('~^Device\.WiFi\.Radio\.\d+$~',$p)&&isset($nodes[$p])){$radio=$p;break;}
        }
        $ssidPath=$base.'.SSID';
        $passCandidates=$ap?[$ap.'.Security.KeyPassphrase',$ap.'.Security.PreSharedKey']:[];
        $passRead=dcFirst($nodes,$passCandidates,true) ?? dcFirst($nodes,$passCandidates);
        $passWrite=null; foreach($passCandidates as $p){ if(dcWritable($nodes,$p)){ $passWrite=$p;break; } }
        $channel=$radio?dcValue($nodes,$radio.'.Channel'):null;
        $band=$radio?dcValue($nodes,$radio.'.OperatingFrequencyBand'):null;
        $standard=$radio?dcValue($nodes,$radio.'.OperatingStandards'):null;
        $out[]=[
            'id'=>$base,'standard'=>'TR-181','label'=>dcBandLabel($band,$channel,$standard,$n),
            'enabled'=>dcBool(dcValue($nodes,($ap ?: $base).'.Enable')),'ssid'=>dcValue($nodes,$ssidPath),
            'ssid_writable'=>dcWritable($nodes,$ssidPath),
            'password'=>$passRead&&dcReadable(dcValue($nodes,$passRead))?(string)dcValue($nodes,$passRead):null,
            'password_state'=>$passRead?(dcReadable(dcValue($nodes,$passRead))?'available':'concealed'):'absent',
            'password_writable'=>$passWrite!==null,'channel'=>$channel,
            'auto_channel'=>$radio?dcBool(dcValue($nodes,$radio.'.AutoChannelEnable')):null,
            'security'=>$ap?dcValue($nodes,$ap.'.Security.ModeEnabled'):null,
        ];
    }
    return $out;
}
function dcAccounts(array $nodes): array {
    $groups=[]; $seen=[];
    foreach(array_keys($nodes) as $path){
        if(!preg_match('~^((?:Device|InternetGatewayDevice)\.Users\.User\.\d+)(?:\.|$)~',$path,$m)) continue;
        $base=$m[1]; if(isset($seen[$base])) continue; $seen[$base]=1;
        $groups[]=['id'=>$base,'label'=>'Conta do equipamento','user'=>[$base.'.Username'],'pass'=>[$base.'.Password']];
    }
    foreach(['Device','InternetGatewayDevice'] as $root){
        $base=$root.'.DeviceInfo.X_CT-COM_TeleComAccount';
        if(isset($nodes[$base])){
            $groups[]=['id'=>$base,'label'=>'Conta Telecom','user'=>[$base.'.Username',$base.'.UserName'],'pass'=>[$base.'.Password']];
        }
        $base=$root.'.LANConfigSecurity';
        if(isset($nodes[$base.'.ConfigPassword'])){
            $groups[]=['id'=>$base,'label'=>'Acesso local','user'=>[],'pass'=>[$base.'.ConfigPassword']];
        }
    }
    if(!$groups && (isset($nodes['VirtualParameters.superAdmin'])||isset($nodes['VirtualParameters.superPassword']))){
        $groups[]=['id'=>'VirtualParameters','label'=>'Administrador (somente leitura)','user'=>['VirtualParameters.superAdmin'],'pass'=>['VirtualParameters.superPassword'],'readonly'=>true];
    }
    $out=[];
    foreach($groups as $g){
        $up=dcFirst($nodes,$g['user'],true) ?? dcFirst($nodes,$g['user']);
        $pp=dcFirst($nodes,$g['pass'],true) ?? dcFirst($nodes,$g['pass']);
        $uw=false; foreach($g['user'] as $p){if(dcWritable($nodes,$p)){$uw=true;break;}}
        $pw=false; foreach($g['pass'] as $p){if(dcWritable($nodes,$p)){$pw=true;break;}}
        if(!empty($g['readonly'])){$uw=$pw=false;}
        $out[]=[
            'id'=>$g['id'],'label'=>$g['label'],
            'username'=>$up&&dcReadable(dcValue($nodes,$up))?(string)dcValue($nodes,$up):null,
            'username_writable'=>$uw,
            'password'=>$pp&&dcReadable(dcValue($nodes,$pp))?(string)dcValue($nodes,$pp):null,
            'password_state'=>$pp?(dcReadable(dcValue($nodes,$pp))?'available':'concealed'):'absent',
            'password_writable'=>$pw
        ];
    }
    return $out;
}
function dcClient(): GenieACS {
    $db=getDBConnection();
    $r=$db->query("SELECT host,port,username,password FROM genieacs_credentials WHERE is_connected=1 ORDER BY id DESC LIMIT 1");
    $c=$r?$r->fetch_assoc():null;
    if(!$c) dcReply(['success'=>false,'message'=>'Canal de gerenciamento não configurado.'],503);
    return new GenieACS($c['host'],$c['port'],$c['username'],$c['password']);
}
function dcLoad(string $deviceId): array {
    $g=dcClient(); $r=$g->getDevice($deviceId);
    if(empty($r['success'])||empty($r['data'])) dcReply(['success'=>false,'message'=>'Equipamento não encontrado.'],404);
    return [$g,$r['data'],dcFlatten($r['data'])];
}
function dcToken(): string {
    if(empty($_SESSION['device_control_csrf'])) $_SESSION['device_control_csrf']=bin2hex(random_bytes(32));
    return $_SESSION['device_control_csrf'];
}
function dcVerifyToken(): void {
    $got=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
    if(!$got||!hash_equals(dcToken(),$got)) dcReply(['success'=>false,'message'=>'Token de segurança expirado. Atualize a página.'],403);
}
function dcFindWifi(array $rows,string $id): ?array { foreach($rows as $r) if($r['id']===$id)return $r; return null; }
function dcFindAccount(array $rows,string $id): ?array { foreach($rows as $r) if($r['id']===$id)return $r; return null; }

$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'){
    $deviceId=trim((string)($_GET['device_id']??''));
    if($deviceId==='') dcReply(['success'=>false,'message'=>'device_id obrigatório.'],400);
    [,,$nodes]=dcLoad($deviceId);
    $wifi=dcWifi($nodes);
    $accounts=dcCanAdmin()?dcAccounts($nodes):[];
    dcReply(['success'=>true,'csrf'=>dcToken(),'permissions'=>['wifi'=>dcCanWifi(),'admin'=>dcCanAdmin()],'wifi'=>$wifi,'accounts'=>$accounts]);
}
if($method!=='POST') dcReply(['success'=>false,'message'=>'Método não permitido.'],405);
dcVerifyToken();
$body=json_decode(file_get_contents('php://input'),true);
if(!is_array($body)) dcReply(['success'=>false,'message'=>'JSON inválido.'],400);
$deviceId=trim((string)($body['device_id']??'')); $action=(string)($body['action']??'');
if($deviceId==='') dcReply(['success'=>false,'message'=>'device_id obrigatório.'],400);
[$g,$device,$nodes]=dcLoad($deviceId);

if($action==='refresh'){
    $kind=(string)($body['kind']??'wifi');
    $roots=$kind==='admin'
        ? ['Device.Users','Device.UserInterface','Device.DeviceInfo','Device.LANConfigSecurity','InternetGatewayDevice.Users','InternetGatewayDevice.UserInterface','InternetGatewayDevice.DeviceInfo','InternetGatewayDevice.LANConfigSecurity','VirtualParameters']
        : ['Device.WiFi','InternetGatewayDevice.LANDevice'];
    $ok=false;
    foreach($roots as $p){ if(isset($nodes[$p])){ $r=$g->addRefreshTask($deviceId,$p); $ok=$ok||!empty($r['success']); } }
    dcReply(['success'=>$ok,'message'=>$ok?'Atualização solicitada ao equipamento.':'Nenhum objeto compatível foi encontrado para atualizar.']);
}
if($action==='update_wifi'){
    if(!dcCanWifi()) dcReply(['success'=>false,'message'=>'Sem permissão para alterar Wi-Fi.'],403);
    $id=(string)($body['interface_id']??''); $row=dcFindWifi(dcWifi($nodes),$id);
    if(!$row) dcReply(['success'=>false,'message'=>'Interface Wi-Fi não localizada. Atualize a leitura.'],409);
    $params=[];
    if(array_key_exists('ssid',$body)){
        $ssid=(string)$body['ssid'];
        if(strlen($ssid)<1||strlen($ssid)>32) dcReply(['success'=>false,'message'=>'SSID deve conter 1 a 32 bytes.'],422);
        $path=$id.'.SSID'; if(!dcWritable($nodes,$path)) dcReply(['success'=>false,'message'=>'SSID não está liberado para escrita neste equipamento.'],422);
        $params[]=[$path,$ssid,'xsd:string'];
    }
    if(array_key_exists('password',$body) && (string)$body['password']!==''){
        $pwd=(string)$body['password'];
        if(strlen($pwd)<8||strlen($pwd)>63||!preg_match('/^[\x20-\x7e]+$/D',$pwd)) dcReply(['success'=>false,'message'=>'Senha Wi-Fi deve ter 8 a 63 caracteres ASCII.'],422);
        $candidates=str_starts_with($id,'Device.')?[]:[$id.'.KeyPassphrase',$id.'.PreSharedKey.1.KeyPassphrase',$id.'.PreSharedKey.1.PreSharedKey'];
        if(str_starts_with($id,'Device.')){
            foreach(array_keys($nodes) as $p){
                if(preg_match('~^(Device\.WiFi\.AccessPoint\.\d+)\.SSIDReference$~',$p,$m) && rtrim((string)dcValue($nodes,$p),'.')===$id){
                    $candidates=[$m[1].'.Security.KeyPassphrase',$m[1].'.Security.PreSharedKey']; break;
                }
            }
        }
        $path=null; foreach($candidates as $p){if(dcWritable($nodes,$p)){$path=$p;break;}}
        if(!$path) dcReply(['success'=>false,'message'=>'Senha desta interface não está liberada para escrita.'],422);
        $params[]=[$path,$pwd,'xsd:string'];
    }
    if(!$params) dcReply(['success'=>false,'message'=>'Nenhuma alteração informada.'],422);
    $r=$g->setParameterValues($deviceId,$params,5000);
    dcReply(['success'=>!empty($r['success']),'queued'=>($r['http_code']??0)===202,'message'=>!empty($r['success'])?'Alteração Wi-Fi enviada ao equipamento.':'Falha ao enviar alteração Wi-Fi.']);
}
if($action==='update_account'){
    if(!dcCanAdmin()) dcReply(['success'=>false,'message'=>'Sem permissão para alterar credenciais administrativas.'],403);
    $id=(string)($body['account_id']??''); $row=dcFindAccount(dcAccounts($nodes),$id);
    if(!$row) dcReply(['success'=>false,'message'=>'Conta não localizada. Atualize a leitura.'],409);
    $params=[];
    if(array_key_exists('username',$body) && (string)$body['username']!==''){
        $v=(string)$body['username']; if(strlen($v)>64||preg_match('/[\x00-\x1f\x7f]/',$v)) dcReply(['success'=>false,'message'=>'Usuário inválido.'],422);
        $candidates=[$id.'.Username',$id.'.UserName']; $path=null; foreach($candidates as $p){if(dcWritable($nodes,$p)){$path=$p;break;}}
        if(!$path) dcReply(['success'=>false,'message'=>'Usuário desta conta não está liberado para escrita.'],422);
        $params[]=[$path,$v,'xsd:string'];
    }
    if(array_key_exists('password',$body) && (string)$body['password']!==''){
        $v=(string)$body['password']; if(strlen($v)<8||strlen($v)>64||preg_match('/[\x00-\x1f\x7f]/',$v)) dcReply(['success'=>false,'message'=>'Senha administrativa deve ter 8 a 64 bytes.'],422);
        $candidates=[$id.'.Password',$id.'.ConfigPassword']; $path=null; foreach($candidates as $p){if(dcWritable($nodes,$p)){$path=$p;break;}}
        if(!$path) dcReply(['success'=>false,'message'=>'Senha desta conta não está liberada para escrita.'],422);
        $params[]=[$path,$v,'xsd:string'];
    }
    if(!$params) dcReply(['success'=>false,'message'=>'Nenhuma alteração informada.'],422);
    $r=$g->setParameterValues($deviceId,$params,5000);
    dcReply(['success'=>!empty($r['success']),'queued'=>($r['http_code']??0)===202,'message'=>!empty($r['success'])?'Alteração de credenciais enviada ao equipamento.':'Falha ao enviar alteração de credenciais.']);
}
dcReply(['success'=>false,'message'=>'Ação inválida.'],400);
