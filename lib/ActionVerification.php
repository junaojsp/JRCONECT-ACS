<?php
declare(strict_types=1);

function acsVerificationSafePath(string $path): bool
{
    // An allowlist excludes all credentials, including their hashes and read requests.
    return strlen($path) <= 220 && (bool)preg_match('/^(InternetGatewayDevice|Device)\.[A-Za-z0-9_.-]+\.(SSID|Enable|NATEnabled|ConnectionType|BeaconType|ModeEnabled|WPAAuthenticationMode|WPAEncryptionModes|X_CT-COM_VLANID|X_CT-COM_ServiceList|X_CT-COM_LanInterface|DHCPServerEnable|MinAddress|MaxAddress|SubnetMask|IPRouters|DNSServers|DHCPLeaseTime)$/D', $path)
        && !preg_match('/password|passphrase|preshared|credential|secret|username/i', $path);
}

function acsVerificationCanonical($value, string $type): ?string
{
    if ($type === 'xsd:boolean') {
        if (in_array($value, [true, 1, '1', 'true'], true)) return 'true';
        if (in_array($value, [false, 0, '0', 'false'], true)) return 'false';
        return null;
    }
    if (in_array($type, ['xsd:int','xsd:unsignedInt','xsd:long','xsd:unsignedLong'], true)) {
        if (!is_scalar($value) || !preg_match('/^-?\d+$/D', (string)$value)) return null;
        $text = (string)$value; $negative = str_starts_with($text, '-');
        $digits = ltrim(ltrim($text, '-'), '0');
        return ($negative && $digits !== '' ? '-' : '') . ($digits === '' ? '0' : $digits);
    }
    return is_string($value) ? $value : null;
}

function acsVerificationExpected(array $parameters): array
{
    $expected = []; $excluded = 0;
    foreach ($parameters as $key => $parameter) {
        if (is_string($key)) {
            $path = $key; $value = $parameter;
            $type = is_bool($value) ? 'xsd:boolean' : (is_int($value) ? 'xsd:int' : 'xsd:string');
        } else {
            $path = is_array($parameter) ? ($parameter[0] ?? '') : '';
            $value = is_array($parameter) ? ($parameter[1] ?? null) : null;
            $type = is_array($parameter) ? ($parameter[2] ?? (is_bool($value) ? 'xsd:boolean' : (is_int($value) ? 'xsd:int' : 'xsd:string'))) : 'xsd:string';
        }
        if (!is_string($path) || !is_string($type) || !acsVerificationSafePath($path)) { ++$excluded; continue; }
        $canonical = acsVerificationCanonical($value, $type);
        if ($canonical === null || count($expected) >= 16) { ++$excluded; continue; }
        $expected[$path] = ['type' => $type, 'hash' => hash('sha256', $canonical)];
    }
    return ['fields' => $expected, 'excluded' => $excluded];
}

function acsVerificationCompare(array $fields, array $device, int $startMs): array
{
    $matched = 0; $fresh = 0;
    foreach ($fields as $path => $expected) {
        if (!acsVerificationSafePath($path)) continue;
        $node = $device;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) { $node = null; break; }
            $node = $node[$part];
        }
        if (!is_array($node) || !array_key_exists('_value', $node) || !is_string($node['_timestamp'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $node['_timestamp'])) continue;
        try { $timestamp = new DateTimeImmutable($node['_timestamp'], new DateTimeZone('UTC')); }
        catch (Throwable $error) { continue; }
        $ms = (int)$timestamp->format('Uv');
        if ($ms < $startMs || $ms > (int)floor(microtime(true) * 1000) + 5000) continue;
        ++$fresh;
        $canonical = acsVerificationCanonical($node['_value'], $expected['type']);
        if ($canonical !== null && hash_equals($expected['hash'], hash('sha256', $canonical))) ++$matched;
    }
    $total = count($fields);
    $status = $total && $matched === $total ? 'confirmed'
        : ($fresh === $total && $total ? 'different' : 'pending');
    return ['status' => $status, 'matched' => $matched, 'total' => $total];
}

function acsVerificationRequest(array $credentials, string $endpoint, ?array $task = null): array
{
    $ch = curl_init('http://' . $credentials['host'] . ':' . $credentials['port'] . $endpoint);
    if ($ch === false) throw new RuntimeException('NBI unavailable');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>4]);
    if (!empty($credentials['username'])) curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
    if ($task !== null) curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>json_encode($task)]);
    $body = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['http_code'=>$http, 'data'=>is_string($body) ? json_decode($body, true) : null];
}

function acsVerificationStart(?int $id, array $parameters, array $credentials): void
{
    if (!$id) return;
    try {
        $expected = acsVerificationExpected($parameters);
        $json = json_encode($expected['fields'], JSON_THROW_ON_ERROR);
        $excluded = $expected['excluded'];
        $ms = (int)floor(microtime(true)*1000);
        $status = $expected['fields'] ? 'pending' : 'unsupported';
        $db = getDBConnection();
        $stmt = $db->prepare('INSERT INTO device_action_verification (action_id,expected_json,excluded_count,status,read_started_ms) VALUES (?,?,?,?,?)');
        if (!$stmt) throw new RuntimeException('Verification unavailable');
        $stmt->bind_param('isisi', $id, $json, $excluded, $status, $ms);
        if (!$stmt->execute()) throw new RuntimeException('Verification unavailable');
        $stmt->close();
        if (!$expected['fields']) return;
        // One targeted read per successful write; polling never sends commands.
        $stmt = $db->prepare('SELECT device_id FROM device_action_history WHERE id=?');
        $stmt->bind_param('i',$id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) throw new RuntimeException('Verification unavailable');
        $result = acsVerificationRequest($credentials, '/devices/' . rawurlencode($row['device_id']) . '/tasks?timeout=1000&connection_request',
            ['name'=>'getParameterValues','parameterNames'=>array_keys($expected['fields'])]);
        $task = acsActionTaskId($result);
        $accepted = $result['http_code'] === 200 ? 2 : ($result['http_code'] === 202 && $task ? 1 : 0);
        $status = $accepted ? 'pending' : 'unavailable';
        $stmt=$db->prepare('UPDATE device_action_verification SET read_accepted=?,status=?,read_task_id=? WHERE action_id=?');
        $stmt->bind_param('issi',$accepted,$status,$task,$id); $stmt->execute(); $stmt->close();
    } catch (Throwable $error) { error_log('ACS configuration verification unavailable.'); }
}

function acsVerificationHistory($db, array $entries, string $deviceId, ?array $knownTasks = null, ?array $knownFaults = null): array
{
    if (!$entries) return [];
    $ids = array_map(fn($row)=>(int)$row['id'], $entries);
    // IDs originate from the prepared, authorized device-history query.
    try {
        $result = $db->query('SELECT * FROM device_action_verification WHERE action_id IN (' . implode(',', $ids) . ') ORDER BY action_id DESC');
        if (!$result) return [];
        $rows = $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $error) { return []; }
    $output=[]; $paths=[]; $candidates=[];
    foreach ($rows as $row) {
        $id=(int)$row['action_id'];
        $fields=json_decode($row['expected_json'],true);
        if (!is_array($fields)) $fields=[];
        $output[$id]=['status'=>$row['status'],'excluded'=>(int)$row['excluded_count'],'matched'=>(int)$row['matched_count'],'total'=>count($fields)];
        if ($row['status'] === 'confirmed' || $row['status'] === 'unsupported') continue;
        if ((int)$row['read_started_ms'] < (int)floor(microtime(true)*1000)-86400000) {
            $output[$id]['status']='expired'; continue;
        }
        if (!$row['read_accepted'] || count($candidates)>=5) continue;
        $safe=array_filter(array_keys($fields), 'acsVerificationSafePath');
        $next=array_unique(array_merge($paths,$safe));
        if (strlen(implode(',',$next))>6000) continue;
        $paths=$next; $candidates[$id]=['row'=>$row,'fields'=>$fields];
    }
    if (!$candidates || !$paths) return $output;
    try {
        $result=$db->query('SELECT host,port,username,password FROM genieacs_credentials WHERE is_connected=1 ORDER BY id DESC LIMIT 1');
        $credentials=$result->fetch_assoc();
        if (!$credentials) throw new RuntimeException('NBI unavailable');
        if (array_filter($candidates, fn($candidate)=>(int)$candidate['row']['read_accepted'] === 1)) {
            $knownTasks ??= acsActionNbiRead($credentials, 'tasks', $deviceId);
            $knownFaults ??= acsActionNbiRead($credentials, 'faults', $deviceId);
            foreach ($candidates as $id=>$candidate) {
                if ((int)$candidate['row']['read_accepted'] !== 1) continue;
                $task=$candidate['row']['read_task_id'];
                if (!$task || isset($knownTasks[$task])) { unset($candidates[$id]); continue; }
                if (isset($knownFaults[$deviceId . ':task_' . $task])) {
                    $output[$id]['status']='unavailable'; unset($candidates[$id]);
                }
            }
        }
        if (!$candidates) return $output;
        $response=acsVerificationRequest($credentials,'/devices/?query='.rawurlencode(json_encode(['_id'=>$deviceId])).'&projection='.rawurlencode(implode(',',$paths)));
        if ($response['http_code']!==200 || !is_array($response['data']) || !isset($response['data'][0])) throw new RuntimeException('NBI unavailable');
        foreach ($candidates as $id=>$candidate) {
            $comparison=acsVerificationCompare($candidate['fields'],$response['data'][0],(int)$candidate['row']['read_started_ms']);
            $status=$comparison['status']; $matched=$comparison['matched']; $time=gmdate('Y-m-d H:i:s');
            $stmt=$db->prepare('UPDATE device_action_verification SET status=?,matched_count=?,checked_at=? WHERE action_id=? AND status<>"confirmed"');
            $stmt->bind_param('sisi',$status,$matched,$time,$id); $stmt->execute(); $stmt->close();
            $output[$id]=array_merge($output[$id],$comparison);
        }
    } catch (Throwable $error) {
        foreach ($candidates as $id=>$unused) $output[$id]['sync_available']=false;
    }
    return $output;
}
