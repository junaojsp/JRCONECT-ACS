<?php
namespace App;

/**
 * Central registry for vendor/model-specific CPE parameter mappings.
 * Keeps device quirks out of page/UI code.
 */
class CPEProfiles
{
    public static function profiles(): array
    {
        return [
            'huawei_eg8145v5' => [
                'vendor' => 'Huawei',
                'model' => 'EG8145V5',
                'match' => ['HUAWEI', 'EG8145V5'],
                'wifi' => [
                    'ssid_24' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                        'Device.WiFi.SSID.1.SSID',
                    ],
                    'ssid_5' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID',
                        'Device.WiFi.SSID.5.SSID',
                        'Device.WiFi.SSID.2.SSID',
                    ],
                    'channel_24' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Channel'],
                    'channel_5' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.Channel'],
                    'enabled_24' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.RadioEnabled',
                    ],
                    'enabled_5' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.Enable',
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.RadioEnabled',
                    ],
                    'security_24' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.IEEE11iEncryptionModes',
                    ],
                    'security_5' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.BeaconType',
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.IEEE11iEncryptionModes',
                    ],
                    'associated_24' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice',
                    'associated_5' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.AssociatedDevice',
                ],
                'optical' => [
                    'rx' => [
                        'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower',
                        'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower',
                        'InternetGatewayDevice.WANDevice.1.X_HW_GponInterfaceConfig.RXPower',
                        'InternetGatewayDevice.WANDevice.1.X_HW_GponInterfaceConfig.RxPower',
                        'Device.Optical.Interface.1.RxPower',
                        'VirtualParameters.RXPower',
                    ],
                    'tx' => [
                        'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TXPower',
                        'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TXPower',
                        'InternetGatewayDevice.WANDevice.1.X_HW_GponInterfaceConfig.TXPower',
                        'InternetGatewayDevice.WANDevice.1.X_HW_GponInterfaceConfig.TxPower',
                        'Device.Optical.Interface.1.TxPower',
                        'VirtualParameters.TXPower',
                    ],
                    'temperature' => [
                        'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TransceiverTemperature',
                        'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TransceiverTemperature',
                        'InternetGatewayDevice.DeviceInfo.Temperature',
                        'VirtualParameters.Temperature',
                        'VirtualParameters.gettemp',
                    ],
                    'voltage' => [
                        'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.Voltage',
                        'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.Voltage',
                        'InternetGatewayDevice.WANDevice.1.X_HW_GponInterfaceConfig.Voltage',
                    ],
                ],
                'pppoe' => [
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.*.WANPPPConnection.*.Username',
                    'Device.PPP.Interface.*.Username',
                ],
            ],
            'huawei_hg8145v5' => [
                'vendor' => 'Huawei',
                'model' => 'HG8145V5',
                'match' => ['HUAWEI', 'HG8145V5'],
                'wifi' => [
                    'ssid_24' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                        'Device.WiFi.SSID.1.SSID',
                    ],
                    'ssid_5' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID',
                        'Device.WiFi.SSID.5.SSID',
                        'Device.WiFi.SSID.2.SSID',
                    ],
                    'channel_24' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Channel'],
                    'channel_5' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.Channel'],
                    'enabled_24' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.RadioEnabled',
                    ],
                    'enabled_5' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.Enable',
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.RadioEnabled',
                    ],
                    'security_24' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.IEEE11iEncryptionModes',
                    ],
                    'security_5' => [
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.BeaconType',
                        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.IEEE11iEncryptionModes',
                    ],
                    'associated_24' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice',
                    'associated_5' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.AssociatedDevice',
                ],
                'optical' => [
                    'rx' => [
                        'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower',
                        'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower',
                        'InternetGatewayDevice.WANDevice.1.X_HW_GponInterfaceConfig.RXPower',
                        'InternetGatewayDevice.WANDevice.1.X_HW_GponInterfaceConfig.RxPower',
                        'Device.Optical.Interface.1.RxPower',
                        'VirtualParameters.RXPower',
                    ],
                    'tx' => [
                        'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TXPower',
                        'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TXPower',
                        'InternetGatewayDevice.WANDevice.1.X_HW_GponInterfaceConfig.TXPower',
                        'InternetGatewayDevice.WANDevice.1.X_HW_GponInterfaceConfig.TxPower',
                        'Device.Optical.Interface.1.TxPower',
                        'VirtualParameters.TXPower',
                    ],
                    'temperature' => [
                        'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TransceiverTemperature',
                        'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TransceiverTemperature',
                        'InternetGatewayDevice.DeviceInfo.Temperature',
                        'VirtualParameters.Temperature',
                        'VirtualParameters.gettemp',
                    ],
                    'voltage' => [
                        'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.Voltage',
                        'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.Voltage',
                        'InternetGatewayDevice.WANDevice.1.X_HW_GponInterfaceConfig.Voltage',
                    ],
                ],
                'pppoe' => [
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.*.WANPPPConnection.*.Username',
                    'Device.PPP.Interface.*.Username',
                ],
            ],
            'fiberhome_hg6143d3' => [
                'vendor' => 'FiberHome',
                'model' => 'HG6143D3',
                'match' => ['FIBERHOME', 'HG6143D3'],
                'wifi' => [
                    'ssid_24' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'],
                    'ssid_5' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID','InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID'],
                ],
                'optical' => [
                    'rx' => ['VirtualParameters.RXPower','InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower'],
                    'tx' => ['VirtualParameters.TXPower','InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TXPower'],
                    'temperature' => ['VirtualParameters.gettemp','VirtualParameters.Temperature'],
                    'voltage' => [],
                ],
            ],
            'nokia_g240wa' => [
                'vendor' => 'Nokia',
                'model' => 'G-240W-A',
                'match' => ['NOKIA', 'G-240W-A'],
                'wifi' => [
                    'ssid_24' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'],
                    'ssid_5' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID','InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID'],
                ],
                'optical' => [
                    'rx' => ['VirtualParameters.RXPower','Device.Optical.Interface.1.RxPower'],
                    'tx' => ['VirtualParameters.TXPower','Device.Optical.Interface.1.TxPower'],
                    'temperature' => ['VirtualParameters.Temperature'],
                    'voltage' => [],
                ],
            ],
        ];
    }


    private static function deviceIdentity(array $device): array
    {
        $manufacturer = (string)(
            $device['_deviceId']['_Manufacturer']
            ?? self::get($device, 'InternetGatewayDevice.DeviceInfo.Manufacturer')
            ?? self::get($device, 'Device.DeviceInfo.Manufacturer')
            ?? 'Desconhecido'
        );
        $model = (string)(
            $device['_deviceId']['_ProductClass']
            ?? self::get($device, 'InternetGatewayDevice.DeviceInfo.ProductClass')
            ?? self::get($device, 'Device.DeviceInfo.ProductClass')
            ?? 'Desconhecido'
        );
        return [$manufacturer, $model];
    }

    private static function collectLeaves($node, string $path, array &$leaves, int $depth = 0): void
    {
        if (!is_array($node) || $depth > 18) return;

        if (array_key_exists('_value', $node)) {
            $value = $node['_value'];
            if (is_scalar($value) || $value === null) {
                $leaves[$path] = $value;
            }
        }

        foreach ($node as $key => $value) {
            $key = (string)$key;
            if ($key === '' || str_starts_with($key, '_')) continue;

            $next = $path === '' ? $key : $path . '.' . $key;
            if (preg_match('/password|passphrase|presharedkey|secret|authkey|credential/i', $next)) {
                continue;
            }

            if (is_array($value)) {
                self::collectLeaves($value, $next, $leaves, $depth + 1);
            } elseif (is_scalar($value) || $value === null) {
                $leaves[$next] = $value;
            }
        }
    }

    private static function appendCandidate(array &$map, string $key, ?string $path): void
    {
        if (!$path) return;
        if (!isset($map[$key]) || !is_array($map[$key])) $map[$key] = [];
        if (!in_array($path, $map[$key], true)) $map[$key][] = $path;
    }

    private static function firstMatchingPath(array $leaves, array $patterns, array $prefer = []): ?string
    {
        $matches = [];
        foreach ($leaves as $path => $value) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $path)) {
                    $score = 0;
                    foreach ($prefer as $needle) {
                        if (stripos($path, $needle) !== false) $score += 10;
                    }
                    if ($value !== null && trim((string)$value) !== '') $score += 2;
                    $matches[] = ['path' => $path, 'score' => $score];
                    break;
                }
            }
        }
        if (!$matches) return null;
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
        return $matches[0]['path'];
    }

    private static function discoverWlanInstances(array $leaves): array
    {
        $instances = [];
        foreach ($leaves as $path => $value) {
            if (!preg_match('/^(InternetGatewayDevice\.LANDevice\.\d+\.WLANConfiguration\.(\d+))\.SSID$/i', $path, $m)) {
                continue;
            }
            if ($value === null || trim((string)$value) === '') continue;

            $base = $m[1];
            $index = (int)$m[2];
            $possible = (string)($leaves[$base . '.PossibleChannels'] ?? '');
            $channel = $leaves[$base . '.Channel'] ?? null;
            $lower = (string)($leaves[$base . '.LowerLayers'] ?? '');
            $band = 'unknown';

            if (preg_match('/(^|,)(3[6-9]|4\d|5\d|6[0-4]|1(?:49|53|57|61))($|,)/', str_replace(' ', '', $possible))) {
                $band = '5';
            } elseif (is_numeric($channel) && (int)$channel >= 36) {
                $band = '5';
            } elseif (stripos($lower, 'Radio.2') !== false || $index >= 5) {
                $band = '5';
            } elseif ((is_numeric($channel) && (int)$channel <= 14) || preg_match('/(^|,)1(,|$)/', str_replace(' ', '', $possible)) || $index === 1) {
                $band = '24';
            }

            $instances[] = [
                'base' => $base,
                'index' => $index,
                'band' => $band,
                'ssid_path' => $path,
            ];
        }
        usort($instances, fn($a, $b) => $a['index'] <=> $b['index']);
        return $instances;
    }

    public static function discover(array $device): array
    {
        [$manufacturer, $model] = self::deviceIdentity($device);
        $leaves = [];
        self::collectLeaves($device, '', $leaves);

        $wifi = [];
        $instances = self::discoverWlanInstances($leaves);
        $band24 = null;
        $band5 = null;
        foreach ($instances as $instance) {
            if ($instance['band'] === '24' && $band24 === null) $band24 = $instance;
            if ($instance['band'] === '5' && $band5 === null) $band5 = $instance;
        }
        if ($band24 === null && isset($instances[0])) $band24 = $instances[0];
        if ($band5 === null && isset($instances[1])) $band5 = $instances[1];

        $mapBand = function (?array $instance, string $suffix) use (&$wifi, $leaves): void {
            if (!$instance) return;
            $base = $instance['base'];
            self::appendCandidate($wifi, 'ssid_' . $suffix, $instance['ssid_path']);
            self::appendCandidate($wifi, 'channel_' . $suffix, isset($leaves[$base . '.Channel']) ? $base . '.Channel' : null);
            self::appendCandidate(
                $wifi,
                'enabled_' . $suffix,
                isset($leaves[$base . '.Enable']) ? $base . '.Enable' :
                    (isset($leaves[$base . '.RadioEnabled']) ? $base . '.RadioEnabled' : null)
            );
            self::appendCandidate(
                $wifi,
                'security_' . $suffix,
                isset($leaves[$base . '.BeaconType']) ? $base . '.BeaconType' :
                    (isset($leaves[$base . '.IEEE11iEncryptionModes']) ? $base . '.IEEE11iEncryptionModes' : null)
            );
            $wifi['associated_' . $suffix] = $base . '.AssociatedDevice';
        };
        $mapBand($band24, '24');
        $mapBand($band5, '5');

        // TR-181 fallback
        if (empty($wifi['ssid_24'])) {
            self::appendCandidate($wifi, 'ssid_24', self::firstMatchingPath(
                $leaves,
                ['/^Device\.WiFi\.SSID\.\d+\.SSID$/i'],
                ['SSID.1.']
            ));
        }
        if (empty($wifi['ssid_5'])) {
            self::appendCandidate($wifi, 'ssid_5', self::firstMatchingPath(
                $leaves,
                ['/^Device\.WiFi\.SSID\.\d+\.SSID$/i'],
                ['SSID.5.', 'SSID.2.']
            ));
        }

        $optical = [
            'rx' => [],
            'tx' => [],
            'temperature' => [],
            'voltage' => [],
        ];
        self::appendCandidate($optical, 'rx', self::firstMatchingPath(
            $leaves,
            ['/(^|\.)(RXPower|RxPower|ReceivePower|OpticalRxPower|TransceiverRxPower)$/i'],
            ['gpon', 'optic', 'transceiver', 'virtualparameters', 'pon']
        ));
        self::appendCandidate($optical, 'tx', self::firstMatchingPath(
            $leaves,
            ['/(^|\.)(TXPower|TxPower|TransmitPower|OpticalTxPower|TransceiverTxPower)$/i'],
            ['gpon', 'optic', 'transceiver', 'virtualparameters', 'pon']
        ));
        self::appendCandidate($optical, 'temperature', self::firstMatchingPath(
            $leaves,
            ['/(^|\.)(TransceiverTemperature|OpticTemperature|Temperature|gettemp)$/i'],
            ['gpon', 'optic', 'transceiver', 'virtualparameters']
        ));
        self::appendCandidate($optical, 'voltage', self::firstMatchingPath(
            $leaves,
            ['/(^|\.)(Voltage|TransceiverVoltage|OpticVoltage)$/i'],
            ['gpon', 'optic', 'transceiver']
        ));

        $pppoe = [];
        $pppPath = self::firstMatchingPath(
            $leaves,
            [
                '/WANPPPConnection\.\d+\.Username$/i',
                '/Device\.PPP\.Interface\.\d+\.Username$/i'
            ],
            ['WANPPPConnection', 'PPP.Interface']
        );
        if ($pppPath) $pppoe[] = $pppPath;

        $slug = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '_', trim($manufacturer . '_' . $model)));
        $slug = trim($slug, '_');
        $profile = [
            'id' => 'auto_' . ($slug ?: 'cpe'),
            'vendor' => $manufacturer,
            'model' => $model,
            'match' => [],
            'source' => 'auto-discovery',
            'wifi' => $wifi,
            'optical' => $optical,
            'pppoe' => $pppoe,
        ];

        $profile['discovery'] = [
            'leaf_count' => count($leaves),
            'wlan_instances' => count($instances),
            'has_wifi_24' => !empty($wifi['ssid_24']),
            'has_wifi_5' => !empty($wifi['ssid_5']),
            'has_optical_rx' => !empty($optical['rx']),
            'has_optical_tx' => !empty($optical['tx']),
            'has_pppoe' => !empty($pppoe),
        ];

        return $profile;
    }

    private static function mergeProfiles(array $static, array $auto): array
    {
        $merged = $static;
        $merged['source'] = 'static+auto-discovery';
        $merged['discovery'] = $auto['discovery'] ?? [];

        foreach (['wifi', 'optical'] as $section) {
            if (!isset($merged[$section])) $merged[$section] = [];
            foreach (($auto[$section] ?? []) as $key => $value) {
                if (str_starts_with((string)$key, 'associated_')) {
                    if (empty($merged[$section][$key])) $merged[$section][$key] = $value;
                    continue;
                }

                $staticValues = $merged[$section][$key] ?? [];
                if (!is_array($staticValues)) $staticValues = [$staticValues];
                $autoValues = is_array($value) ? $value : [$value];
                $merged[$section][$key] = array_values(array_unique(array_filter(array_merge($staticValues, $autoValues))));
            }
        }

        $merged['pppoe'] = array_values(array_unique(array_filter(array_merge(
            is_array($merged['pppoe'] ?? null) ? $merged['pppoe'] : [],
            is_array($auto['pppoe'] ?? null) ? $auto['pppoe'] : []
        ))));

        return $merged;
    }

    public static function resolve(array $device, bool $deepDiscovery = true): array
    {
        $static = self::detect($device);

        // Fast device lists should not recursively scan the complete TR-069 tree.
        if (!$deepDiscovery) {
            if (!$static) return [];
            $static['source'] = 'static';
            $static['discovery'] = [];
            return $static;
        }

        $auto = self::discover($device);
        return $static ? self::mergeProfiles($static, $auto) : $auto;
    }

    public static function discoveryReport(array $device): array
    {
        $profile = self::resolve($device);
        return [
            'id' => $profile['id'] ?? null,
            'vendor' => $profile['vendor'] ?? null,
            'model' => $profile['model'] ?? null,
            'source' => $profile['source'] ?? 'static',
            'discovery' => $profile['discovery'] ?? [],
            'wifi' => $profile['wifi'] ?? [],
            'optical' => $profile['optical'] ?? [],
            'pppoe' => $profile['pppoe'] ?? [],
        ];
    }

    public static function detect(array $device): ?array
    {
        $manufacturer = strtoupper((string)(
            $device['_deviceId']['_Manufacturer']
            ?? self::get($device, 'InternetGatewayDevice.DeviceInfo.Manufacturer')
            ?? self::get($device, 'Device.DeviceInfo.Manufacturer')
            ?? ''
        ));
        $model = strtoupper((string)(
            $device['_deviceId']['_ProductClass']
            ?? self::get($device, 'InternetGatewayDevice.DeviceInfo.ProductClass')
            ?? self::get($device, 'Device.DeviceInfo.ProductClass')
            ?? ''
        ));
        $haystack = $manufacturer . ' ' . $model;

        foreach (self::profiles() as $id => $profile) {
            $ok = true;
            foreach (($profile['match'] ?? []) as $token) {
                if (!str_contains($haystack, strtoupper((string)$token))) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $profile['id'] = $id;
                return $profile;
            }
        }
        return null;
    }

    public static function get(array $device, string $path)
    {
        $value = $device;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) return null;
            $value = $value[$key];
        }
        if (is_array($value) && array_key_exists('_value', $value)) return $value['_value'];
        return is_scalar($value) ? $value : null;
    }

    private static function first(array $device, array $paths)
    {
        foreach ($paths as $path) {
            $value = self::get($device, $path);
            if ($value !== null && trim((string)$value) !== '') return $value;
        }
        return null;
    }

    private static function countAssociated(array $device, ?string $path): int
    {
        if (!$path) return 0;

        $node = $device;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) return 0;
            $node = $node[$key];
        }
        if (!is_array($node)) return 0;

        $count = 0;
        foreach ($node as $index => $item) {
            if (strpos((string)$index, '_') === 0 || !is_array($item)) continue;

            $auth = $item['AssociatedDeviceAuthenticationState']['_value']
                ?? $item['AssociatedDeviceAuthenticationState']
                ?? null;
            $mac = $item['AssociatedDeviceMACAddress']['_value']
                ?? $item['AssociatedDeviceMACAddress']
                ?? null;

            if ($auth === true || $auth === 1 || $auth === '1' || (!empty($mac) && $auth !== false && $auth !== 0 && $auth !== '0')) {
                $count++;
            }
        }
        return $count;
    }

    private static function boolValue($value): ?bool
    {
        if ($value === null || $value === '') return null;
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return ((int)$value) !== 0;
        $text = strtolower(trim((string)$value));
        if (in_array($text, ['true','on','enabled','up','yes'], true)) return true;
        if (in_array($text, ['false','off','disabled','down','no'], true)) return false;
        return null;
    }

    private static function normalizeOptical($value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) return null;
        $n = (float)$value;

        // Huawei/FiberHome firmware may expose hundredths or raw scaled values.
        if ($n > 1000) $n = $n / 100;
        if ($n < -1000) $n = $n / 100;
        return $n;
    }

    public static function optical(array $device, bool $deepDiscovery = true): array
    {
        $profile = self::resolve($device, $deepDiscovery);
        if (!$profile) return [];

        $map = $profile['optical'] ?? [];
        return [
            'profile' => $profile['id'] ?? null,
            'rx_power' => self::normalizeOptical(self::first($device, $map['rx'] ?? [])),
            'tx_power' => self::normalizeOptical(self::first($device, $map['tx'] ?? [])),
            'temperature' => self::normalizeOptical(self::first($device, $map['temperature'] ?? [])),
            'voltage' => self::normalizeOptical(self::first($device, $map['voltage'] ?? [])),
        ];
    }

    public static function enrich(array $device, array $data, bool $deepDiscovery = true): array
    {
        $profile = self::resolve($device, $deepDiscovery);
        if (!$profile) {
            $data['cpe_profile'] = null;
            return $data;
        }

        $data['cpe_profile'] = $profile['id'];
        $data['cpe_profile_vendor'] = $profile['vendor'] ?? null;
        $data['cpe_profile_model'] = $profile['model'] ?? null;
        $data['cpe_profile_source'] = $profile['source'] ?? 'static';
        $data['cpe_profile_discovery'] = $profile['discovery'] ?? [];

        $wifi = $profile['wifi'] ?? [];
        $ssid24 = self::first($device, $wifi['ssid_24'] ?? []);
        $ssid5 = self::first($device, $wifi['ssid_5'] ?? []);

        if ($ssid24 !== null) {
            $data['wifi_ssid_24ghz'] = (string)$ssid24;
            if (empty($data['wifi_ssid']) || $data['wifi_ssid'] === 'N/A') $data['wifi_ssid'] = (string)$ssid24;
        }
        if ($ssid5 !== null) $data['wifi_ssid_5ghz'] = (string)$ssid5;

        $channel24 = self::first($device, $wifi['channel_24'] ?? []);
        $channel5 = self::first($device, $wifi['channel_5'] ?? []);
        $enabled24 = self::boolValue(self::first($device, $wifi['enabled_24'] ?? []));
        $enabled5 = self::boolValue(self::first($device, $wifi['enabled_5'] ?? []));
        $security24 = self::first($device, $wifi['security_24'] ?? []);
        $security5 = self::first($device, $wifi['security_5'] ?? []);

        if ($channel24 !== null) $data['wifi_channel_24ghz'] = $channel24;
        if ($channel5 !== null) $data['wifi_channel_5ghz'] = $channel5;
        if ($enabled24 !== null) $data['wifi_enabled_24ghz'] = $enabled24;
        if ($enabled5 !== null) $data['wifi_enabled_5ghz'] = $enabled5;
        if ($security24 !== null) $data['wifi_security_24ghz'] = (string)$security24;
        if ($security5 !== null) $data['wifi_security_5ghz'] = (string)$security5;

        $data['wifi_clients_24ghz'] = self::countAssociated($device, $wifi['associated_24'] ?? null);
        $data['wifi_clients_5ghz'] = self::countAssociated($device, $wifi['associated_5'] ?? null);

        if (($data['pppoe_username'] ?? 'N/A') === 'N/A' || trim((string)($data['pppoe_username'] ?? '')) === '') {
            $pppoe = self::first($device, is_array($profile['pppoe'] ?? null) ? $profile['pppoe'] : []);
            if ($pppoe !== null) $data['pppoe_username'] = (string)$pppoe;
        }

        $optical = self::optical($device, $deepDiscovery);
        if (($data['rx_power'] ?? 'N/A') === 'N/A' && $optical['rx_power'] !== null) {
            $data['rx_power'] = number_format($optical['rx_power'], 2, '.', '');
        }
        if (($data['temperature'] ?? 'N/A') === 'N/A' && $optical['temperature'] !== null) {
            $data['temperature'] = number_format($optical['temperature'], 1, '.', '');
        }

        return $data;
    }
}
