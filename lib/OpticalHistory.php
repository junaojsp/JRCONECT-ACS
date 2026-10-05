<?php
declare(strict_types=1);

use App\CPEProfiles;

function acsOpticalSerial(string $value): string
{
    return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', trim($value)));
}

function acsOpticalPower(mixed $value): ?float
{
    if (!is_scalar($value) || is_bool($value) || trim((string)$value) === '') return null;
    $text = str_replace(',', '.', trim((string)$value));
    if (!is_numeric($text)) return null;
    $number = (float)$text;
    return is_finite($number) && $number >= -60 && $number <= 10 ? $number : null;
}

function acsOpticalMeasuredAt(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value) || trim($value) === '') return null;
    $value = trim($value);
    $timezone = new DateTimeZone('America/Sao_Paulo');
    foreach (['!Y-m-d H:i:s', '!d/m/Y H:i:s', '!Y-m-d\TH:i:sP', '!Y-m-d\TH:i:s.vP'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) {
            return $date->setTimezone(new DateTimeZone('UTC'));
        }
    }
    return null;
}

function acsOpticalSample(string $serial, array $fiber, int $now): ?array
{
    $serial = acsOpticalSerial($serial);
    if ($serial === '' || strlen($serial) > 128) return null;
    $rx = acsOpticalPower($fiber['sinal_rx'] ?? null);
    if ($rx === null) return null;
    $rawDate = $fiber['data_sinal'] ?? null;
    $measured = acsOpticalMeasuredAt($rawDate);
    // A supplied but invalid timestamp is not silently replaced by collection time.
    if ($rawDate !== null && $rawDate !== '' && !$measured) return null;
    if ($measured && ($measured->getTimestamp() > $now + 300 || $measured->getTimestamp() < $now - 90 * 86400)) return null;
    $sampleTime = $measured ? $measured->getTimestamp() : $now;
    $dedupTime = $measured ? $measured->format('Y-m-d H:i:s') : (string)(intdiv($now, 900) * 900);
    return ['serial' => $serial, 'source' => 'IXC',
        'sample_key' => hash('sha256', $serial . '|IXC|' . ($measured ? 'measured|' : 'observed|') . $dedupTime),
        'sample_at' => gmdate('Y-m-d H:i:s', $sampleTime),
        'measured_at' => $measured?->format('Y-m-d H:i:s'),
        'collected_at' => gmdate('Y-m-d H:i:s', $now),
        'rx_power' => $rx, 'tx_power' => acsOpticalPower($fiber['sinal_tx'] ?? null)];
}

function acsOpticalMatchSamples(array $devices, array $fibers, int $now): array
{
    $index = [];
    foreach ($fibers as $position => $fiber) {
        foreach (CPEProfiles::serialAliases((string)($fiber['mac'] ?? '')) as $alias) {
            $index[$alias][$position] = $fiber;
        }
    }
    $seen = [];
    $samples = [];
    $stats = ['onus' => 0, 'without_link' => 0, 'ambiguous' => 0, 'without_valid_reading' => 0];
    foreach ($devices as $device) {
        $serial = acsOpticalSerial((string)($device['_deviceId']['_SerialNumber']
            ?? $device['InternetGatewayDevice']['DeviceInfo']['SerialNumber']['_value']
            ?? $device['Device']['DeviceInfo']['SerialNumber']['_value'] ?? ''));
        if ($serial === '' || isset($seen[$serial])) continue;
        $seen[$serial] = true;
        $stats['onus']++;
        $matches = [];
        foreach (CPEProfiles::serialAliases($serial) as $alias) {
            foreach ($index[$alias] ?? [] as $position => $fiber) $matches[$position] = $fiber;
        }
        if (!$matches) { $stats['without_link']++; continue; }
        if (count($matches) !== 1) { $stats['ambiguous']++; continue; }
        $sample = acsOpticalSample($serial, reset($matches), $now);
        if (!$sample) { $stats['without_valid_reading']++; continue; }
        $samples[] = $sample;
    }
    return ['samples' => $samples, 'stats' => $stats];
}
