<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use App\GenieACS;

if (function_exists('requireLogin')) requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');

function out(array $data, int $status=200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function walkCounters(mixed $node, string $path, array &$rows, int $depth=0): void {
    if (!is_array($node) || $depth > 30 || count($rows) >= 300) return;

    if (array_key_exists('_value', $node)) {
        $leaf = strtolower((string)preg_replace('/^.*\./', '', $path));
        if (
            preg_match('/(?:bytes|octets)(?:sent|received|rx|tx)?$/i', $leaf)
            || preg_match('/(?:sent|received)(?:bytes|octets)$/i', $leaf)
            || preg_match('/(?:rx|tx)(?:bytes|octets)$/i', $leaf)
        ) {
            $value = $node['_value'];
            if (is_numeric($value)) {
                $rows[] = [
                    'path'=>$path,
                    'value'=>(string)$value,
                    'timestamp'=>$node['_timestamp'] ?? null,
                    'type'=>$node['_type'] ?? null,
                ];
            }
        }
    }

    foreach ($node as $key=>$value) {
        if (!is_array($value) || str_starts_with((string)$key, '_')) continue;
        $next = $path === '' ? (string)$key : $path.'.'.$key;
        walkCounters($value, $next, $rows, $depth+1);
    }
}

try {
    $deviceId=trim((string)($_GET['device_id'] ?? ''));
    if ($deviceId==='' || strlen($deviceId)>255) out(['success'=>false,'message'=>'device_id inválido'],400);
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();

    $db=getDBConnection();
    $res=$db->query("SELECT host,port,username,password FROM genieacs_credentials WHERE is_connected=1 ORDER BY id DESC LIMIT 1");
    $cred=$res?$res->fetch_assoc():null;
    if(!$cred) out(['success'=>false,'message'=>'GenieACS não conectado'],503);

    $acs=new GenieACS($cred['host'],$cred['port'],$cred['username'],$cred['password']);

    // Solicita toda a árvore WAN/PPP para descobrir também parâmetros
    // específicos do fabricante que não estavam no cache do GenieACS.
    $refresh=$acs->refreshDeviceDiagnostics($deviceId);
    usleep(500000);

    $dev=$acs->getDevice($deviceId);
    if(empty($dev['success']) || !is_array($dev['data'] ?? null)){
        out(['success'=>false,'message'=>'Equipamento não encontrado no GenieACS'],404);
    }

    $rows=[];
    walkCounters($dev['data'],'',$rows);
    usort($rows, static function(array $a,array $b): int {
        $score=static function(string $p): int {
            $s=0;
            if(stripos($p,'WANPPPConnection')!==false) $s+=100;
            if(stripos($p,'.PPP.')!==false) $s+=90;
            if(stripos($p,'WANIPConnection')!==false) $s+=70;
            if(stripos($p,'WANCommonInterfaceConfig')!==false) $s-=30;
            if(stripos($p,'Stats')!==false) $s+=20;
            return $s;
        };
        return $score($b['path']) <=> $score($a['path']);
    });

    out([
        'success'=>true,
        'source'=>'TR-069 / GenieACS',
        'device'=>[
            'manufacturer'=>$dev['data']['_deviceId']['_Manufacturer'] ?? null,
            'model'=>$dev['data']['_deviceId']['_ProductClass'] ?? null,
        ],
        'refresh'=>[
            'success'=>!empty($refresh['success']),
            'http_code'=>$refresh['http_code'] ?? null,
        ],
        'counter_count'=>count($rows),
        'counters'=>$rows,
    ]);
} catch(Throwable $e) {
    out(['success'=>false,'message'=>'Falha ao mapear contadores TR-069','detail'=>mb_substr($e->getMessage(),0,220)],502);
}
