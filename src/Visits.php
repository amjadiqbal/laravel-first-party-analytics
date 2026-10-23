<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics;

use AmjadIqbal\FirstPartyAnalytics\Support\Ip;
use AmjadIqbal\FirstPartyAnalytics\Support\Source;
use AmjadIqbal\FirstPartyAnalytics\Support\UserAgent;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Finds the visit a request belongs to, or starts one.
 *
 * A visit ends after visit_minutes without a request, or when the visitor arrives again from a new
 * campaign or another site. Its state is kept in a first-party cookie (cookie mode) or in the cache
 * under the day's visitor hash (no-cookie mode). The state remembers which user it was linked to, so
 * linking costs one update per visit, not one per request.
 */
final class Visits
{
    private const string ATTRIBUTE = 'first-party-analytics.visit';

    public function __construct(
        private readonly Recorder $recorder,
        private readonly CacheFactory $cache,
        private readonly CookieJar $cookies,
    ) {}

    /**
     * The request's visit: ['token' => ..., 'visitor' => ..., 'user' => ?string, 'source' => ?string].
     * With $start false, null when the visitor has no visit in progress.
     *
     * @return array{token: string, visitor: string, user: ?string, source: ?string}|null
     */
    public function resolve(Request $request, bool $start = true, ?string $landingPath = null): ?array
    {
        $visit = $request->attributes->get(self::ATTRIBUTE);

        if (is_array($visit)) {
            return $visit;
        }

        $visitor = $this->visitor($request);
        $visit = $this->load($request, $visitor);
        $source = Source::key($request);

        if ($visit !== null && $source !== null && $source !== $visit['source']) {
            $visit = null;
        }

        if ($visit === null) {
            if (! $start) {
                return null;
            }

            $visit = ['token' => Str::random(32), 'visitor' => $visitor, 'user' => null, 'source' => $source];
            $this->recorder->push(['op' => 'visit', 'row' => $this->row($request, $visit, $landingPath)]);
        }

        $request->attributes->set(self::ATTRIBUTE, $visit);

        if (($user = Analytics::userId($request->user())) !== null) {
            $visit = $this->link($request, $user) ?? $visit;
        }

        $this->save($request, $visit);

        return $request->attributes->get(self::ATTRIBUTE);
    }

    /**
     * Links the request's current visit to a user. With the visitor cookie, the visitor's earlier
     * anonymous visits are linked too (first-touch attribution).
     *
     * @return array{token: string, visitor: string, user: ?string, source: ?string}|null
     */
    public function link(Request $request, string $user): ?array
    {
        $visit = $request->attributes->get(self::ATTRIBUTE) ?? $this->load($request, $this->visitor($request));

        if (! is_array($visit) || $visit['user'] === $user) {
            return is_array($visit) ? $visit : null;
        }

        $this->recorder->push([
            'op' => 'link',
            'token' => $visit['token'],
            'visitor' => $this->usesCookies() ? $visit['visitor'] : null,
            'user' => $user,
        ]);

        $visit['user'] = $user;
        $request->attributes->set(self::ATTRIBUTE, $visit);
        $this->save($request, $visit);

        return $visit;
    }

    /**
     * @param  array{token: string, visitor: string, user: ?string, source: ?string}  $visit
     * @return array<string, mixed>
     */
    private function row(Request $request, array $visit, ?string $landingPath): array
    {
        $country = strtoupper((string) $request->headers->get((string) config('first-party-analytics.country_header', 'CF-IPCountry')));

        return [
            'token' => $visit['token'],
            'visitor' => $visit['visitor'],
            'user_id' => Analytics::userId($request->user()),
            'started_at' => Date::now(),
            'landing_path' => $landingPath ?? Analytics::path($request),
            ...Source::fromRequest($request),
            ...UserAgent::parse($request->userAgent()),
            'country' => preg_match('/^[A-Z]{2}$/', $country) === 1 && ! in_array($country, ['XX', 'T1'], true) ? $country : null,
            'ip' => Ip::forStorage($request->ip(), (string) config('first-party-analytics.ip', 'anonymize')),
        ];
    }

    private function visitor(Request $request): string
    {
        if ($this->usesCookies()) {
            $cookie = $request->cookie($this->cookieName('visitor'));

            return is_string($cookie) && preg_match('/^[A-Za-z0-9]{32}$/', $cookie) === 1 ? $cookie : Str::random(32);
        }

        // No cookie: a hash of the IP, user agent and host, salted per day with the app key (the
        // IP is only hashed, never stored), so the same person gets a new id every day.
        $key = (string) config('app.key');

        return substr(hash_hmac('sha256', implode('|', [
            Date::now()->utc()->format('Y-m-d'),
            (string) $request->ip(),
            (string) $request->userAgent(),
            $request->getHost(),
        ]), $key), 0, 32);
    }

    /**
     * @return array{token: string, visitor: string, user: ?string, source: ?string}|null
     */
    private function load(Request $request, string $visitor): ?array
    {
        $state = $this->usesCookies()
            ? json_decode((string) $request->cookie($this->cookieName('visit')), true)
            : $this->store()->get('fpa:visit:'.$visitor);

        if (! is_array($state) || ! is_string($state['token'] ?? null) || preg_match('/^[A-Za-z0-9]{32}$/', $state['token']) !== 1) {
            return null;
        }

        return [
            'token' => $state['token'],
            'visitor' => $visitor,
            'user' => is_scalar($state['user'] ?? null) ? (string) $state['user'] : null,
            'source' => is_string($state['source'] ?? null) ? $state['source'] : null,
        ];
    }

    /**
     * @param  array{token: string, visitor: string, user: ?string, source: ?string}  $visit
     */
    private function save(Request $request, array $visit): void
    {
        $minutes = max(1, (int) config('first-party-analytics.visit_minutes', 30));
        $state = ['token' => $visit['token'], 'user' => $visit['user'], 'source' => $visit['source']];

        if (! $this->usesCookies()) {
            $key = 'fpa:visit:'.$visit['visitor'];
            $this->recorder->afterwards($key, fn () => $this->store()->put($key, $state, $minutes * 60));

            return;
        }

        $secure = $request->isSecure();
        $this->cookies->queue($this->cookies->make($this->cookieName('visitor'), $visit['visitor'], max(1, (int) config('first-party-analytics.visitor_days', 365)) * 1440, secure: $secure, sameSite: 'lax'));
        $this->cookies->queue($this->cookies->make($this->cookieName('visit'), (string) json_encode($state), $minutes, secure: $secure, sameSite: 'lax'));
    }

    private function usesCookies(): bool
    {
        return (bool) config('first-party-analytics.cookies', false);
    }

    private function cookieName(string $which): string
    {
        return (string) (config('first-party-analytics.cookie_names.'.$which) ?? 'fpa_'.$which);
    }

    private function store(): Cache
    {
        return $this->cache->store(config('first-party-analytics.cache_store'));
    }
}
