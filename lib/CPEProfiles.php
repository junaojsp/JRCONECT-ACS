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

    public static function optical(array $device): array
    {
        $profile = self::detect($device);
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

    public static function enrich(array $device, array $data): array
    {
        $profile = self::detect($device);
        if (!$profile) {
            $data['cpe_profile'] = null;
            return $data;
        }

        $data['cpe_profile'] = $profile['id'];
        $data['cpe_profile_vendor'] = $profile['vendor'] ?? null;
        $data['cpe_profile_model'] = $profile['model'] ?? null;

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

        $optical = self::optical($device);
        if (($data['rx_power'] ?? 'N/A') === 'N/A' && $optical['rx_power'] !== null) {
            $data['rx_power'] = number_format($optical['rx_power'], 2, '.', '');
        }
        if (($data['temperature'] ?? 'N/A') === 'N/A' && $optical['temperature'] !== null) {
            $data['temperature'] = number_format($optical['temperature'], 1, '.', '');
        }

        return $data;
    }
}
