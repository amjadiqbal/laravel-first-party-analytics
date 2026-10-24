<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Support;

use Illuminate\Support\Facades\Log;

final class Ip
{
    /** Zeroes the last IPv4 octet, or the last 80 bits of an IPv6 address. Anything else becomes null. */
    public static function anonymize(?string $ip): ?string
    {
        $packed = $ip === null ? false : @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        $packed = strlen($packed) === 4
            ? substr($packed, 0, 3)."\0"
            : substr($packed, 0, 6).str_repeat("\0", 10);

        return (string) inet_ntop($packed);
    }

    public const array MODES = ['none', 'anonymize', 'full'];

    private static bool $warned = false;

    /** The IP as the "ip" option says to store it: "anonymize", "full", or nothing ("none" or any other value). */
    public static function forStorage(?string $ip, string $mode): ?string
    {
        return match ($mode) {
            'full' => @inet_pton((string) $ip) === false ? null : $ip,
            'anonymize' => self::anonymize($ip),
            default => null,
        };
    }

    /**
     * The configured "ip" option. An unknown value (a typo in ANALYTICS_IP) fails closed: it stores no
     * IP, and a warning naming the value is logged once per process.
     */
    public static function mode(): string
    {
        $mode = config('first-party-analytics.ip', 'none');

        if (is_string($mode) && in_array($mode, self::MODES, true)) {
            return $mode;
        }

        if (! self::$warned) {
            self::$warned = true;
            Log::warning(sprintf('first-party-analytics: unknown "ip" value %s (expected none, anonymize or full); storing no IP.', json_encode($mode)));
        }

        return 'none';
    }
}
