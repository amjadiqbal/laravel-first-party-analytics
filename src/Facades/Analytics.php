<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void track(string $name, array<string, mixed> $properties = [], \Illuminate\Contracts\Auth\Authenticatable|int|string|null $user = null)
 * @method static void identify(\Illuminate\Contracts\Auth\Authenticatable|int|string $user)
 * @method static void ignoreUsing(\Closure $callback)
 * @method static string|null currentVisit()
 *
 * @see \AmjadIqbal\FirstPartyAnalytics\Analytics
 */
final class Analytics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AmjadIqbal\FirstPartyAnalytics\Analytics::class;
    }
}
