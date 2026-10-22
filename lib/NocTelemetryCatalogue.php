<?php
namespace App;

/** Redacted catalogue for CLI diagnostics only. No raw device tree is returned. */
class NocTelemetryCatalogue
{
    public static function collect(array $device, bool $values = false): array
    {
        $rows = []; $visited = 0; $truncated = false;
        $walk = function (array $node, string $path, int $depth) use (&$walk, &$rows, &$visited, &$truncated, $values): void {
            if (++$visited > 50000 || $depth > 40 || count($rows) >= 300) { $truncated = true; return; }
            if ($path !== '' && preg_match('/password|passphrase|secret|token|credential|authentication|keypass|preshared|username|ssid|hostname|macaddress|ipaddress|connectionrequest|serialnumber|phonenumber/i', $path)) return;
            if (array_key_exists('_value', $node)) {
                if (!preg_match('/^[A-Za-z0-9_.-]{1,512}$/', $path)) return;
                if (!preg_match('/temperature|transceiver|rxpower|txpower|receivepower|transmitpower|cpu|processor|memory|memusage|ramusage|hostnumberofentries|numberofhosts|bytesreceived|bytessent|octets|uptime|\.Time\..*(Status|Sync)/i', $path)) return;
                $value = $node['_value'];
                $row = ['path' => $path, 'has_value' => $value !== null && $value !== ''];
                $type = $node['_type'] ?? null;
                if (is_string($type) && preg_match('/^(xsd:)?[A-Za-z]{1,30}$/', $type)) $row['type'] = $type;
                $at = $node['_timestamp'] ?? null;
                if (is_string($at) && preg_match('/^\d{4}-\d{2}-\d{2}T[\d:.+Z-]{5,30}$/', $at)) $row['updated_at'] = $at;
                // Optional samples are numeric metrics only, never arbitrary strings or whole objects.
                if ($values && !is_bool($value) && is_numeric($value) && is_finite((float) $value)
                    && strlen((string) $value) <= 32) $row['numeric_value'] = $value;
                $rows[] = $row;
                return;
            }
            foreach ($node as $key => $child) {
                if (!is_array($child) || str_starts_with((string) $key, '_')) continue;
                $walk($child, $path === '' ? (string) $key : $path . '.' . $key, $depth + 1);
            }
        };
        $walk($device, '', 0);
        usort($rows, static fn($a, $b) => strcmp($a['path'], $b['path']));
        return ['parameters' => $rows, 'truncated' => $truncated,
            'note' => 'Caminhos presentes no ACS; ausência não comprova falta de suporte. Nenhuma atualização ou tarefa foi enviada à CPE.'];
    }
}
