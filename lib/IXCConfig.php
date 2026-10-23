<?php
declare(strict_types=1);

function getIxcConfig(): array
{
    $baseUrl = trim((string)(getenv('IXC_BASE_URL') ?: ''));
    $token = trim((string)(getenv('IXC_TOKEN') ?: ''));

    $files = [
        '/etc/jrconect-acs/ixc.php',
        __DIR__ . '/../config/ixc.php',
    ];

    foreach ($files as $file) {
        if (($baseUrl === '' || $token === '') && is_file($file) && is_readable($file)) {
            $config = require $file;
            if (is_array($config)) {
                if ($baseUrl === '') $baseUrl = trim((string)($config['base_url'] ?? ''));
                if ($token === '') $token = trim((string)($config['token'] ?? ''));
            }
        }
    }

    if ($baseUrl === '' || $token === '') {
        throw new RuntimeException(
            'Configuração IXC ausente. Defina IXC_BASE_URL/IXC_TOKEN ou /etc/jrconect-acs/ixc.php.'
        );
    }

    return [
        'base_url' => rtrim($baseUrl, '/'),
        'token' => $token,
    ];
}
