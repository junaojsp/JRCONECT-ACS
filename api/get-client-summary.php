<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/IXCConfig.php';
if (function_exists('requireLogin')) requireLogin();

header('Content-Type: application/json; charset=utf-8');

function jrClientOut(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jrClientConfig(): array {
    $cfg = getIxcConfig();
    return [$cfg['base_url'], $cfg['token']];
}

function jrClientList(string $baseUrl, string $token, string $table, string $qtype, string $query, int $rp = 20): array {
    $url = $baseUrl . '/webservice/v1/' . rawurlencode($table);
    $payload = json_encode([
        'qtype' => $qtype,
        'query' => $query,
        'oper' => '=',
        'page' => '1',
        'rp' => (string)$rp,
        'sortname' => $table . '.id',
        'sortorder' => 'desc',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Não foi possível iniciar a consulta IXC.');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
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
    if ($http < 200 || $http >= 300) throw new RuntimeException('IXC retornou HTTP ' . $http . ' em ' . $table . '.');

    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException('Resposta inválida do IXC em ' . $table . '.');

    $records = $json['registros'] ?? $json['records'] ?? (array_is_list($json) ? $json : []);
    return is_array($records) ? array_values(array_filter($records, 'is_array')) : [];
}

function jrClientFirst(array $records): array {
    return $records[0] ?? [];
}

function jrClientPick(array $record, array $keys): mixed {
    foreach ($keys as $key) {
        if (array_key_exists($key, $record) && $record[$key] !== null && trim((string)$record[$key]) !== '') {
            return $record[$key];
        }
    }
    return null;
}

function jrClientStatusLabel(mixed $value): ?string {
    if ($value === null || $value === '') return null;
    $text = strtoupper(trim((string)$value));
    return match ($text) {
        'A' => 'Ativo',
        'I' => 'Inativo',
        'D' => 'Desativado',
        'C' => 'Cancelado',
        'P' => 'Pendente',
        default => (string)$value,
    };
}

function jrClientAddress(array $client): ?string {
    $street = jrClientPick($client, ['endereco', 'logradouro']);
    $number = jrClientPick($client, ['numero']);
    $district = jrClientPick($client, ['bairro']);
    $city = jrClientPick($client, ['cidade_nome', 'nome_cidade']);
    $uf = jrClientPick($client, ['uf']);

    $parts = [];
    $line = trim(implode(', ', array_filter([(string)$street, (string)$number], fn($v) => $v !== '')));
    if ($line !== '') $parts[] = $line;
    if ($district) $parts[] = (string)$district;

    $cityUf = trim(implode(' - ', array_filter([(string)$city, (string)$uf], fn($v) => $v !== '')));
    if ($cityUf !== '') $parts[] = $cityUf;

    return $parts ? implode(' · ', $parts) : null;
}

function jrClientOverdue(array $records): array {
    $today = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
    $count = 0;

    foreach ($records as $record) {
        $status = strtoupper(trim((string)($record['status'] ?? '')));
        $released = strtoupper(trim((string)($record['liberado'] ?? 'S')));
        if (in_array($status, ['R', 'C'], true) || $released === 'N') continue;

        $rawDate = trim((string)($record['data_vencimento'] ?? ''));
        if ($rawDate === '') continue;

        $due = DateTimeImmutable::createFromFormat('Y-m-d', substr($rawDate, 0, 10), new DateTimeZone('America/Sao_Paulo'));
        if (!$due) $due = DateTimeImmutable::createFromFormat('d/m/Y', substr($rawDate, 0, 10), new DateTimeZone('America/Sao_Paulo'));
        if ($due && $due < $today) $count++;
    }

    return [
        'overdue_count' => $count,
        'label' => $count === 0 ? 'Em dia' : ($count === 1 ? '1 fatura vencida' : $count . ' faturas vencidas'),
    ];
}

try {
    $idLogin = trim((string)($_GET['id_login'] ?? ''));
    $idContrato = trim((string)($_GET['id_contrato'] ?? ''));

    if ($idLogin === '' && $idContrato === '') {
        jrClientOut(['success' => false, 'message' => 'Informe id_login ou id_contrato.'], 400);
    }

    if ($idLogin !== '' && !preg_match('/^\d+$/', $idLogin)) {
        jrClientOut(['success' => false, 'message' => 'id_login inválido.'], 400);
    }
    if ($idContrato !== '' && !preg_match('/^\d+$/', $idContrato)) {
        jrClientOut(['success' => false, 'message' => 'id_contrato inválido.'], 400);
    }

    [$baseUrl, $token] = jrClientConfig();

    $login = [];
    $contract = [];
    $client = [];
    $plan = null;
    $financial = ['overdue_count' => null, 'label' => 'Não consultado'];

    if ($idLogin !== '') {
        try {
            $login = jrClientFirst(jrClientList($baseUrl, $token, 'radusuarios', 'radusuarios.id', $idLogin, 1));
        } catch (Throwable $ignored) {}
    }

    if ($idContrato === '') {
        $idContrato = trim((string)(jrClientPick($login, ['id_contrato', 'contrato_id']) ?? ''));
    }

    if ($idContrato !== '') {
        try {
            $contract = jrClientFirst(jrClientList($baseUrl, $token, 'cliente_contrato', 'cliente_contrato.id', $idContrato, 1));
        } catch (Throwable $ignored) {}
    }

    $idCliente = trim((string)(
        jrClientPick($contract, ['id_cliente', 'cliente_id'])
        ?? jrClientPick($login, ['id_cliente', 'cliente_id'])
        ?? ''
    ));

    if ($idCliente !== '') {
        try {
            $client = jrClientFirst(jrClientList($baseUrl, $token, 'cliente', 'cliente.id', $idCliente, 1));
        } catch (Throwable $ignored) {}
    }

    $plan = jrClientPick($contract, ['plano', 'nome_plano', 'contrato', 'descricao'])
        ?? jrClientPick($login, ['plano', 'nome_plano', 'perfil', 'nome_perfil']);

    $idVdContrato = trim((string)(jrClientPick($contract, ['id_vd_contrato']) ?? ''));
    if (!$plan && $idVdContrato !== '') {
        try {
            $planRecord = jrClientFirst(jrClientList($baseUrl, $token, 'vd_contratos', 'vd_contratos.id', $idVdContrato, 1));
            $plan = jrClientPick($planRecord, ['nome', 'descricao', 'contrato', 'titulo']);
        } catch (Throwable $ignored) {}
    }

    if ($idContrato !== '') {
        try {
            $receivables = jrClientList($baseUrl, $token, 'fn_areceber', 'fn_areceber.id_contrato', $idContrato, 100);
            $financial = jrClientOverdue($receivables);
        } catch (Throwable $ignored) {}
    }

    $phone = jrClientPick($client, ['whatsapp', 'telefone_celular', 'fone', 'telefone_comercial']);
    $username = jrClientPick($login, ['login', 'username', 'usuario']);

    jrClientOut([
        'success' => true,
        'source' => 'IXC',
        'source_policy' => [
            'customer' => 'IXC',
            'contract' => 'IXC',
            'login' => 'IXC',
            'financial' => 'IXC',
        ],
        'customer' => [
            'id' => $idCliente !== '' ? $idCliente : null,
            'name' => jrClientPick($client, ['razao', 'fantasia', 'nome']),
            'phone' => $phone,
            'address' => jrClientAddress($client),
        ],
        'contract' => [
            'id' => $idContrato !== '' ? $idContrato : null,
            'status' => jrClientPick($contract, ['status']),
            'status_label' => jrClientStatusLabel(jrClientPick($contract, ['status'])),
            'plan' => $plan,
        ],
        'login' => [
            'id' => $idLogin !== '' ? $idLogin : (jrClientPick($login, ['id']) ?: null),
            'username' => $username,
        ],
        'financial' => $financial,
    ]);

} catch (Throwable $e) {
    jrClientOut([
        'success' => false,
        'source' => 'IXC',
        'message' => $e->getMessage(),
    ], 502);
}
