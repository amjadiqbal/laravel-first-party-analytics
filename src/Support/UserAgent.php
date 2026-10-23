<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Support;

/**
 * A small user agent parser: browser family, OS family and device type. Only the parsed values are
 * stored, never the user agent itself. Order matters: Edge and Opera also claim Chrome, Chrome claims Safari.
 */
final class UserAgent
{
    private const array BROWSERS = [
        'Edge' => '/\bEdg(e|A|iOS)?\//',
        'Opera' => '/\b(OPR|Opera|OPT)\//',
        'Samsung Internet' => '/\bSamsungBrowser\//',
        'Vivaldi' => '/\bVivaldi\//',
        'Yandex' => '/\bYaBrowser\//',
        'Firefox' => '/\b(Firefox|FxiOS)\//',
        'Chrome' => '/\b(Chrome|CriOS|Chromium)\//',
        'Safari' => '/\bVersion\/[\d.]+.*\bSafari\//',
        'Internet Explorer' => '/\b(MSIE |Trident\/)/',
    ];

    private const array SYSTEMS = [
        'iPadOS' => '/\biPad\b/',
        'iOS' => '/\b(iPhone|iPod)\b/',
        'Android' => '/\bAndroid\b/',
        'ChromeOS' => '/\bCrOS\b/',
        'Windows' => '/\bWindows\b/',
        'macOS' => '/\bMac OS X\b|\bMacintosh\b/',
        'Linux' => '/\bLinux\b/',
    ];

    /**
     * @return array{browser: ?string, os: ?string, device: ?string}
     */
    public static function parse(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        if ($ua === '') {
            return ['browser' => null, 'os' => null, 'device' => null];
        }

        return [
            'browser' => self::first(self::BROWSERS, $ua),
            'os' => self::first(self::SYSTEMS, $ua),
            'device' => self::device($ua),
        ];
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private static function first(array $patterns, string $ua): ?string
    {
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $ua) === 1) {
                return $name;
            }
        }

        return null;
    }

    private static function device(string $ua): string
    {
        if (preg_match('/\b(iPad|Tablet|PlayBook|Kindle|Silk)\b/i', $ua) === 1
            || (preg_match('/\bAndroid\b/', $ua) === 1 && preg_match('/\bMobile\b/', $ua) !== 1)) {
            return 'tablet';
        }

        if (preg_match('/\b(Mobile|iPhone|iPod|Android|Windows Phone|Opera Mini|IEMobile)\b/i', $ua) === 1) {
            return 'mobile';
        }

        return 'desktop';
    }
}
