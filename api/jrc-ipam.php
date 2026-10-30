<?php
/**
 * JRC_IPAM_V1 — cadastro enxuto de redes e IPs no ACS.
 * Estado: /var/lib/jrconect-acs/ipam.json
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();

function jrcIpamFile(): string
{
    $env = getenv('JRC_IPAM_FILE');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return '/var/lib/jrconect-acs/ipam.json';
}

function jrcIpamEmpty(): array
{
    return ['networks' => [], 'addresses' => [], 'history' => []];
}

function jrcIpamRead($fp): array
{
    rewind($fp);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        return jrcIpamEmpty();
    }
    foreach (['networks', 'addresses', 'history'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }
    return $data;
}

function jrcIpamOpen()
{
    $file = jrcIpamFile();
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        jsonResponse(['success' => false, 'message' => 'Não foi possível guardar o cadastro de IPs.'], 500);
    }
    $fp = fopen($file, 'c+');
    if ($fp === false) {
        jsonResponse(['success' => false, 'message' => 'Não foi possível guardar o cadastro de IPs.'], 500);
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        jsonResponse(['success' => false, 'message' => 'Não foi possível guardar o cadastro de IPs.'], 500);
    }
    return $fp;
}

function jrcIpamWrite($fp, array $data): void
{
    $data['history'] = array_slice(array_values($data['history']), 0, 300);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
}

function jrcIpamClip($value, int $max): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    if (strlen($text) > $max) {
        $text = substr($text, 0, $max);
    }
    return $text;
}

function jrcIpamUser(): string
{
    $name = jrcIpamClip($_SESSION['username'] ?? ($_SESSION['user_name'] ?? 'operador'), 80);
    return $name !== '' ? $name : 'operador';
}

function jrcIpamLong(string $ip): ?int
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return null;
    }
    $long = ip2long($ip);
    if ($long === false) {
        return null;
    }
    return (int) sprintf('%u', $long);
}

function jrcIpamIp(int $long): string
{
    $signed = $long > 2147483647 ? $long - 4294967296 : $long;
    $text = long2ip($signed);
    return $text === false ? '0.0.0.0' : $text;
}

function jrcIpamCidr(string $cidr): ?array
{
    if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/', trim($cidr), $match)) {
        return null;
    }
    $ip = jrcIpamLong($match[1]);
    $prefix = (int) $match[2];
    if ($ip === null || $prefix < 16 || $prefix > 30) {
        return null;
    }
    $hostBits = 32 - $prefix;
    $mask = (int) (4294967295 ^ ((1 << $hostBits) - 1));
    $network = $ip & $mask;
    $broadcast = $network | (4294967295 ^ $mask);
    $size = $broadcast - $network + 1;
    return [
        'cidr' => jrcIpamIp($network) . '/' . $prefix,
        'network' => $network,
        'broadcast' => $broadcast,
        'prefix' => $prefix,
        'mask' => jrcIpamIp($mask),
        'first' => $network + 1,
        'last' => $broadcast - 1,
        'gateway' => jrcIpamIp($network + 1),
        'first_ip' => jrcIpamIp($network + 1),
        'last_ip' => jrcIpamIp($broadcast - 1),
        'broadcast_ip' => jrcIpamIp($broadcast),
        'usable' => max(0, $size - 2),
        'size' => $size,
    ];
}

function jrcIpamInside(array $block, int $ip): bool
{
    return $ip >= $block['first'] && $ip <= $block['last'];
}

function jrcIpamOverlap(array $left, array $right): bool
{
    return $left['network'] <= $right['broadcast'] && $right['network'] <= $left['broadcast'];
}

function jrcIpamNote(array &$data, string $text): void
{
    array_unshift($data['history'], [
        'at' => time(),
        'user' => jrcIpamUser(),
        'text' => jrcIpamClip($text, 180),
    ]);
}

function jrcIpamStatus(string $value): string
{
    $text = strtolower(trim($value));
    $text = str_replace(['á', 'é'], ['a', 'e'], $text);
    if (in_array($text, ['uso', 'em uso', 'usado', 'ocupado', 'used'], true)) {
        return 'uso';
    }
    if (in_array($text, ['reservado', 'reserva', 'reserved'], true)) {
        return 'reservado';
    }
    if (in_array($text, ['livre', 'free', 'vazio', ''], true)) {
        return 'livre';
    }
    return '';
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
    $fp = jrcIpamOpen();
    $data = jrcIpamRead($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    jsonResponse(['success' => true] + $data);
}

if ($action === 'save-network') {
    $block = jrcIpamCidr((string) ($payload['cidr'] ?? ''));
    if ($block === null) {
        jsonResponse(['success' => false, 'message' => 'Informe uma rede IPv4 de /16 a /30. Exemplo: 10.0.1.0/24.'], 400);
    }
    $description = jrcIpamClip($payload['description'] ?? '', 80);
    if ($description === '') {
        jsonResponse(['success' => false, 'message' => 'Dê um nome para a rede.'], 400);
    }
    $location = jrcIpamClip($payload['location'] ?? '', 80);
    $kind = jrcIpamClip($payload['kind'] ?? '', 40);
    $gatewayText = trim((string) ($payload['gateway'] ?? ''));
    $gateway = $gatewayText === '' ? $block['first'] : jrcIpamLong($gatewayText);
    if ($gateway === null || !jrcIpamInside($block, $gateway)) {
        jsonResponse(['success' => false, 'message' => 'O gateway precisa estar dentro da rede.'], 400);
    }
    $active = !isset($payload['active']) || $payload['active'] === true || $payload['active'] === 1 || $payload['active'] === '1';
    $id = trim((string) ($payload['id'] ?? ''));
    if ($id !== '' && !preg_match('/^net_[a-f0-9]{12}$/', $id)) {
        jsonResponse(['success' => false, 'message' => 'Rede inválida.'], 400);
    }
    $fp = jrcIpamOpen();
    $data = jrcIpamRead($fp);
    foreach ($data['networks'] as $network) {
        if (!is_array($network) || (string) ($network['id'] ?? '') === $id) {
            continue;
        }
        $other = jrcIpamCidr((string) ($network['cidr'] ?? ''));
        if ($other && jrcIpamOverlap($block, $other)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            jsonResponse(['success' => false, 'message' => 'Essa rede encosta em ' . $other['cidr'] . '.'], 400);
        }
    }
    $row = [
        'id' => $id !== '' ? $id : ('net_' . bin2hex(random_bytes(6))),
        'cidr' => $block['cidr'],
        'description' => $description,
        'location' => $location,
        'kind' => $kind,
        'gateway' => jrcIpamIp($gateway),
        'active' => $active,
        'updated_at' => time(),
    ];
    $found = false;
    foreach ($data['networks'] as $index => $network) {
        if (!is_array($network) || (string) ($network['id'] ?? '') !== $row['id']) {
            continue;
        }
        if (!preg_match('/^net_[a-f0-9]{12}$/', $row['id'])) {
            flock($fp, LOCK_UN);
            fclose($fp);
            jsonResponse(['success' => false, 'message' => 'Rede inválida.'], 400);
        }
        $row['created_at'] = (int) ($network['created_at'] ?? time());
        $data['networks'][$index] = $row;
        $kept = [];
        foreach ($data['addresses'] as $address) {
            if (!is_array($address) || (string) ($address['network_id'] ?? '') !== $row['id']) {
                $kept[] = $address;
                continue;
            }
            $ip = jrcIpamLong((string) ($address['ip'] ?? ''));
            if ($ip !== null && jrcIpamInside($block, $ip)) {
                $kept[] = $address;
            }
        }
        $data['addresses'] = $kept;
        $found = true;
        jrcIpamNote($data, 'Atualizou a rede ' . $row['cidr']);
        break;
    }
    if (!$found) {
        if (count($data['networks']) >= 200) {
            flock($fp, LOCK_UN);
            fclose($fp);
            jsonResponse(['success' => false, 'message' => 'O cadastro chegou a 200 redes.'], 400);
        }
        $row['created_at'] = time();
        $data['networks'][] = $row;
        jrcIpamNote($data, 'Cadastrou a rede ' . $row['cidr']);
    }
    jrcIpamWrite($fp, $data);
    flock($fp, LOCK_UN);
    fclose($fp);
    jsonResponse(['success' => true, 'network' => $row]);
}

if ($action === 'delete-network') {
    $id = trim((string) ($payload['id'] ?? ''));
    if (!preg_match('/^net_[a-f0-9]{12}$/', $id)) {
        jsonResponse(['success' => false, 'message' => 'Rede inválida.'], 400);
    }
    $fp = jrcIpamOpen();
    $data = jrcIpamRead($fp);
    $cidr = '';
    $networks = [];
    foreach ($data['networks'] as $network) {
        if (is_array($network) && (string) ($network['id'] ?? '') === $id) {
            $cidr = (string) ($network['cidr'] ?? $id);
            continue;
        }
        $networks[] = $network;
    }
    $data['networks'] = $networks;
    $data['addresses'] = array_values(array_filter($data['addresses'], static function ($address) use ($id) {
        return !is_array($address) || (string) ($address['network_id'] ?? '') !== $id;
    }));
    if ($cidr !== '') {
        jrcIpamNote($data, 'Excluiu a rede ' . $cidr);
    }
    jrcIpamWrite($fp, $data);
    flock($fp, LOCK_UN);
    fclose($fp);
    jsonResponse(['success' => true]);
}

if ($action === 'save-address' || $action === 'import') {
    $networkId = trim((string) ($payload['network_id'] ?? ''));
    $rows = $action === 'import' ? ($payload['rows'] ?? null) : [[
        'ip' => $payload['ip'] ?? '',
        'hostname' => $payload['hostname'] ?? '',
        'description' => $payload['description'] ?? '',
        'status' => $payload['status'] ?? 'uso',
        'id' => $payload['id'] ?? '',
    ]];
    if (!preg_match('/^net_[a-f0-9]{12}$/', $networkId) || !is_array($rows)) {
        jsonResponse(['success' => false, 'message' => 'Escolha a rede antes de lançar o IP.'], 400);
    }
    if (count($rows) > 2000) {
        jsonResponse(['success' => false, 'message' => 'O CSV passou de 2000 linhas.'], 400);
    }
    $fp = jrcIpamOpen();
    $data = jrcIpamRead($fp);
    $network = null;
    foreach ($data['networks'] as $item) {
        if (is_array($item) && (string) ($item['id'] ?? '') === $networkId) {
            $network = $item;
            break;
        }
    }
    $block = $network ? jrcIpamCidr((string) ($network['cidr'] ?? '')) : null;
    if ($block === null) {
        flock($fp, LOCK_UN);
        fclose($fp);
        jsonResponse(['success' => false, 'message' => 'Rede não encontrada.'], 404);
    }
    $saved = 0;
    $skipped = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ip = jrcIpamLong(trim((string) ($row['ip'] ?? '')));
        if ($ip === null || !jrcIpamInside($block, $ip)) {
            $skipped[] = trim((string) ($row['ip'] ?? '')) . ' fora da rede';
            continue;
        }
        $status = jrcIpamStatus((string) ($row['status'] ?? 'uso'));
        if ($status === '') {
            $skipped[] = jrcIpamIp($ip) . ' com status desconhecido';
            continue;
        }
        $ipText = jrcIpamIp($ip);
        $kept = [];
        foreach ($data['addresses'] as $address) {
            if (!is_array($address) || (string) ($address['network_id'] ?? '') !== $networkId || (string) ($address['ip'] ?? '') !== $ipText) {
                $kept[] = $address;
            }
        }
        $data['addresses'] = $kept;
        if ($status !== 'livre') {
            if (count($data['addresses']) >= 20000) {
                $skipped[] = $ipText . ' acima do limite';
                continue;
            }
            $data['addresses'][] = [
                'id' => 'ip_' . bin2hex(random_bytes(6)),
                'network_id' => $networkId,
                'ip' => $ipText,
                'status' => $status,
                'hostname' => jrcIpamClip($row['hostname'] ?? '', 80),
                'description' => jrcIpamClip($row['description'] ?? '', 120),
                'updated_at' => time(),
            ];
        }
        $saved++;
    }
    if ($saved === 0) {
        flock($fp, LOCK_UN);
        fclose($fp);
        jsonResponse(['success' => false, 'message' => $skipped[0] ?? 'Nenhum IP válido.', 'skipped' => array_slice($skipped, 0, 20)], 400);
    }
    $shown = trim((string) ($rows[0]['ip'] ?? ''));
    $label = $action === 'import'
        ? ('Importou ' . $saved . ' IP(s) em ' . $block['cidr'])
        : ('Lançou ' . $shown . ' em ' . $block['cidr']);
    jrcIpamNote($data, $label);
    jrcIpamWrite($fp, $data);
    flock($fp, LOCK_UN);
    fclose($fp);
    jsonResponse(['success' => true, 'saved' => $saved, 'skipped' => array_slice($skipped, 0, 20)]);
}

if ($action === 'delete-address') {
    $id = trim((string) ($payload['id'] ?? ''));
    if (!preg_match('/^ip_[a-f0-9]{12}$/', $id)) {
        jsonResponse(['success' => false, 'message' => 'IP inválido.'], 400);
    }
    $fp = jrcIpamOpen();
    $data = jrcIpamRead($fp);
    $label = $id;
    $addresses = [];
    foreach ($data['addresses'] as $address) {
        if (is_array($address) && (string) ($address['id'] ?? '') === $id) {
            $label = (string) ($address['ip'] ?? $id);
            continue;
        }
        $addresses[] = $address;
    }
    $data['addresses'] = $addresses;
    jrcIpamNote($data, 'Liberou o IP ' . $label);
    jrcIpamWrite($fp, $data);
    flock($fp, LOCK_UN);
    fclose($fp);
    jsonResponse(['success' => true]);
}

jsonResponse(['success' => false, 'message' => 'Ação inválida.'], 400);
