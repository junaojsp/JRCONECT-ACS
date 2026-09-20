<?php
declare(strict_types=1);

/** Read-only capability inspection. Candidate paths NEVER authorize writes. */
final class WifiNetworkGroups {
    private const LIMIT = 40000;
    private array $nodes = [];
    private bool $truncated = false;

    public function __construct(private array $device) {
        foreach (['Device', 'InternetGatewayDevice'] as $root) {
            if (isset($device[$root]) && is_array($device[$root])) $this->walk($device[$root], $root, 0);
        }
    }
    private function walk(array $node, string $path, int $depth): void {
        if ($depth > 30 || count($this->nodes) >= self::LIMIT) { $this->truncated = true; return; }
        $this->nodes[$path] = $node;
        foreach ($node as $key => $child) {
            if (str_starts_with((string)$key, '_') || !is_array($child)) continue;
            if (!preg_match('/^(?:[A-Za-z_][A-Za-z0-9_-]*|[0-9]+)$/D', (string)$key)) continue;
            $this->walk($child, $path . '.' . $key, $depth + 1);
        }
    }
    private function value(string $path): mixed { return $this->nodes[$path]['_value'] ?? null; }
    private static function text(mixed $value): ?string {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 128 || preg_match('/[\x00-\x1f\x7f]/', $value)) return null;
        return $value;
    }
    private static function boolean(mixed $value): ?bool {
        if (in_array($value, [true, 1, '1', 'true'], true)) return true;
        if (in_array($value, [false, 0, '0', 'false'], true)) return false;
        return null;
    }
    public static function standards(mixed $raw): array {
        if (!is_string($raw) || strlen($raw) > 96) return [];
        $text = preg_replace('/(?:ieee\s*)?802\.11/i', '', strtolower(trim($raw)));
        $parts = preg_split('/[\s,\/+_-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) return [];
        $all = [];
        foreach ($parts as $part) {
            preg_match_all('/ac|ax|be|[abgn]/', $part, $m);
            if (implode('', $m[0]) !== $part) return [];
            $all = array_merge($all, $m[0]);
        }
        return array_values(array_unique($all));
    }
    private static function feature(string $path): ?string {
        if (preg_match('/band[_-]?steer|smart[_-]?connect/i', $path)) return 'unified';
        if (preg_match('/multi[_-]?link|(?:^|[._-])MLO(?:Enable|Enabled|Capability|Capabilities|Supported|Config|Configuration|Mode|[._-]|$)/i', $path)) return 'mlo';
        return null;
    }
    private static function date(mixed $value): ?string {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return $value;
        return null;
    }
    public function inspect(): array {
        $radios = []; $candidates = [];
        $supported = []; $configured = []; $bands = [];
        $radioPattern = '~^(Device\.WiFi\.Radio\.\d+|InternetGatewayDevice\.LANDevice\.\d+\.WLANConfiguration\.\d+)\.(SupportedStandards|OperatingStandards|Standard|OperatingFrequencyBand|SupportedFrequencyBands|X_FH_OperatingFrequencyBand|X_HW_FrequencyBand)$~';
        foreach ($this->nodes as $path => $node) {
            if (preg_match($radioPattern, $path, $m)) {
                [$full, $base, $field] = $m;
                $radios[$base] ??= ['path' => $base, 'supported' => [], 'configured' => [], 'bands' => []];
                if (in_array($field, ['SupportedStandards', 'OperatingStandards', 'Standard'], true)) {
                    $tokens = self::standards($node['_value'] ?? null);
                    $key = $field === 'SupportedStandards' ? 'supported' : 'configured';
                    $radios[$base][$key] = array_values(array_unique(array_merge($radios[$base][$key], $tokens)));
                    if ($key === 'supported') $supported = array_merge($supported, $tokens);
                    else $configured = array_merge($configured, $tokens);
                } else {
                    $raw = $node['_value'] ?? null;
                    if (is_string($raw)) foreach (explode(',', $raw) as $band) {
                        $band = strtolower(preg_replace('/\s+/', '', $band));
                        $normal = match($band) { '2.4ghz', '2.4g', '2.4' => '2.4', '5ghz', '5g', '5', '5.8ghz', '5.8' => '5', '6ghz', '6g', '6' => '6', default => null };
                        if ($normal !== null) { $bands[] = $normal; $radios[$base]['bands'][] = $normal; }
                    }
                }
            }
            $feature = self::feature($path);
            if ($feature === null || (!array_key_exists('_value', $node) && ($node['_object'] ?? null) !== false)) continue;
            // Metadata only. No SSIDs, secrets, MACs, identifiers or arbitrary parameter values.
            $candidates[] = ['feature' => $feature, 'path' => $path,
                'type' => in_array($node['_type'] ?? '', ['xsd:boolean','xsd:string','xsd:int','xsd:unsignedInt'], true) ? $node['_type'] : null,
                'writable_reported' => self::boolean($node['_writable'] ?? null),
                'collected' => array_key_exists('_value', $node),
                'reported_at' => self::date($node['_timestamp'] ?? null)];
        }
        $count = ['unified' => 0, 'mlo' => 0];
        foreach ($candidates as $c) $count[$c['feature']]++;
        $version = $this->value('Device.DeviceInfo.SoftwareVersion') ?? $this->value('InternetGatewayDevice.DeviceInfo.SoftwareVersion');
        $result = [
            'schema' => 'wifi-network-groups-v1', 'read_only' => true,
            'device' => [
                'manufacturer' => self::text($this->device['_deviceId']['_Manufacturer'] ?? $this->value('Device.DeviceInfo.Manufacturer') ?? $this->value('InternetGatewayDevice.DeviceInfo.Manufacturer')),
                'model' => self::text($this->device['_deviceId']['_ProductClass'] ?? $this->value('Device.DeviceInfo.ModelName') ?? $this->value('InternetGatewayDevice.DeviceInfo.ModelName')),
                'firmware' => self::text($version)
            ],
            'technology' => ['supported' => array_values(array_unique($supported)), 'configured' => array_values(array_unique($configured))],
            'bands_reported' => array_values(array_unique($bands)),
            'unified' => ['state' => $count['unified'] ? 'mapping_required' : 'not_reported', 'candidate_count' => $count['unified'], 'can_manage' => false],
            'mlo' => ['state' => $count['mlo'] ? 'mapping_required' : 'not_reported', 'candidate_count' => $count['mlo'], 'can_manage' => false],
            'parameters' => array_slice($candidates, 0, 256),
            'radios' => array_values($radios),
            'incomplete' => $this->truncated || count($candidates) > 256,
            'mapping_note' => 'No manufacturer/firmware control mapping has been validated. Candidate paths and matching SSIDs do not prove a group or authorize writes.'
        ];
        return $result;
    }
}
