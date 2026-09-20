<?php
declare(strict_types=1);

/* CPE controls: explicit interface selection; never infer permission from username. */
use App\GenieACS;

function dcReply(array $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function dcFail(string $message, int $status = 422): never { throw new RuntimeException($message, $status); }
function dcCapabilities(string $role): array {
    $role = strtolower(trim($role));
    return ['role'=>$role, 'wifi'=>in_array($role,['admin','noc','atendimento'],true), 'admin'=>in_array($role,['admin','noc'],true)];
}
function dcAuth(): array {
    $id = $_SESSION['user_id'] ?? null;
    if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id < 1) dcFail('Sessão expirada. Entre novamente.',401);
    $db = getDBConnection();
    $stmt = $db->prepare('SELECT role, active FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) dcFail('Não foi possível verificar as permissões.',503);
    $uid = (int)$id; $stmt->bind_param('i',$uid); $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user || (int)$user['active'] !== 1) dcFail('Usuário inativo ou não autorizado.',403);
    // Database is authoritative, including for sessions created before role changes.
    return dcCapabilities((string)$user['role']);
}
function dcBool(mixed $v): ?bool {
    if (in_array($v,[true,1,'1','true','True'],true)) return true;
    if (in_array($v,[false,0,'0','false','False'],true)) return false;
    return null;
}
function dcReadable(mixed $v): bool {
    return is_scalar($v) && !is_bool($v) && trim((string)$v)!==''
        && !in_array(strtoupper(trim((string)$v)),['N/A','N/D','NULL','[OCULTO]'],true)
        && !preg_match('/^[*\x{2022}]+$/u',(string)$v);
}
function dcFlatten(array $device): array {
    $nodes=[];
    $walk=function(array $node,string $path,int $depth) use (&$walk,&$nodes): void {
        if ($depth>32 || count($nodes)>=40000) return;
        $nodes[$path]=$node;
        foreach($node as $key=>$child) if(!str_starts_with((string)$key,'_') && is_array($child)) $walk($child,$path.'.'.$key,$depth+1);
    };
    foreach(['Device','InternetGatewayDevice','VirtualParameters'] as $root) if(isset($device[$root]) && is_array($device[$root])) $walk($device[$root],$root,0);
    return $nodes;
}
function dcValue(array $nodes,string $path): mixed { return $nodes[$path]['_value'] ?? null; }
function dcRoots(array $nodes,string $pattern): array {
    $out=[]; foreach(array_keys($nodes) as $p) if(preg_match($pattern,$p,$m)) $out[$m[1]]=true;
    $paths=array_keys($out); sort($paths,SORT_NATURAL); return $paths;
}
function dcField(array $nodes,array $paths,bool $readonly=false): array {
    $read=null; $write=null;
    foreach($paths as $p) {
        if(!isset($nodes[$p])) continue;
        if($read===null || (!dcReadable(dcValue($nodes,$read)) && dcReadable(dcValue($nodes,$p)))) $read=$p;
        if(!$readonly && $write===null && dcBool($nodes[$p]['_writable']??null)===true) $write=$p;
    }
    return ['read'=>$read,'write'=>$write];
}
function dcRow(array $nodes,string $id,array $fields,array $extra=[]): array {
    $row=['id'=>$id,'_fields'=>$fields]+$extra;
    $revision=[$id];
    foreach($fields as $key=>$f) {
        $node=$f['read']!==null ? $nodes[$f['read']] : [];
        $v=$node['_value']??null; $available=dcReadable($v);
        $row[$key]=$key==='password' ? null : ($available ? $v : null);
        $row[$key.'_writable']=$f['write']!==null;
        $row[$key.'_state']=$f['read']===null ? 'absent' : (!array_key_exists('_value',$node) ? 'not_collected' : ($available ? 'available' : ($key==='password' ? 'concealed' : 'empty')));
        $row[$key.'_reported_at']=$node['_timestamp']??null;
        // Password text never participates in public revisions or GET output.
        $revision[]=[$f['read'],$f['write'],$node['_timestamp']??null,$key==='password'?null:$v];
    }
    $row['revision']=hash('sha256',json_encode($revision,JSON_INVALID_UTF8_SUBSTITUTE));
    return $row;
}
/**
 * Resolve a band from the reported radio/configuration, never from SSID suffix
 * or instance number. 802.11n/ax/be and a channel number alone are ambiguous.
 */
function dcNormalizeBand(mixed $value): ?string {
    if(!is_scalar($value) || is_bool($value)) return null;
    $text=strtolower(preg_replace('/\s+/','',str_replace(',','.',trim((string)$value))));
    return match($text) {
        '2.4','2.4g','2.4ghz' => '2.4',
        '5','5g','5ghz','5.8','5.8g','5.8ghz' => '5',
        '6','6g','6ghz','6g','6' => '6',
        default => null
    };
}
function dcBandLabel(mixed $band,int $index): string {
    return match(dcNormalizeBand($band)) {
        '2.4' => 'Wi-Fi 2,4 GHz',
        '5' => 'Wi-Fi 5 GHz (5,8)',
        '6' => 'Wi-Fi 6 GHz',
        default => 'Banda não informada'
    };
}
/** Parse explicit operating standards, including vendor spellings such as bgn.
 * Reject unknown text as a whole; ax/n/be alone never determine the band.
 */
function dcOperatingTokens(string $raw): array {
    $text=preg_replace('/(?:ieee\s*)?802\.11/i','',strtolower(trim($raw)));
    $words=preg_split('/[\s,\/+_-]+/',$text,-1,PREG_SPLIT_NO_EMPTY);
    if(!$words) return [];
    $tokens=[];
    foreach($words as $word) {
        preg_match_all('/ac|ax|be|[abgn]/',$word,$matches);
        if(implode('',$matches[0])!==$word) return [];
        $tokens=array_merge($tokens,$matches[0]);
    }
    return array_values(array_unique($tokens));
}
function dcWifiBand(array $nodes,?string $base,bool $legacy=false): array {
    $unknown=['band'=>null,'band_source'=>null,'band_conflict'=>false];
    if(!$base) return $unknown;
    $observed=[];
    foreach(['OperatingFrequencyBand','X_FH_OperatingFrequencyBand','X_HW_FrequencyBand'] as $field) {
        $band=dcNormalizeBand(dcValue($nodes,$base.'.'.$field));
        if($band!==null) $observed[$band]=$field;
    }
    if(count($observed)>1) return array_replace($unknown,['band_conflict'=>true]);
    if($observed) return ['band'=>(string)array_key_first($observed),'band_source'=>reset($observed),'band_conflict'=>false];
    // A single supported band is enough. A multi-band capability is NOT the current band.
    $supported=dcValue($nodes,$base.'.SupportedFrequencyBands');
    if(is_string($supported)) {
        $parts=array_values(array_filter(array_map('trim',explode(',',$supported)),fn($p)=>$p!==''));
        if(count($parts)===1 && ($band=dcNormalizeBand($parts[0]))!==null)
            return ['band'=>$band,'band_source'=>'SupportedFrequencyBands','band_conflict'=>false];
    }
    $field=$legacy?'Standard':'OperatingStandards';
    $raw=dcValue($nodes,$base.'.'.$field);
    if(is_string($raw)) {
        $tokens=dcOperatingTokens($raw);
        $has24=(bool)array_intersect($tokens,['b','g']);
        $has5=(bool)array_intersect($tokens,['a','ac']);
        if($has24 xor $has5) return ['band'=>$has24?'2.4':'5','band_source'=>$field,'band_conflict'=>false];
    }
    return $unknown;
}
/** Full discovery is explicit and read-only; selected refreshes remain narrow. */
function dcWifiRefreshRoots(array $nodes): array {
    $roots=[];
    if(isset($nodes['InternetGatewayDevice'])) $roots[]='InternetGatewayDevice.LANDevice';
    if(isset($nodes['Device'])) $roots[]='Device.WiFi';
    return $roots;
}
function dcWifi(array $nodes): array {
    $rows=[]; $n=0;
    foreach(dcRoots($nodes,'~^(InternetGatewayDevice\.LANDevice\.\d+\.WLANConfiguration\.\d+)(?:\.|$)~') as $base) {
        $fields=['ssid'=>dcField($nodes,[$base.'.SSID']), 'password'=>dcField($nodes,[$base.'.KeyPassphrase',$base.'.PreSharedKey.1.KeyPassphrase',$base.'.PreSharedKey.1.PreSharedKey'])];
        $band=dcWifiBand($nodes,$base,true);
        preg_match('/LANDevice\.(\d+)\.WLANConfiguration\.(\d+)$/',$base,$ids);
        $rows[]=dcRow($nodes,$base,$fields,$band+[
            'standard'=>'TR-098','label'=>dcBandLabel($band['band'],++$n),
            'instance_label'=>'LAN '.$ids[1].' / SSID '.$ids[2],
            'radio_id'=>null,
            'enabled'=>dcBool(dcValue($nodes,$base.'.Enable')),'channel'=>dcValue($nodes,$base.'.Channel'),
            'auto_channel'=>dcBool(dcValue($nodes,$base.'.AutoChannelEnable')),
            'security'=>dcValue($nodes,$base.'.BeaconType')??dcValue($nodes,$base.'.BasicEncryptionModes'),'_refresh'=>[$base]]);
    }
    $aps=dcRoots($nodes,'~^(Device\.WiFi\.AccessPoint\.\d+)(?:\.|$)~');
    foreach(dcRoots($nodes,'~^(Device\.WiFi\.SSID\.\d+)(?:\.|$)~') as $base) {
        $matches=array_values(array_filter($aps,fn($p)=>rtrim(trim((string)dcValue($nodes,$p.'.SSIDReference')),'.')===$base));
        $ap=count($matches)===1?$matches[0]:null;
        $layers=array_unique(array_map(fn($p)=>rtrim(trim($p),'.'),explode(',',(string)dcValue($nodes,$base.'.LowerLayers'))));
        $radios=array_values(array_filter($layers,fn($p)=>preg_match('~^Device\.WiFi\.Radio\.\d+$~',$p)&&isset($nodes[$p])));
        $radio=count($radios)===1?$radios[0]:null;
        $band=dcWifiBand($nodes,$radio);
        $fields=['ssid'=>dcField($nodes,[$base.'.SSID']), 'password'=>dcField($nodes,$ap?[$ap.'.Security.KeyPassphrase',$ap.'.Security.PreSharedKey']:[])];
        $rows[]=dcRow($nodes,$base,$fields,$band+[
            'standard'=>'TR-181','label'=>dcBandLabel($band['band'],++$n),
            'instance_label'=>'SSID '.substr($base,strrpos($base,'.')+1).' (TR-181)',
            'radio_id'=>$radio,
            'enabled'=>dcBool(dcValue($nodes,($ap??$base).'.Enable')), 'channel'=>$radio?dcValue($nodes,$radio.'.Channel'):null,
            'auto_channel'=>$radio?dcBool(dcValue($nodes,$radio.'.AutoChannelEnable')):null, 'security'=>$ap?dcValue($nodes,$ap.'.Security.ModeEnabled'):null,
            '_refresh'=>array_values(array_filter([$base,$ap,$radio]))]);
    }
    // Do not merge distinct interfaces just because the SSID or band is equal.
    return $rows;
}
/** Only parameter paths and metadata. No SSIDs, passwords, IPs or serial numbers. */
function dcWifiDiagnostic(array $nodes,array $wifi): array {
    return ['schema'=>'wifi-edit-diagnostic-v1','interfaces'=>array_map(function($row) use($nodes) {
        $basis=$row['standard']==='TR-098'?$row['id']:($row['radio_id']??null);
        $observed=[];
        if($basis) foreach(['OperatingFrequencyBand','X_FH_OperatingFrequencyBand','X_HW_FrequencyBand','SupportedFrequencyBands','Standard','OperatingStandards'] as $field) {
            $value=dcValue($nodes,$basis.'.'.$field);
            if(is_string($value) && strlen($value)<=96 && preg_match('/^[A-Za-z0-9., \/+_-]+$/D',$value)) $observed[$field]=$value;
        }
        $fields=[];
        foreach($row['_fields'] as $name=>$field) {
            $paths=array_values(array_unique(array_filter([$field['read'],$field['write']],fn($p)=>is_string($p))));
            $fields[$name]=['state'=>$row[$name.'_state'],'writable'=>$row[$name.'_writable'],
                'parameters'=>array_map(fn($p)=>['path'=>$p,'writable'=>dcBool($nodes[$p]['_writable']??null)],$paths)];
        }
        return ['interface_id'=>$row['id'],'standard'=>$row['standard'],'band'=>$row['band'],
            'band_source'=>$row['band_source'],'band_conflict'=>$row['band_conflict'],
            'radio_id'=>$row['radio_id'],'band_metadata'=>$observed,'fields'=>$fields];
    },$wifi)];
}
function dcAccounts(array $nodes): array {
    $groups=[];
    foreach(dcRoots($nodes,'~^((?:Device|InternetGatewayDevice)\.Users\.User\.\d+)(?:\.|$)~') as $base)
        $groups[]=['id'=>$base,'label'=>'Conta do equipamento','user'=>[$base.'.Username'],'pass'=>[$base.'.Password']];
    foreach(['Device','InternetGatewayDevice'] as $root) {
        $base=$root.'.DeviceInfo.X_CT-COM_TeleComAccount';
        if(isset($nodes[$base])) $groups[]=['id'=>$base,'label'=>'Conta Telecom','user'=>[$base.'.Username',$base.'.UserName'],'pass'=>[$base.'.Password']];
        $base=$root.'.LANConfigSecurity';
        if(isset($nodes[$base.'.ConfigPassword'])) $groups[]=['id'=>$base,'label'=>'Acesso local','user'=>[],'pass'=>[$base.'.ConfigPassword']];
    }
    if(!$groups && (isset($nodes['VirtualParameters.superAdmin'])||isset($nodes['VirtualParameters.superPassword'])))
        $groups[]=['id'=>'VirtualParameters','label'=>'Administrador (parâmetro virtual)','user'=>['VirtualParameters.superAdmin'],'pass'=>['VirtualParameters.superPassword'],'readonly'=>true];
    $out=[];
    foreach($groups as $g) $out[]=dcRow($nodes,$g['id'],['username'=>dcField($nodes,$g['user'],$g['readonly']??false),'password'=>dcField($nodes,$g['pass'],$g['readonly']??false)],
        ['label'=>$g['label'],'_refresh'=>[$g['id']]]);
    return $out;
}
function dcPublic(array $rows): array {
    return array_map(function($row){unset($row['_fields'],$row['_refresh']); return $row;},$rows);
}
function dcSelect(array $rows,string $id): array {
    foreach($rows as $row) if($row['id']===$id) return $row;
    dcFail('Interface ou conta não encontrada. Atualize a leitura.',409);
}
function dcString(array $data,string $key,int $max=512): string {
    $value=$data[$key]??null;
    if(!is_string($value)||$value===''||strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value)||!preg_match('//u',$value)) dcFail('Campo inválido: '.$key.'.',400);
    return $value;
}
function dcParameters(array $row,array $body,string $kind): array {
    if($kind==='wifi' && array_key_exists('wifi_band',$body)) {
        $expected=$body['wifi_band'];
        $actual=$row['band']??'unknown';
        if(!is_string($expected) || !in_array($expected,['2.4','5','6','unknown'],true) || $expected!==$actual)
            dcFail('A banda selecionada não corresponde à interface. Atualize a leitura antes de salvar.',409);
    }
    $out=[]; $keys=$kind==='wifi'?['ssid','password']:['username','password'];
    foreach($keys as $key) {
        if(!array_key_exists($key,$body)) continue;
        if($key==='password' && $body[$key]==='') continue; // Blank means keep, never clear a password.
        $value=dcString($body,$key,$key==='ssid'?32:64);
        $path=$row['_fields'][$key]['write']??null;
        if(!$path) dcFail('O equipamento não liberou escrita para '.$key.'.');
        if($key==='password') {
            if(strlen($value)<8 || ($kind==='wifi' && (strlen($value)>63 || !preg_match('/^[\x20-\x7e]+$/D',$value)))) dcFail('Senha Wi-Fi: 8–63 caracteres ASCII; senha administrativa: 8–64 bytes.');
        }
        $out[]=[$path,$value,'xsd:string'];
    }
    if(!$out) dcFail('Nenhuma alteração informada.');
    return $out;
}
function dcRun(): never {
    $permissions=dcAuth();
    if(!$permissions['wifi']) dcFail('Perfil sem permissão para gerenciar equipamentos.',403);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true)) dcFail('Método não permitido.',405);
    if(empty($_SESSION['device_control_csrf'])) $_SESSION['device_control_csrf']=bin2hex(random_bytes(32));
    $token=$_SESSION['device_control_csrf']; $body=[];
    if($method==='POST') {
        $given=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
        if(!is_string($given)||!hash_equals($token,$given)) dcFail('Token de segurança expirado. Atualize a leitura.',403);
        $raw=file_get_contents('php://input',false,null,0,16385);
        if(strlen($raw?:'')>16384) dcFail('Requisição muito grande.',413);
        $body=json_decode($raw?:'',true);
        if(!is_array($body)) dcFail('JSON inválido.',400);
    }
    $deviceId=dcString($method==='GET'?$_GET:$body,'device_id');
    $action=$method==='GET'?'read':dcString($body,'action',32);
    if(!in_array($action,['read','refresh','wifi_diagnostics','reveal_secret','update_wifi','update_account'],true)) dcFail('Ação inválida.',400);
    $kind=$action==='update_account'?'account':($action==='update_wifi'?'wifi':($body['kind']??'wifi'));
    if($kind==='admin') $kind='account';
    if(!in_array($kind,['wifi','account'],true)) dcFail('Tipo de controle inválido.',400);
    if($kind==='account' && !$permissions['admin']) dcFail('Somente NOC/Admin pode acessar credenciais do roteador.',403);
    // Release session lock during upstream calls; no broad refresh or password change on GET.
    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
    $db=getDBConnection();
    $r=$db->query('SELECT host,port,username,password FROM genieacs_credentials WHERE is_connected=1 ORDER BY id DESC LIMIT 1');
    $c=$r?$r->fetch_assoc():null;
    if(!$c) dcFail('Canal de gerenciamento não configurado.',503);
    $g=new GenieACS($c['host'],$c['port'],$c['username'],$c['password']);
    $r=$g->getDevice($deviceId);
    if(empty($r['success'])||!is_array($r['data']??null)) dcFail('Não foi possível consultar o equipamento.',502);
    $nodes=dcFlatten($r['data']); $wifi=dcWifi($nodes);
    if($action==='wifi_diagnostics') dcReply(['success'=>true,'diagnostic'=>dcWifiDiagnostic($nodes,$wifi)]);
    $accounts=$permissions['admin']?dcAccounts($nodes):[];
    if($action==='read') dcReply(['success'=>true,'csrf'=>$token,'permissions'=>$permissions,'wifi'=>dcPublic($wifi),'accounts'=>dcPublic($accounts)]);
    $id=$body[$kind==='wifi'?'interface_id':'account_id']??null;
    $row=$id!==null?dcSelect($kind==='wifi'?$wifi:$accounts,dcString($body,$kind==='wifi'?'interface_id':'account_id')):null;
    if($action==='refresh') {
        $scope=$body['scope']??'selection';
        if(!in_array($scope,['selection','all'],true)) dcFail('Escopo de leitura inválido.',400);
        if($scope==='all' && ($kind!=='wifi' || $id!==null)) dcFail('A busca completa exige Wi-Fi sem interface selecionada.',400);
        if($kind==='wifi' && ($scope==='all' || !$row)) {
            // Discover all LANDevice/WiFi instances, including a band missing from cache.
            $roots=dcWifiRefreshRoots($nodes);
        } else {
            $roots=$row['_refresh']??['Device.Users','Device.UserInterface','Device.DeviceInfo','Device.LANConfigSecurity','InternetGatewayDevice.Users','InternetGatewayDevice.UserInterface','InternetGatewayDevice.DeviceInfo','InternetGatewayDevice.LANConfigSecurity','VirtualParameters'];
            $roots=array_values(array_filter(array_unique($roots),fn($p)=>isset($nodes[$p])));
        }
        if(!$roots) dcFail('Nenhum parâmetro compatível foi coletado para atualizar.',409);
        $completed=0; $queued=0; $failed=0;
        foreach(array_slice($roots,0,9) as $p) {
            $task=$g->addRefreshTask($deviceId,$p);
            if(empty($task['success'])) $failed++;
            elseif(($task['http_code']??0)===200) $completed++;
            else $queued++;
        }
        dcReply(['success'=>($completed+$queued)>0,'queued'=>$queued>0,'partial'=>$failed>0,
            'message'=>$queued?'Leitura enfileirada; aguarde nova comunicação.':($failed?'Leitura parcialmente concluída.':'Leitura atualizada no equipamento.')]);
    }
    if(!$row) dcFail('Selecione a rede ou a conta.',400);
    if($action==='reveal_secret') {
        if($row['password_state']!=='available') dcFail('O equipamento não informou a senha atual. Não é possível recuperá-la por esta leitura.',409);
        $path=$row['_fields']['password']['read'];
        dcReply(['success'=>true,'password'=>(string)dcValue($nodes,$path),'reported_at'=>$row['password_reported_at']]);
    }
    $revision=dcString($body,'revision',64);
    if(!hash_equals($row['revision'],$revision)) dcFail('Os dados mudaram desde a abertura. Feche o formulário e atualize a leitura.',409);
    $params=dcParameters($row,$body,$kind);
    $task=$g->setParameterValues($deviceId,$params,5000);
    if(empty($task['success'])) dcFail('Envio não confirmado. Confira a leitura antes de repetir a alteração.',502);
    $queued=($task['http_code']??0)!==200;
    dcReply(['success'=>true,'queued'=>$queued,'message'=>$queued?'Alteração enfileirada, ainda não aplicada.':'Comando executado. Atualize a leitura para conferir.']);
}

try {
    require_once __DIR__ . '/../config/config.php';
    dcRun();
} catch (Throwable $error) {
    $status=(int)$error->getCode();
    if($status<400||$status>599) $status=500;
    dcReply(['success'=>false,'message'=>$status===500?'Falha interna ao consultar o controle. Verifique a configuração do servidor.':$error->getMessage()],$status);
}
