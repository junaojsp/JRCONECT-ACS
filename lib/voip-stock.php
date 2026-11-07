<?php
declare(strict_types=1);

/* Small, isolated inventory. No IXC writes and no automatic number release. */
final class VoipStock
{
    private string $dir;
    public function __construct(string $dir = '/var/lib/jrconect-voip') { $this->dir = $dir; }

    public static function parseImport(string $text): array
    {
        if (strlen($text) > 100000) throw new RuntimeException('Importação muito grande.');
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', trim($text)) as $line) {
            if (trim($line) === '') continue;
            $parts = explode(';', $line, 3);
            $number = preg_replace('/[\s()+-]/', '', trim($parts[0]));
            if (!preg_match('/^[1-9][0-9]{9}$/', $number)) throw new RuntimeException('Número inválido: use DDD e 8 dígitos.');
            $status = trim($parts[1] ?? 'a_conferir');
            $holder = trim($parts[2] ?? '');
            if (!in_array($status, ['ocupado', 'a_conferir'], true)) throw new RuntimeException('Use ocupado ou a_conferir.');
            if (strlen($holder) > 250 || preg_match('/[\x00-\x1f]/', $holder)) throw new RuntimeException('Nome inválido.');
            if ($status === 'ocupado' && $holder === '') throw new RuntimeException('Informe o titular do número ocupado.');
            if (isset($rows[$number])) throw new RuntimeException('Número duplicado na importação.');
            $rows[$number] = ['number'=>$number, 'status'=>$status, 'holder'=>$holder, 'contract_id'=>null, 'product_record_id'=>null];
        }
        if (!$rows || count($rows) > 500) throw new RuntimeException('Importe entre 1 e 500 números.');
        return array_values($rows);
    }

    private function locked(bool $write, callable $operation): array
    {
        if (!is_dir($this->dir) || is_link($this->dir)) throw new RuntimeException('Estoque não instalado. Execute o instalador.');
        $file = $this->dir . '/stock.json';
        $lockPath = $this->dir . '/stock.lock';
        if (is_link($file) || is_link($lockPath)) throw new RuntimeException('Armazenamento inválido.');
        $lock = fopen($lockPath, 'c');
        if (!$lock) throw new RuntimeException('Estoque indisponível.');
        try {
            if (!flock($lock, $write ? LOCK_EX : LOCK_SH)) throw new RuntimeException('Falha ao bloquear estoque.');
            if (is_file($file)) {
                $raw = file_get_contents($file);
                if ($raw === false) throw new RuntimeException('Falha na leitura do estoque.');
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['numbers'] ?? null) || !is_array($data['audit'] ?? null)) {
                    throw new RuntimeException('Estoque inválido; nenhum dado foi substituído.');
                }
            } else {
                $data = ['version'=>1, 'revision'=>0, 'numbers'=>[], 'audit'=>[]];
            }
            $result = $operation($data);
            if ($write && $result !== $data) {
                $result['revision'] = $data['revision'] + 1;
                $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $tmp = tempnam($this->dir, '.stock-');
                if ($tmp === false) throw new RuntimeException('Falha ao preparar gravação.');
                try {
                    if (!chmod($tmp, 0600) || file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $file)) {
                        throw new RuntimeException('Falha ao gravar estoque.');
                    }
                } finally { if (is_file($tmp)) unlink($tmp); }
            }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function read(): array { return $this->locked(false, static fn(array $data): array => $data); }


    private static function validId(string $value): bool
    {
        return preg_match('/^[0-9]{1,12}$/D', $value) === 1;
    }

    public function linkManually(string $number, string $contractId, string $productRecordId, string $actor): array
    {
        if (!preg_match('/^[1-9][0-9]{9}$/D', $number) ||
            !self::validId($contractId) || !self::validId($productRecordId) ||
            $actor === '') throw new RuntimeException('Dados de vínculo inválidos.');

        return $this->locked(true, static function(array $data) use ($number, $contractId, $productRecordId, $actor): array {
            if (!isset($data['numbers'][$number])) throw new RuntimeException('Número não cadastrado.');
            $current = $data['numbers'][$number];
            if (!in_array($current['status'] ?? '', ['ocupado', 'disponivel'], true)) {
                throw new RuntimeException('Número a conferir: valide a disponibilidade na VoiceCopy antes de vincular.');
            }
            if (!empty($current['contract_id']) || !empty($current['product_record_id'])) {
                throw new RuntimeException('Número já vinculado. Nenhuma alteração realizada.');
            }
            foreach ($data['numbers'] as $existingNumber => $existing) {
                if ($existingNumber !== $number &&
                    ((string)($existing['contract_id'] ?? '') === $contractId ||
                     (string)($existing['product_record_id'] ?? '') === $productRecordId)) {
                    throw new RuntimeException('Contrato ou registro do produto 74 já vinculado a outro número.');
                }
            }
            $data['numbers'][$number]['status'] = 'ocupado';
            $data['numbers'][$number]['contract_id'] = $contractId;
            $data['numbers'][$number]['product_record_id'] = $productRecordId;
            $data['audit'][] = [
                'at'=>gmdate('c'), 'actor'=>$actor, 'action'=>'manual_link',
                'number'=>$number, 'contract_id'=>$contractId,
                'product_record_id'=>$productRecordId,
                'previous_status'=>$current['status']
            ];
            return $data;
        });
    }

    /**
     * Reserve one previously verified free DID for one exact product-74 record.
     * The stock lock prevents two simultaneous jobs assigning the same DID.
     * No number is considered occupied until IXC write has been read back.
     */
    public function reserveForVoip(string $contractId, string $productRecordId, string $actor): array
    {
        if (!self::validId($contractId) || !self::validId($productRecordId) ||
            $actor === '') throw new RuntimeException('Reserva VoIP inválida.');
        $token = bin2hex(random_bytes(16));
        $selected = null;
        $state = $this->locked(true, static function(array $data) use ($contractId, $productRecordId, $actor, $token, &$selected): array {
            foreach ($data['numbers'] as $number => $row) {
                if ((string)($row['contract_id'] ?? '') === $contractId ||
                    (string)($row['product_record_id'] ?? '') === $productRecordId) {
                    throw new RuntimeException('Contrato ou produto já vinculado/reservado.');
                }
            }
            $candidates = [];
            foreach ($data['numbers'] as $number => $row) {
                if (($row['status'] ?? '') === 'disponivel' &&
                    empty($row['contract_id']) && empty($row['product_record_id']) &&
                    empty($row['reservation_id'])) $candidates[] = $number;
            }
            sort($candidates, SORT_STRING);
            if (!$candidates) throw new RuntimeException('Nenhum DID confirmado como disponível.');
            // PHP casts numeric array keys to int; preserve DID as a string.
            $selected = (string)$candidates[0];
            $data['numbers'][$selected]['status'] = 'reservado';
            $data['numbers'][$selected]['contract_id'] = $contractId;
            $data['numbers'][$selected]['product_record_id'] = $productRecordId;
            $data['numbers'][$selected]['reservation_id'] = $token;
            $data['audit'][] = [
                'at'=>gmdate('c'), 'actor'=>$actor, 'action'=>'automatic_reserved',
                'number'=>$selected, 'contract_id'=>$contractId,
                'product_record_id'=>$productRecordId,
            ];
            return $data;
        });
        return ['number'=>$selected, 'reservation_id'=>$token,
            'contract_id'=>$contractId, 'product_record_id'=>$productRecordId];
    }

    /**
     * Caller must independently re-read the IXC contract and confirm that
     * obs_contrato contains the exact assigned contract/DID line.
     * Reservation remains blocked for reconciliation on ambiguous failures.
     */
    public function confirmVoipAssignment(array $reservation, bool $ixcReadbackConfirmed, string $actor): array
    {
        $number = (string)($reservation['number'] ?? '');
        $contractId = (string)($reservation['contract_id'] ?? '');
        $productId = (string)($reservation['product_record_id'] ?? '');
        $token = (string)($reservation['reservation_id'] ?? '');
        if (!$ixcReadbackConfirmed || !preg_match('/^[1-9][0-9]{9}$/D', $number) ||
            !self::validId($contractId) || !self::validId($productId) ||
            !preg_match('/^[a-f0-9]{32}$/D', $token) || $actor === '') {
            throw new RuntimeException('Atribuição não confirmada pelo IXC.');
        }
        return $this->locked(true, static function(array $data) use ($number, $contractId, $productId, $token, $actor): array {
            $row = $data['numbers'][$number] ?? null;
            if (!$row || ($row['status'] ?? '') !== 'reservado' ||
                ($row['contract_id'] ?? null) !== $contractId ||
                ($row['product_record_id'] ?? null) !== $productId ||
                ($row['reservation_id'] ?? null) !== $token) {
                throw new RuntimeException('Reserva inexistente ou diferente.');
            }
            $data['numbers'][$number]['status'] = 'ocupado';
            unset($data['numbers'][$number]['reservation_id']);
            $data['audit'][] = [
                'at'=>gmdate('c'), 'actor'=>$actor, 'action'=>'automatic_link_confirmed',
                'number'=>$number, 'contract_id'=>$contractId,
                'product_record_id'=>$productId,
            ];
            return $data;
        });
    }

    public function confirmAvailable(string $number, string $actor): array
    {
        if (!preg_match('/^[1-9][0-9]{9}$/D', $number) || $actor === '') {
            throw new RuntimeException('Confirmação de disponibilidade inválida.');
        }
        return $this->locked(true, static function(array $data) use ($number, $actor): array {
            if (!isset($data['numbers'][$number])) throw new RuntimeException('Número não cadastrado.');
            $row = $data['numbers'][$number];
            if ($row['status'] !== 'a_conferir' ||
                !empty($row['contract_id']) || !empty($row['product_record_id'])) {
                throw new RuntimeException('Somente números a conferir e sem vínculo podem ser confirmados como disponíveis.');
            }
            $data['numbers'][$number]['status'] = 'disponivel';
            $data['audit'][] = [
                'at'=>gmdate('c'), 'actor'=>$actor, 'action'=>'voicecopy_available_confirmed',
                'number'=>$number, 'previous_status'=>$row['status']
            ];
            return $data;
        });
    }

    public function setMinimum(int $minimum, string $actor): array
    {
        if ($minimum < 1 || $minimum > 100 || $actor === '') {
            throw new RuntimeException('Estoque mínimo inválido (1 a 100).');
        }
        return $this->locked(true, static function(array $data) use ($minimum, $actor): array {
            if (($data['minimum_available'] ?? 5) === $minimum) return $data;
            $data['minimum_available'] = $minimum;
            $data['audit'][] = [
                'at'=>gmdate('c'), 'actor'=>$actor, 'action'=>'minimum_changed',
                'minimum_available'=>$minimum
            ];
            return $data;
        });
    }

    public function import(string $text, string $actor): array
    {
        $rows = self::parseImport($text);
        if ($actor === '') throw new RuntimeException('Operador não identificado.');
        return $this->locked(true, static function(array $data) use ($rows, $actor): array {
            foreach ($rows as $row) {
                $key = $row['number'];
                if (isset($data['numbers'][$key])) {
                    $current = $data['numbers'][$key];
                    if ($current['holder'] !== $row['holder'] || $current['status'] !== $row['status']) {
                        throw new RuntimeException('Número já cadastrado com dados diferentes. Nenhum registro foi importado.');
                    }
                    continue; // A repeated import never overwrites a confirmed assignment.
                }
                $data['numbers'][$key] = $row;
                $data['audit'][] = ['at'=>gmdate('c'), 'actor'=>$actor, 'action'=>'import', 'number'=>$key, 'status'=>$row['status']];
            }
            ksort($data['numbers']);
            return $data;
        });
    }
}
