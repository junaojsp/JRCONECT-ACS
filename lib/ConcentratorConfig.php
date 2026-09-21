<?php

declare(strict_types=1);

use phpseclib3\Net\SSH2;

function ensureConcentratorConfigTable(mysqli $conn): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS concentrator_credentials (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            vendor VARCHAR(50) NOT NULL DEFAULT 'huawei',
            model VARCHAR(100) NOT NULL DEFAULT 'NE8000',
            host VARCHAR(255) NOT NULL,
            port INT UNSIGNED NOT NULL DEFAULT 22,
            protocol VARCHAR(20) NOT NULL DEFAULT 'ssh',
            username VARCHAR(120) NOT NULL,
            password_ciphertext TEXT NOT NULL,
            ixc_name VARCHAR(120) NULL,
            nas_ip VARCHAR(45) NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_connected TINYINT(1) NOT NULL DEFAULT 0,
            server_fingerprint VARCHAR(160) NULL,
            last_test DATETIME NULL,
            last_error VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_concentrator_host_port_user (host, port, username),
            KEY idx_concentrator_default (is_default),
            KEY idx_concentrator_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Não foi possível preparar a tabela de concentradores.');
    }
}

function concentratorSecretKeyPath(): string
{
    return dirname(__DIR__) . '/config/concentrator.key';
}

function concentratorSecretKey(): string
{
    $path = concentratorSecretKeyPath();

    if (is_file($path)) {
        $raw = trim((string)file_get_contents($path));
        $decoded = base64_decode($raw, true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            return $decoded;
        }
        throw new RuntimeException('A chave de proteção dos concentradores é inválida.');
    }

    $key = random_bytes(32);
    $encoded = base64_encode($key);

    if (@file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível criar a chave local de proteção das credenciais.');
    }
    @chmod($path, 0600);

    return $key;
}

function encryptConcentratorPassword(string $plain): string
{
    if ($plain === '') {
        throw new InvalidArgumentException('Senha do concentrador não pode ficar vazia.');
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('Extensão OpenSSL do PHP não está disponível.');
    }

    $key = concentratorSecretKey();
    $iv = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if (!is_string($cipher)) {
        throw new RuntimeException('Não foi possível proteger a senha do concentrador.');
    }

    return base64_encode(
        json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($cipher),
        ], JSON_UNESCAPED_SLASHES)
    );
}

function decryptConcentratorPassword(string $encoded): string
{
    if ($encoded === '') return '';

    $outer = base64_decode($encoded, true);
    $payload = is_string($outer) ? json_decode($outer, true) : null;

    if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) {
        throw new RuntimeException('Formato da credencial do concentrador não é reconhecido.');
    }

    $iv = base64_decode((string)($payload['iv'] ?? ''), true);
    $tag = base64_decode((string)($payload['tag'] ?? ''), true);
    $cipher = base64_decode((string)($payload['data'] ?? ''), true);

    if (!is_string($iv) || !is_string($tag) || !is_string($cipher)) {
        throw new RuntimeException('Credencial do concentrador está corrompida.');
    }

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        concentratorSecretKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if (!is_string($plain)) {
        throw new RuntimeException('Não foi possível abrir a credencial do concentrador.');
    }

    return $plain;
}

function normalizeConcentratorInput(array $data): array
{
    $id = isset($data['id']) && is_numeric($data['id']) ? (int)$data['id'] : 0;
    $name = trim((string)($data['name'] ?? ''));
    $vendor = strtolower(trim((string)($data['vendor'] ?? 'huawei')));
    $model = trim((string)($data['model'] ?? 'NE8000'));
    $host = trim((string)($data['host'] ?? ''));
    $port = isset($data['port']) && is_numeric($data['port']) ? (int)$data['port'] : 22;
    $protocol = strtolower(trim((string)($data['protocol'] ?? 'ssh')));
    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $ixcName = trim((string)($data['ixc_name'] ?? ''));
    $nasIp = trim((string)($data['nas_ip'] ?? ''));
    $isDefault = !empty($data['is_default']) && !in_array($data['is_default'], ['0', 0, false, 'false'], true);
    $isActive = !isset($data['is_active']) || !in_array($data['is_active'], ['0', 0, false, 'false'], true);

    if ($name === '' || $host === '' || $username === '') {
        throw new InvalidArgumentException('Nome, endereço e usuário são obrigatórios.');
    }
    if (!in_array($vendor, ['huawei'], true)) {
        throw new InvalidArgumentException('Nesta etapa, o fabricante suportado é Huawei.');
    }
    if (!in_array($protocol, ['ssh'], true)) {
        throw new InvalidArgumentException('Nesta etapa, o protocolo suportado é SSH.');
    }
    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('Porta SSH inválida.');
    }
    if (
        filter_var($host, FILTER_VALIDATE_IP) === false &&
        !preg_match('/^[A-Za-z0-9.-]+$/', $host)
    ) {
        throw new InvalidArgumentException('Endereço do concentrador inválido.');
    }
    if ($nasIp !== '' && filter_var($nasIp, FILTER_VALIDATE_IP) === false) {
        throw new InvalidArgumentException('NAS IP inválido.');
    }

    return [
        'id' => $id,
        'name' => $name,
        'vendor' => $vendor,
        'model' => $model !== '' ? $model : 'NE8000',
        'host' => $host,
        'port' => $port,
        'protocol' => $protocol,
        'username' => $username,
        'password' => $password,
        'ixc_name' => $ixcName !== '' ? $ixcName : null,
        'nas_ip' => $nasIp !== '' ? $nasIp : null,
        'is_default' => $isDefault,
        'is_active' => $isActive,
    ];
}

function getConcentrators(mysqli $conn): array
{
    ensureConcentratorConfigTable($conn);

    $result = $conn->query("
        SELECT id, name, vendor, model, host, port, protocol, username,
               ixc_name, nas_ip, is_default, is_active, is_connected,
               server_fingerprint, last_test, last_error, created_at, updated_at
        FROM concentrator_credentials
        ORDER BY is_default DESC, is_active DESC, name ASC, id ASC
    ");

    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['port'] = (int)$row['port'];
            $row['is_default'] = !empty($row['is_default']);
            $row['is_active'] = !empty($row['is_active']);
            $row['is_connected'] = !empty($row['is_connected']);
            $rows[] = $row;
        }
    }
    return $rows;
}

function getConcentratorById(mysqli $conn, int $id, bool $withPassword = false): ?array
{
    ensureConcentratorConfigTable($conn);

    $stmt = $conn->prepare("SELECT * FROM concentrator_credentials WHERE id = ? LIMIT 1");
    if (!$stmt) throw new RuntimeException('Não foi possível consultar o concentrador.');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    if (!$row) return null;

    $row['id'] = (int)$row['id'];
    $row['port'] = (int)$row['port'];
    $row['is_default'] = !empty($row['is_default']);
    $row['is_active'] = !empty($row['is_active']);
    $row['is_connected'] = !empty($row['is_connected']);

    if ($withPassword) {
        $row['password'] = decryptConcentratorPassword((string)$row['password_ciphertext']);
    }
    unset($row['password_ciphertext']);

    return $row;
}

function getDefaultConcentrator(mysqli $conn, bool $withPassword = false): ?array
{
    ensureConcentratorConfigTable($conn);

    $result = $conn->query("
        SELECT id
        FROM concentrator_credentials
        WHERE is_active = 1
        ORDER BY is_default DESC, is_connected DESC, id ASC
        LIMIT 1
    ");
    $row = $result ? $result->fetch_assoc() : null;

    return $row ? getConcentratorById($conn, (int)$row['id'], $withPassword) : null;
}

function saveConcentratorConfig(mysqli $conn, array $input): int
{
    ensureConcentratorConfigTable($conn);
    $data = normalizeConcentratorInput($input);

    $existing = $data['id'] > 0
        ? getConcentratorById($conn, $data['id'], false)
        : null;

    if ($data['password'] === '' && !$existing) {
        throw new InvalidArgumentException('Senha é obrigatória no primeiro cadastro.');
    }

    if ($data['is_default']) {
        $conn->query("UPDATE concentrator_credentials SET is_default = 0");
    }

    if ($existing) {
        if ($data['password'] !== '') {
            $passwordCiphertext = encryptConcentratorPassword($data['password']);
            $sql = "
                UPDATE concentrator_credentials
                SET name=?, vendor=?, model=?, host=?, port=?, protocol=?, username=?,
                    password_ciphertext=?, ixc_name=?, nas_ip=?, is_default=?, is_active=?,
                    is_connected=0, last_error=NULL, updated_at=NOW()
                WHERE id=?
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new RuntimeException('Não foi possível preparar a atualização do concentrador.');
            $defaultInt = $data['is_default'] ? 1 : 0;
            $activeInt = $data['is_active'] ? 1 : 0;
            $stmt->bind_param(
                'ssssisssssiii',
                $data['name'],
                $data['vendor'],
                $data['model'],
                $data['host'],
                $data['port'],
                $data['protocol'],
                $data['username'],
                $passwordCiphertext,
                $data['ixc_name'],
                $data['nas_ip'],
                $defaultInt,
                $activeInt,
                $data['id']
            );
        } else {
            $sql = "
                UPDATE concentrator_credentials
                SET name=?, vendor=?, model=?, host=?, port=?, protocol=?, username=?,
                    ixc_name=?, nas_ip=?, is_default=?, is_active=?, updated_at=NOW()
                WHERE id=?
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new RuntimeException('Não foi possível preparar a atualização do concentrador.');
            $defaultInt = $data['is_default'] ? 1 : 0;
            $activeInt = $data['is_active'] ? 1 : 0;
            $stmt->bind_param(
                'ssssissssiii',
                $data['name'],
                $data['vendor'],
                $data['model'],
                $data['host'],
                $data['port'],
                $data['protocol'],
                $data['username'],
                $data['ixc_name'],
                $data['nas_ip'],
                $defaultInt,
                $activeInt,
                $data['id']
            );
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Não foi possível atualizar o concentrador.');
        }
        return $data['id'];
    }

    $passwordCiphertext = encryptConcentratorPassword($data['password']);
    $defaultInt = $data['is_default'] ? 1 : 0;
    $activeInt = $data['is_active'] ? 1 : 0;

    $stmt = $conn->prepare("
        INSERT INTO concentrator_credentials
            (name, vendor, model, host, port, protocol, username, password_ciphertext,
             ixc_name, nas_ip, is_default, is_active, is_connected)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
    ");
    if (!$stmt) throw new RuntimeException('Não foi possível preparar o cadastro do concentrador.');

    $stmt->bind_param(
        'ssssisssssii',
        $data['name'],
        $data['vendor'],
        $data['model'],
        $data['host'],
        $data['port'],
        $data['protocol'],
        $data['username'],
        $passwordCiphertext,
        $data['ixc_name'],
        $data['nas_ip'],
        $defaultInt,
        $activeInt
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Não foi possível cadastrar o concentrador.');
    }

    return (int)$conn->insert_id;
}

function deleteConcentratorConfig(mysqli $conn, int $id): void
{
    ensureConcentratorConfigTable($conn);

    $stmt = $conn->prepare("DELETE FROM concentrator_credentials WHERE id = ?");
    if (!$stmt) throw new RuntimeException('Não foi possível preparar a exclusão do concentrador.');
    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        throw new RuntimeException('Não foi possível excluir o concentrador.');
    }
}

function concentratorFingerprint(SSH2 $ssh): ?string
{
    try {
        $hostKey = $ssh->getServerPublicHostKey();
        if (!is_string($hostKey) || $hostKey === '') return null;
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $hostKey, true)), '=');
    } catch (Throwable) {
        return null;
    }
}

function testConcentratorConnection(array $config): array
{
    if (!class_exists(SSH2::class)) {
        return [
            'success' => false,
            'code' => 'dependency_missing',
            'message' => 'Biblioteca SSH não instalada. Execute composer install após atualizar o projeto.',
        ];
    }

    $host = trim((string)($config['host'] ?? ''));
    $port = (int)($config['port'] ?? 22);
    $username = trim((string)($config['username'] ?? ''));
    $password = (string)($config['password'] ?? '');

    if ($host === '' || $username === '' || $password === '') {
        return [
            'success' => false,
            'code' => 'credentials_missing',
            'message' => 'Host, usuário e senha são obrigatórios para testar o concentrador.',
        ];
    }

    try {
        $ssh = new SSH2($host, $port, 8);
        $ssh->setTimeout(8);

        if (!$ssh->login($username, $password)) {
            return [
                'success' => false,
                'code' => 'authentication_failed',
                'message' => 'O NE respondeu, mas recusou o usuário ou a senha.',
            ];
        }

        $fingerprint = concentratorFingerprint($ssh);
        $expectedFingerprint = trim((string)($config['server_fingerprint'] ?? ''));
        if (
            $expectedFingerprint !== '' &&
            $fingerprint !== null &&
            !hash_equals($expectedFingerprint, $fingerprint)
        ) {
            return [
                'success' => false,
                'code' => 'host_key_changed',
                'message' => 'A chave SSH do concentrador mudou. Confirme o equipamento antes de continuar.',
                'fingerprint' => $fingerprint,
            ];
        }

        $version = trim((string)$ssh->exec('display version'));
        $isHuawei = stripos($version, 'Huawei') !== false ||
                    stripos($version, 'VRP') !== false ||
                    strtolower((string)($config['vendor'] ?? '')) === 'huawei';

        $summary = null;
        if ($version !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $version) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (
                    stripos($line, 'Huawei') !== false ||
                    stripos($line, 'VRP') !== false ||
                    stripos($line, 'NetEngine') !== false
                ) {
                    $summary = mb_substr($line, 0, 180);
                    break;
                }
            }
        }

        return [
            'success' => true,
            'code' => 'connected',
            'message' => 'Autenticação SSH realizada com sucesso no concentrador.',
            'vendor_confirmed' => $isHuawei,
            'fingerprint' => $fingerprint,
            'identity' => $summary,
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'code' => 'connection_failed',
            'message' => 'Não foi possível abrir a sessão SSH no concentrador: ' . $e->getMessage(),
        ];
    }
}

function updateConcentratorTestStatus(
    mysqli $conn,
    int $id,
    array $result
): void {
    ensureConcentratorConfigTable($conn);

    $connected = !empty($result['success']) ? 1 : 0;
    $fingerprint = $result['fingerprint'] ?? null;
    $error = !empty($result['success'])
        ? null
        : mb_substr((string)($result['message'] ?? 'Falha de conexão'), 0, 255);

    $stmt = $conn->prepare("
        UPDATE concentrator_credentials
        SET is_connected = ?,
            server_fingerprint = COALESCE(server_fingerprint, ?),
            last_test = NOW(),
            last_error = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    if (!$stmt) return;

    $stmt->bind_param('issi', $connected, $fingerprint, $error, $id);
    $stmt->execute();
}
