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
