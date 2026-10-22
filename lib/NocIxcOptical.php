<?php
namespace App;

/** Cached inventory reads only. Never refreshes OLTs, ONUs or subscriber sessions. */
class NocIxcOptical
{
    private $url;
    private $token;

    public function __construct(array $config)
    {
        $this->url = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->token = (string) ($config['token'] ?? '');
        $parts = parse_url($this->url);
        $local = in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || $this->token === ''
            || (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $local))) {
            throw new \RuntimeException('Invalid IXC connection configuration.');
        }
    }

    public static function serialAliases(string $value): array
    {
        $serial = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));
        if ($serial === '') return [];
        $aliases = [$serial];
        if (strlen($serial) >= 12 && preg_match('/^[0-9A-F]{8}/', $serial)) {
            $decoded = hex2bin(substr($serial, 0, 8));
            if ($decoded !== false && preg_match('/^[A-Z0-9]{4}$/i', $decoded)) {
                $aliases[] = strtoupper($decoded . substr($serial, 8));
            }
        }
        if (strlen($serial) >= 12 && !preg_match('/^[0-9A-F]{4}$/i', substr($serial, 0, 4))) {
            $aliases[] = strtoupper(bin2hex(substr($serial, 0, 4)) . substr($serial, 4));
        }
        return array_values(array_unique($aliases));
    }

    public static function normalizeRx($value): ?float
    {
        if (!is_scalar($value) || is_bool($value)) return null;
        $text = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($text)) return null;
        $rx = (float) $text;
        return is_finite($rx) && $rx < 0 && $rx >= -50 ? round($rx, 2) : null;
    }

    private function page(int $page, int $timeout): array
    {
        $table = 'radpop_radio_cliente_fibra';
        $ch = curl_init($this->url . '/webservice/v1/' . $table);
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'qtype' => $table . '.id', 'query' => '0', 'oper' => '>',
                'page' => (string) $page, 'rp' => '100',
                'sortname' => $table . '.id', 'sortorder' => 'asc',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json',
                'ixcsoft: listar', 'Authorization: Basic ' . base64_encode($this->token)],
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($ok === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('IXC inventory request failed.');
        }
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($data) && !isset($data['registros']) && (string) ($data['total'] ?? '') === '0'
            && !isset($data['type']) && !isset($data['error'])) {
            $data['registros'] = [];
        }
        if (!is_array($data) || !isset($data['registros']) || !is_array($data['registros'])
            || ($data['type'] ?? '') === 'error' || ($data['status'] ?? '') === 'error'
            || count($data['registros']) > 100
            || ($data['registros'] !== [] && array_keys($data['registros']) !== range(0, count($data['registros']) - 1))) {
            throw new \RuntimeException('Invalid IXC inventory response.');
        }
        return $data;
    }

    public function snapshot(int $budget = 12): array
    {
        // Identity includes the credential hash, preventing reuse after token rotation.
        $identity = substr(hash('sha256', $this->url . "\0" . $this->token . "\0" . __DIR__), 0, 32);
        $parent = getenv('NOC_CACHE_DIR') ?: sys_get_temp_dir();
        $dir = rtrim($parent, '/\\') . '/jrc-noc-ixc-' . $identity;
        if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true))) {
            throw new \RuntimeException('Private NOC cache unavailable.');
        }
        $path = $dir . '/optical.json';
        if (is_link($path) || is_link($dir . '/refresh.lock')) {
            throw new \RuntimeException('Invalid NOC cache path.');
        }
        $readCache = static function () use ($path): ?array {
            if (!is_file($path)) return null;
            $raw = file_get_contents($path, false, null, 0, 4 * 1024 * 1024);
            $cache = is_string($raw) ? json_decode($raw, true) : null;
            $age = time() - (int) ($cache['captured_at'] ?? 0);
            return is_array($cache) && ($cache['version'] ?? 0) === 1 && $age >= 0 && $age < 120
                && is_array($cache['devices'] ?? null) ? $cache : null;
        };
        $cached = $readCache();
        if ($cached !== null) return $cached;
        $lock = fopen($dir . '/refresh.lock', 'c');
        if (!$lock) throw new \RuntimeException('NOC cache lock unavailable.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('NOC optical refresh already running.');
            }
            $cached = $readCache();
            if ($cached !== null) return $cached;
            $deadline = microtime(true) + max(1, min(12, $budget));
            $devices = [];
            $lastId = 0;
            $seen = 0;
            for ($page = 1; $page <= 200; $page++) {
                $remaining = (int) ($deadline - microtime(true));
                if ($remaining < 1) throw new \RuntimeException('IXC snapshot deadline exceeded.');
                $data = $this->page($page, min(5, $remaining));
                foreach ($data['registros'] as $row) {
                    $id = is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
                    if ($id <= $lastId) throw new \RuntimeException('Unordered or repeated IXC page.');
                    $lastId = $id;
                    $seen++;
                    $serial = is_scalar($row['mac'] ?? null) ? (string) $row['mac'] : '';
                    foreach (self::serialAliases($serial) as $alias) {
                        $devices[$alias] = ['rx' => self::normalizeRx($row['sinal_rx'] ?? null),
                            'updated_at' => is_string($row['data_sinal'] ?? null) ? $row['data_sinal'] : null];
                    }
                }
                $count = count($data['registros']);
                $total = is_numeric($data['total'] ?? null) ? (int) $data['total'] : null;
                if ($count < 100 || ($total !== null && $seen >= $total)) {
                    if ($total !== null && $seen !== $total) throw new \RuntimeException('Incomplete IXC inventory.');
                    $snapshot = ['version' => 1, 'captured_at' => time(), 'inventory_total' => $seen, 'devices' => $devices];
                    $temp = tempnam($dir, 'optical-');
                    try {
                        if ($temp === false || file_put_contents($temp, json_encode($snapshot, JSON_THROW_ON_ERROR)) === false
                            || !rename($temp, $path)) throw new \RuntimeException('NOC cache write failed.');
                    } finally {
                        if ($temp !== false && is_file($temp)) unlink($temp);
                    }
                    return $snapshot;
                }
                unset($data);
            }
            throw new \RuntimeException('IXC inventory page limit exceeded.');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
