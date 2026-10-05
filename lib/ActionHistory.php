<?php
declare(strict_types=1);

function acsActionFields(array $parameters): string
{
    $fields = [];
    foreach ($parameters as $parameter) {
        $path = is_array($parameter) ? (string)($parameter[0] ?? '') : '';
        $field = match (true) {
            (bool)preg_match('/password|passphrase|preshared|configpassword/i', $path) => 'Senha',
            (bool)preg_match('/\.SSID$/i', $path) => 'SSID',
            (bool)preg_match('/\.Username$/i', $path) => 'Usuário',
            (bool)preg_match('/DHCP/i', $path) => 'DHCP',
            (bool)preg_match('/WAN|PPP|VLAN/i', $path) => 'WAN / PPPoE',
            (bool)preg_match('/WLAN|WiFi/i', $path) => 'Wi-Fi',
            default => 'Parâmetros',
        };
        $fields[$field] = true;
    }
    // Only field categories. Parameter paths, submitted values and secrets never enter the log.
    return implode(', ', array_keys($fields));
}

function acsActionResultState(array $result): string
{
    $code = (int)($result['http_code'] ?? 0);
    if (!empty($result['success']) && $code === 200) return 'completed';
    if (!empty($result['success']) && $code === 202) return 'queued';
    if ($code >= 400 && $code < 500) return 'failed';
    return 'unconfirmed';
}

function acsActionTaskId(array $result): ?string
{
    $id = $result['data']['_id'] ?? null;
    if (is_array($id)) $id = $id['$oid'] ?? null;
    return is_string($id) && preg_match('/^[a-f0-9]{24}$/iD', $id) ? $id : null;
}

function acsActionStart(string $deviceId, string $action, string $fields): ?int
{
    try {
        $db = getDBConnection();
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $actor = 'Integração';
        if ($uid) {
            $stmt = $db->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $uid); $stmt->execute();
            $actor = (string)($stmt->get_result()->fetch_assoc()['username'] ?? 'Usuário');
            $stmt->close();
        }
        $key = hash('sha256', $deviceId);
        $time = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare('INSERT INTO device_action_history
            (device_id, device_key, actor, user_id, action, fields_changed, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, "submitted", ?, ?)');
        if (!$stmt) throw new RuntimeException('Audit unavailable');
        $stmt->bind_param('sssissss', $deviceId, $key, $actor, $uid, $action, $fields, $time, $time);
        if (!$stmt->execute()) throw new RuntimeException('Audit unavailable');
        $id = (int)$db->insert_id; $stmt->close();
        return $id;
    } catch (Throwable $e) {
        // Do not change the existing device-control behavior if the audit DB is unavailable.
        error_log('ACS action history unavailable before command.');
        return null;
    }
}

function acsActionFinish(?int $id, array $result): void
{
    if (!$id) return;
    try {
        $db = getDBConnection();
        $status = acsActionResultState($result);
        $task = acsActionTaskId($result);
        $code = (int)($result['http_code'] ?? 0);
        $time = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare('UPDATE device_action_history SET status = ?, task_id = ?, http_code = ?, updated_at = ? WHERE id = ?');
        if (!$stmt) throw new RuntimeException('Audit unavailable');
        $stmt->bind_param('ssisi', $status, $task, $code, $time, $id);
        if (!$stmt->execute()) throw new RuntimeException('Audit unavailable');
        $stmt->close();
    } catch (Throwable $e) { error_log('ACS action history unavailable after command.'); }
}

function acsActionRemoteState(array $entry, array $taskIds, array $faultIds): string
{
    if (!in_array($entry['status'], ['queued', 'submitted', 'unconfirmed'], true)) return $entry['status'];
    $task = $entry['task_id'] ?? null;
    if (!$task) return $entry['status'] === 'submitted' ? 'unconfirmed' : $entry['status'];
    if (isset($faultIds[$entry['device_id'] . ':task_' . $task])) return 'failed';
    if (isset($taskIds[$task])) return 'queued';
    // Removal from the queue can also be manual. It is not execution confirmation.
    return 'unconfirmed';
}

function acsActionNbiRead(array $credentials, string $collection, string $deviceId): array
{
    if (!in_array($collection, ['tasks', 'faults'], true)) throw new InvalidArgumentException('Invalid collection');
    $filter = $collection === 'tasks' ? ['device' => $deviceId]
        : ['_id' => ['$regex' => '^' . preg_quote($deviceId, '/') . ':task_']];
    $query = rawurlencode((string)json_encode($filter));
    $url = 'http://' . $credentials['host'] . ':' . $credentials['port'] . '/' . $collection . '/?query=' . $query . '&projection=_id';
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('NBI unavailable');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6]);
    if (!empty($credentials['username'])) curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
    $body = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $rows = is_string($body) ? json_decode($body, true) : null;
    if ($http !== 200 || !is_array($rows) || !array_is_list($rows)) throw new RuntimeException('NBI unavailable');
    $ids = [];
    foreach ($rows as $row) {
        $id = $row['_id'] ?? null;
        if (is_array($id)) $id = $id['$oid'] ?? null;
        if (is_string($id)) $ids[$id] = true;
    }
    return $ids;
}
