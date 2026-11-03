<?php

namespace App\Services;

/** Turns the browser details recorded with an attempt into a device type, brand, system and browser people can read. */
class DeviceLabel
{
    /** Model-number prefixes Android browsers report, mapped to the brand. */
    private const BRANDS = [
        'SM-' => 'Samsung', 'GT-' => 'Samsung', 'SAMSUNG' => 'Samsung', 'TECNO' => 'Tecno', 'Infinix' => 'Infinix', 'itel' => 'itel',
        'Redmi' => 'Xiaomi', 'POCO' => 'Xiaomi', 'Mi ' => 'Xiaomi', 'Xiaomi' => 'Xiaomi', 'CPH' => 'Oppo', 'OPPO' => 'Oppo', 'RMX' => 'Realme',
        'vivo' => 'vivo', 'Pixel' => 'Google', 'moto' => 'Motorola', 'Nokia' => 'Nokia', 'HUAWEI' => 'Huawei', 'Lenovo' => 'Lenovo',
        'TB-' => 'Lenovo', 'ONEPLUS' => 'OnePlus', 'LG-' => 'LG', 'Kindle' => 'Amazon', 'KF' => 'Amazon',
    ];

    /** @return array{type: string, brand: string, model: string, system: string, browser: string} */
    public static function describe(?array $device): array
    {
        $ua = (string) ($device['ua'] ?? '');
        $touch = (int) ($device['touch'] ?? 0);
        // iPads ask for the desktop site and report a Mac; only touch support gives them away.
        $ipad = str_contains($ua, 'iPad') || (str_contains($ua, 'Macintosh') && $touch > 1);
        $model = trim((string) ($device['model'] ?? ''));
        if ($model === '' && preg_match('/Android [\d.]+; (?:[a-z]{2}[-_][a-z]{2}; )?([^;)]+?)(?: Build|\))/i', $ua, $m) && strtoupper(trim($m[1])) !== 'K') {
            $model = trim($m[1]);
        }
        $type = match (true) {
            $ua === '' => 'Unknown',
            $ipad, str_contains($ua, 'Tablet') => 'Tablet',
            str_contains($ua, 'Android') => str_contains($ua, 'Mobile') ? 'Phone' : 'Tablet',
            str_contains($ua, 'iPhone') || str_contains($ua, 'Mobile') => 'Phone',
            default => 'Computer',
        };
        $brand = match (true) {
            $ipad, str_contains($ua, 'iPhone'), str_contains($ua, 'Macintosh') => 'Apple',
            default => collect(self::BRANDS)->first(fn ($name, $prefix) => stripos($model, $prefix) === 0) ?? ($type === 'Computer' ? '' : 'Other'),
        };
        $system = match (true) {
            $ipad => 'iPadOS',
            str_contains($ua, 'iPhone') => 'iOS',
            (bool) preg_match('/Android ([\d]+)/', $ua, $v) => 'Android '.$v[1],
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };
        $browser = match (true) {
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'Firefox') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Chrome') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => '',
        };
        if (($device['standalone'] ?? false) && $browser !== '') {
            $browser .= ' (installed app)';
        }

        return ['type' => $type, 'brand' => $brand, 'model' => $model, 'system' => $system, 'browser' => $browser];
    }

    /** One line such as "Tablet · Samsung SM-T505 · Android 13 · Chrome". */
    public static function line(?array $device): string
    {
        if (! $device) {
            return 'Device not recorded';
        }
        $d = self::describe($device);
        $name = trim(($d['brand'] === 'Other' ? '' : $d['brand']).' '.($d['brand'] === 'Apple' ? '' : $d['model']));

        return collect([$d['type'], $name, $d['system'], $d['browser']])->filter()->join(' · ');
    }
}
