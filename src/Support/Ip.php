<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Support;

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

    /** The IP as the "ip" option says to store it: "anonymize", "full" or "none". */
    public static function forStorage(?string $ip, string $mode): ?string
    {
        return match ($mode) {
            'full' => @inet_pton((string) $ip) === false ? null : $ip,
            'none' => null,
            default => self::anonymize($ip),
        };
    }
}
