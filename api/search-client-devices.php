<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
if (function_exists('requireLogin')) requireLogin();

header('Content-Type: application/json; charset=utf-8');

function jrSearchOut(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jrSearchIxcConfig(): array {
    $source = (string)file_get_contents(__DIR__ . '/get-onu-optical.php');

    if (!preg_match('/\\$ixcBaseUrl\\s*=\\s*\'([^\']+)\'/', $source, $urlMatch)) {
        throw new RuntimeException('URL IXC não localizada.');
    }
    if (!preg_match('/\\$ixcToken\\s*=\\s*\'([^\']+)\'/', $source, $tokenMatch)) {
        throw new RuntimeException('Token IXC não localizado.');
    }

    return [rtrim($urlMatch[1], '/'), $tokenMatch[1]];
}

function jrSearchIxcList(
    string $baseUrl,
    string $token,
    string $table,
    string $qtype,
    string $query,
    string $oper = '=',
    int $rp = 50
): array {
    $payload = json_encode([
        'qtype' => $qtype,
        'query' => $query,
        'oper' => $oper,
        'page' => '1',
        'rp' => (string)$rp,
        'sortname' => $table . '.id',
        'sortorder' => 'desc',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($baseUrl . '/webservice/v1/' . rawurlencode($table));
    if ($ch === false) throw new RuntimeException('Falha ao iniciar consulta IXC.');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'ixcsoft: listar',
            'Authorization: Basic ' . base64_encode($token),
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException('Falha na consulta IXC: ' . $error);
    if ($http < 200 || $http >= 300) throw new RuntimeException('IXC retornou HTTP ' . $http . '.');

    $json = json_decode($body, true);
    if (!is_array($json)) return [];

    $records = $json['registros'] ?? $json['records'] ?? (array_is_list($json) ? $json : []);
    return is_array($records) ? array_values(array_filter($records, 'is_array')) : [];
}


function jrFormatDocument(string $digits): array {
    $values = [$digits];

    if (strlen($digits) === 11) {
        $values[] = substr($digits, 0, 3) . '.' .
            substr($digits, 3, 3) . '.' .
            substr($digits, 6, 3) . '-' .
            substr($digits, 9, 2);
    } elseif (strlen($digits) === 14) {
        $values[] = substr($digits, 0, 2) . '.' .
            substr($digits, 2, 3) . '.' .
            substr($digits, 5, 3) . '/' .
            substr($digits, 8, 4) . '-' .
            substr($digits, 12, 2);
    }

    return array_values(array_unique(array_filter($values)));
}

function jrSearchPick(array $record, array $keys): ?string {
    foreach ($keys as $key) {
        if (isset($record[$key]) && trim((string)$record[$key]) !== '') {
            return trim((string)$record[$key]);
        }
    }
    return null;
}

try {
    $term = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($term) < 3) {
        jrSearchOut(['success' => true, 'serials' => [], 'matches' => []]);
    }

    [$baseUrl, $token] = jrSearchIxcConfig();

    $clientIds = [];
    $loginRecords = [];
    $matches = [];

    // Login PPPoE (busca parcial).
    try {
        foreach (jrSearchIxcList($baseUrl, $token, 'radusuarios', 'radusuarios.login', $term, 'L', 50) as $login) {
            $id = jrSearchPick($login, ['id']);
            if ($id) $loginRecords[$id] = $login;
        }
    } catch (Throwable $ignored) {}

    // Nome / razão social e nome fantasia.
    foreach (['cliente.razao', 'cliente.fantasia'] as $qtype) {
        try {
            foreach (jrSearchIxcList($baseUrl, $token, 'cliente', $qtype, $term, 'L', 50) as $client) {
                $id = jrSearchPick($client, ['id']);
                if ($id) $clientIds[$id] = $client;
            }
        } catch (Throwable $ignored) {}
    }

    // CPF/CNPJ: o IXC pode armazenar o documento com ou sem pontuação.
    $digits = preg_replace('/\\D+/', '', $term) ?? '';
    if (strlen($digits) >= 6) {
        $documentQueries = array_values(array_unique(array_merge(
            [$term],
            jrFormatDocument($digits)
        )));

        // Campos encontrados em diferentes versões/instalações do IXC.
        $documentFields = [
            'cliente.cnpj_cpf',
            'cliente.cpf_cnpj',
            'cliente.cpf',
            'cliente.cnpj'
        ];

        foreach ($documentFields as $qtype) {
            foreach ($documentQueries as $cpfQuery) {
                foreach (['=', 'L'] as $oper) {
                    try {
                        foreach (jrSearchIxcList($baseUrl, $token, 'cliente', $qtype, $cpfQuery, $oper, 50) as $client) {
                            $id = jrSearchPick($client, ['id']);
                            if ($id) $clientIds[$id] = $client;
                        }
                    } catch (Throwable $ignored) {}
                }
            }
        }
    }

    // Clientes encontrados -> logins PPPoE.
    foreach (array_keys($clientIds) as $clientId) {
        try {
            foreach (jrSearchIxcList($baseUrl, $token, 'radusuarios', 'radusuarios.id_cliente', $clientId, '=', 50) as $login) {
                $id = jrSearchPick($login, ['id']);
                if ($id) $loginRecords[$id] = $login;
            }
        } catch (Throwable $ignored) {}
    }

    $serials = [];

    // Login -> cadastro da fibra -> serial/MAC da ONU.
    foreach ($loginRecords as $loginId => $login) {
        try {
            $fiberRows = jrSearchIxcList(
                $baseUrl,
                $token,
                'radpop_radio_cliente_fibra',
                'radpop_radio_cliente_fibra.id_login',
                (string)$loginId,
                '=',
                20
            );

            foreach ($fiberRows as $fiber) {
                $serial = jrSearchPick($fiber, ['mac']);
                if (!$serial) continue;

                $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $serial) ?? '');
                if ($normalized === '') continue;

                $serials[$normalized] = true;

                $clientId = jrSearchPick($login, ['id_cliente', 'cliente_id']);
                $client = ($clientId && isset($clientIds[$clientId])) ? $clientIds[$clientId] : [];

                $matches[] = [
                    'serial' => $normalized,
                    'login' => jrSearchPick($login, ['login', 'username', 'usuario']),
                    'name' => jrSearchPick($client, ['razao', 'fantasia', 'nome'])
                        ?? jrSearchPick($fiber, ['nome']),
                ];
            }
        } catch (Throwable $ignored) {}
    }

    jrSearchOut([
        'success' => true,
        'source' => 'IXC',
        'serials' => array_values(array_keys($serials)),
        'matches' => $matches,
    ]);
} catch (Throwable $e) {
    jrSearchOut([
        'success' => false,
        'source' => 'IXC',
        'message' => $e->getMessage(),
    ], 502);
}
