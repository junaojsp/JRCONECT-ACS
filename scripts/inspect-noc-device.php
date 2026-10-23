<?php
/** Run from the staging checkout, never install this script as a web endpoint. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ob_start();
$finished = false;
register_shutdown_function(static function () use (&$finished): void {
    if (!$finished) {
        while (ob_get_level()) ob_end_clean();
        fwrite(STDERR, "Diagnóstico interrompido; nenhum dado privado foi impresso.\n");
    }
});
try {
    $options = getopt('', ['app:', 'serial:', 'values', 'all-paths']);
    $app = realpath($options['app'] ?? '/var/www/gacs');
    $serial = $options['serial'] ?? '';
    if (!$app || !is_file($app . '/config/config.php') || !is_string($serial)
        || !preg_match('/^[A-Za-z0-9:-]{4,80}$/', $serial)) {
        throw new InvalidArgumentException('Usage');
    }
    require $app . '/config/config.php';
    $db = getDBConnection();
    $result = $db->query('SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1');
    $credentials = $result ? $result->fetch_assoc() : null;
    if (!$credentials) throw new RuntimeException('Configuration');
    $query = ['_deviceId._SerialNumber' => $serial];
    $url = 'http://' . $credentials['host'] . ':' . $credentials['port'] . '/devices/?'
        . http_build_query(['query' => json_encode($query, JSON_THROW_ON_ERROR), 'limit' => 2,
            'projection' => '_id,_deviceId,InternetGatewayDevice,Device,VirtualParameters'], '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init($url); $body = '';
    curl_setopt_array($ch, [
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) return 0;
            $body .= $chunk; return strlen($chunk);
        },
    ]);
    if ($credentials['username'] && $credentials['password']) {
        curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
    }
    $ok = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($ok === false || $status < 200 || $status >= 300) throw new RuntimeException('Query');
    $data = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($data) || count($data) !== 1 || !isset($data[0])
        || ($data[0]['_deviceId']['_SerialNumber'] ?? null) !== $serial) throw new RuntimeException('Selection');
    require_once __DIR__ . '/../lib/NocTelemetryCatalogue.php';
    $report = App\NocTelemetryCatalogue::collect($data[0], array_key_exists('values', $options), array_key_exists('all-paths', $options));
    unset($data, $body, $credentials, $db);
    while (ob_get_level()) ob_end_clean();
    $finished = true;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
} catch (Throwable $error) {
    while (ob_get_level()) ob_end_clean();
    $finished = true;
    fwrite(STDERR, "Diagnóstico não concluído. Verifique o caminho da aplicação, o serial e o acesso ao ACS. Nenhum dado privado foi impresso.\n");
    exit(1);
}
