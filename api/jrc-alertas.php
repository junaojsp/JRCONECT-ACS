<?php
/**
 * JRC_ALERTAS_V1 — reconhecimento dos alertas da frota.
 * Estado: /var/lib/jrconect-acs/alertas.json
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();

const JRC_ALERTAS_FILE = '/var/lib/jrconect-acs/alertas.json';

function jrcAlertasRead($fp)
{
    rewind($fp);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data) || !isset($data['acks']) || !is_array($data['acks'])) {
        return ['acks' => []];
    }
    return $data;
}

function jrcAlertasOpen()
{
    $dir = dirname(JRC_ALERTAS_FILE);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        jsonResponse(['success' => false, 'message' => 'Não foi possível guardar o reconhecimento.'], 500);
    }
    $fp = fopen(JRC_ALERTAS_FILE, 'c+');
    if ($fp === false) {
        jsonResponse(['success' => false, 'message' => 'Não foi possível guardar o reconhecimento.'], 500);
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        jsonResponse(['success' => false, 'message' => 'Não foi possível guardar o reconhecimento.'], 500);
    }
    return $fp;
}

$payload = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $decoded = json_decode(file_get_contents('php://input') ?: '', true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
$action = (string) ($payload['action'] ?? $_GET['action'] ?? 'list');

if ($action === 'list') {
    $fp = jrcAlertasOpen();
    $data = jrcAlertasRead($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    jsonResponse(['success' => true, 'acks' => $data['acks']]);
}

$clip = static function ($value) {
    $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    if (strlen($text) > 240) {
        $text = substr($text, 0, 240);
    }
    return $text;
};

$validId = static function ($id) {
    return (bool) preg_match('/^(offline|rx):[^\s\/\\\\]{1,180}$/', $id);
};

$makeAck = static function ($item) use ($clip) {
    $level = (string) ($item['level'] ?? 'info');
    if (!in_array($level, ['critico', 'aviso', 'info'], true)) {
        $level = 'info';
    }
    return [
        'user' => $clip($_SESSION['username'] ?? 'operador') ?: 'operador',
        'at' => time(),
        'title' => $clip($item['title'] ?? ''),
        'text' => $clip($item['text'] ?? ''),
        'level' => $level,
        'device_id' => $clip($item['device_id'] ?? ''),
        'serial' => $clip($item['serial'] ?? ''),
    ];
};

if ($action === 'ack-many') {
    $items = $payload['items'] ?? null;
    if (!is_array($items)) {
        jsonResponse(['success' => false, 'message' => 'Ação inválida.'], 400);
    }
    if (count($items) > 2000) {
        jsonResponse(['success' => false, 'message' => 'Muitos alertas de uma vez.'], 400);
    }
    $fp = jrcAlertasOpen();
    $data = jrcAlertasRead($fp);
    $saved = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = trim((string) ($item['id'] ?? ''));
        if (!$validId($id)) {
            continue;
        }
        $ack = $makeAck($item);
        $data['acks'][$id] = $ack;
        $saved[$id] = $ack;
    }
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    jsonResponse([
        'success' => true,
        'acks' => $saved === [] ? new stdClass() : $saved,
        'count' => count($saved),
    ]);
}

if ($action !== 'ack') {
    jsonResponse(['success' => false, 'message' => 'Ação inválida.'], 400);
}

$id = trim((string) ($payload['id'] ?? ''));
if (!$validId($id)) {
    jsonResponse(['success' => false, 'message' => 'Alerta inválido.'], 400);
}

$ack = $makeAck($payload);

$fp = jrcAlertasOpen();
$data = jrcAlertasRead($fp);
$data['acks'][$id] = $ack;
rewind($fp);
ftruncate($fp, 0);
fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

jsonResponse(['success' => true, 'ack' => $ack]);
