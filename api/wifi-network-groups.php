<?php
declare(strict_types=1);

/** Identification only: this endpoint has no task, credential or write actions. */
require_once __DIR__ . '/../lib/WifiNetworkGroups.php';
function wngReply(array $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
try {
    require_once __DIR__ . '/../config/config.php';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        wngReply(['success'=>false,'message'=>'Este recurso permite somente consulta.'], 405);
    }
    $uid = $_SESSION['user_id'] ?? null;
    if (!is_scalar($uid) || !ctype_digit((string)$uid) || (int)$uid < 1) wngReply(['success'=>false,'message'=>'Entre novamente no painel.'],401);
    $db = getDBConnection();
    $stmt = $db->prepare('SELECT role, active FROM users WHERE id = ? LIMIT 1');
    $uid = (int)$uid; $stmt->bind_param('i',$uid); $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user || (int)$user['active'] !== 1 || !in_array(strtolower(trim((string)$user['role'])), ['admin','noc','atendimento'], true)) {
        wngReply(['success'=>false,'message'=>'Perfil sem acesso ao gerenciamento de redes.'],403);
    }
    $id = $_GET['device_id'] ?? null;
    if (!is_string($id) || $id === '' || strlen($id)>512 || preg_match('/[\x00-\x1f\x7f]/',$id) || !preg_match('//u',$id)) {
        wngReply(['success'=>false,'message'=>'Equipamento invalido.'],400);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $r = $db->query('SELECT host,port,username,password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1');
    $c = $r ? $r->fetch_assoc() : null;
    if (!$c) wngReply(['success'=>false,'message'=>'Canal de gerenciamento nao configurado.'],503);
    $host = trim((string)$c['host']);
    $base = preg_match('~^https?://~i',$host) ? $host : 'http://' . $host;
    $u = parse_url($base);
    if (!$u || !in_array($u['scheme'] ?? '',['http','https'],true) || empty($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])) throw new RuntimeException('invalid configuration');
    $port = $u['port'] ?? (int)($c['port'] ?: 7557);
    if ($port<1 || $port>65535) throw new RuntimeException('invalid configuration');
    $base = $u['scheme'] . '://' . $u['host'] . ':' . $port . rtrim($u['path'] ?? '', '/');
    $url = $base . '/devices/?query=' . rawurlencode(json_encode(['_id'=>$id], JSON_THROW_ON_ERROR));
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>12,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    if ((string)$c['username'] !== '') curl_setopt($ch,CURLOPT_USERPWD,(string)$c['username'].':'.(string)$c['password']);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw === false || $status<200 || $status>=300) wngReply(['success'=>false,'message'=>'Sem resposta da consulta de capacidades. Tente atualizar a leitura.'],502);
    $devices = json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($devices[0] ?? null)) wngReply(['success'=>false,'message'=>'Equipamento nao encontrado no gerenciamento.'],404);
    $data = (new WifiNetworkGroups($devices[0]))->inspect();
    wngReply(['success'=>true,'observed_at'=>gmdate('c'),'data'=>$data]);
} catch (Throwable $error) {
    // Never echo upstream content, secrets, SQL or credential-bearing URLs.
    wngReply(['success'=>false,'message'=>'Nao foi possivel identificar os recursos de rede. Verifique a integracao.'],502);
}
