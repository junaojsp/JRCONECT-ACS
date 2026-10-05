<?php
declare(strict_types=1);

// Paginated, read-only IXC listing. A failed page never yields a partial snapshot.
function acsIxcReadTable(array $cfg, string $table): array
{
    if (!in_array($table, ['radpop_radio_cliente_fibra'], true)) throw new InvalidArgumentException('Tabela IXC inválida.');
    $rows = [];
    for ($page = 1; $page <= 100; $page++) {
        $ch = curl_init($cfg['base_url'] . '/webservice/v1/' . $table);
        if ($ch === false) throw new RuntimeException('Falha ao iniciar consulta IXC.');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json',
                'ixcsoft: listar', 'Authorization: Basic ' . base64_encode($cfg['token'])],
            CURLOPT_POSTFIELDS => json_encode(['qtype' => $table . '.id', 'query' => '0',
                'oper' => '>', 'page' => (string)$page, 'rp' => '500',
                'sortname' => $table . '.id', 'sortorder' => 'asc']),
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        if ($http < 200 || $http >= 300 || !is_array($json) || !isset($json['registros']) || !is_array($json['registros'])) {
            if ($http >= 200 && $http < 300 && is_array($json) && isset($json['total']) && (string)$json['total'] === '0') return [];
            throw new RuntimeException('Falha ao listar o cadastro de fibra do IXC. Confira a configuração e a permissão da API.');
        }
        $batch = $json['registros'];
        foreach ($batch as $row) {
            if (!is_array($row)) throw new RuntimeException('Registro IXC inválido.');
            $rows[] = $row;
        }
        if (isset($json['total']) && is_numeric($json['total'])) {
            if (count($rows) >= (int)$json['total']) return $rows;
            if (!$batch) throw new RuntimeException('Paginação IXC incompleta.');
        } elseif (count($batch) < 500) return $rows;
    }
    throw new RuntimeException('Consulta IXC excedeu 50.000 registros.');
}
