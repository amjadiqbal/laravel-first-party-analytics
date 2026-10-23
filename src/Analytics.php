<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics;

use AmjadIqbal\FirstPartyAnalytics\Contracts\DetectsBots;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public API (also as the Analytics facade):
 *
 *   Analytics::track('signup', ['plan' => 'pro']);                in a request: joins the visit
 *   Analytics::track('invoice_paid', ['amount' => 49], $user);    from a job, a command or a webhook
 *   Analytics::identify($user);                                   link the current visit to a user
 *   Analytics::ignoreUsing(fn (Request $request) => ...);         skip some visitors (your own team)
 */
final class Analytics
{
    public const string PAGEVIEW = '$pageview';

    /** @var list<Closure(Request): bool> */
    private array $ignore = [];

    public function __construct(
        private readonly Application $app,
        private readonly Recorder $recorder,
        private readonly Visits $visits,
    ) {}

    /**
     * Records a custom event. Inside a web request it belongs to the visitor's visit (and is skipped
     * for bots, Do-Not-Track/GPC and ignored visitors). Elsewhere it is stored without a visit, for
     * the given user.
     *
     * @param  array<string, mixed>  $properties
     */
    public function track(string $name, array $properties = [], Authenticatable|int|string|null $user = null): void
    {
        $name = trim($name);

        if (! $this->enabled() || $name === '' || str_starts_with($name, '$')) {
            return;
        }

        $request = $this->request();
        $visit = null;

        if ($request !== null) {
            if (! $this->accepts($request)) {
                return;
            }

            $visit = $this->visits->resolve($request);
        }

        $this->event($name, $properties, $visit['token'] ?? null, self::userId($user) ?? $visit['user'] ?? null, $request !== null ? self::path($request) : null);
    }

    /** Links the current visit (and, with the visitor cookie, the visitor's earlier visits) to a user. */
    public function identify(Authenticatable|int|string $user): void
    {
        $request = $this->request();
        $id = self::userId($user);

        if ($request !== null && $id !== null && $this->enabled() && $this->accepts($request)) {
            $this->visits->link($request, $id);
        }
    }

    /**
     * @param  Closure(Request): bool  $callback  return true to skip the request's visitor
     */
    public function ignoreUsing(Closure $callback): void
    {
        $this->ignore[] = $callback;
    }

    /** The current visit's token, or null outside a tracked request. */
    public function currentVisit(): ?string
    {
        $request = $this->request();

        return $request !== null && $this->enabled() && $this->accepts($request)
            ? $this->visits->resolve($request, start: false)['token'] ?? null
            : null;
    }

    /** Records the request as a pageview when it is one (TrackVisits middleware). */
    public function recordPageview(Request $request, Response $response): void
    {
        if (! $this->enabled() || ! $this->isPageview($request, $response) || ! $this->accepts($request)) {
            return;
        }

        $visit = $this->visits->resolve($request);

        if ($visit !== null) {
            $this->event(self::PAGEVIEW, [], $visit['token'], $visit['user'], self::path($request));
        }
    }

    /**
     * Records an event sent by the browser script.
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordBrowserEvent(Request $request, string $name, array $properties, ?string $path): void
    {
        if (! $this->enabled() || ! $this->accepts($request)) {
            return;
        }

        $visit = $this->visits->resolve($request, landingPath: $path);

        if ($visit !== null) {
            $this->event($name, $properties, $visit['token'], $visit['user'], $path);
        }
    }

    /** Whether the visitor may be recorded: not a bot, no Do-Not-Track / GPC signal, not ignored. */
    public function accepts(Request $request): bool
    {
        if (config('first-party-analytics.respect_dnt', true) && $request->headers->get('DNT') === '1') {
            return false;
        }

        if (config('first-party-analytics.respect_gpc', true) && $request->headers->get('Sec-GPC') === '1') {
            return false;
        }

        if ($this->app->make(DetectsBots::class)->isBot($request)) {
            return false;
        }

        foreach ($this->ignore as $callback) {
            if ($callback($request) === true) {
                return false;
            }
        }

        return true;
    }

    /** A successful HTML answer to a GET, not a prefetch, not JSON, not an excluded path. */
    public function isPageview(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }

        if (! str_contains(strtolower((string) $response->headers->get('Content-Type', 'text/html')), 'text/html')) {
            return false;
        }

        if ($request->ajax() || $request->headers->has('X-Livewire') || str_contains((string) $request->headers->get('Purpose').(string) $request->headers->get('Sec-Purpose').(string) $request->headers->get('X-Moz'), 'prefetch')) {
            return false;
        }

        return ! $request->is(...$this->excludedPaths());
    }

    /**
     * @return list<string>
     */
    public function excludedPaths(): array
    {
        $paths = array_values(array_filter((array) config('first-party-analytics.exclude_paths', []), 'is_string'));

        foreach (['dashboard.path', 'js.endpoint'] as $path) {
            $value = trim((string) config('first-party-analytics.'.$path), '/');
            if ($value !== '') {
                $paths[] = $value;
                $paths[] = $value.'/*';
            }
        }

        return $paths;
    }

    public function enabled(): bool
    {
        return (bool) config('first-party-analytics.enabled', true);
    }

    /** The path without its query string, at most 2048 characters. */
    public static function path(Request $request): string
    {
        return mb_substr('/'.ltrim($request->path(), '/'), 0, 2048);
    }

    public static function userId(mixed $user): ?string
    {
        $id = $user instanceof Authenticatable ? $user->getAuthIdentifier() : $user;

        return is_int($id) || (is_string($id) && $id !== '') ? mb_substr((string) $id, 0, 64) : null;
    }

    /**
     * Keeps at most 25 properties with short keys and scalar values; longer strings are cut to 255.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<string, scalar|null>
     */
    public static function cleanProperties(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            if (count($clean) >= 25) {
                break;
            }

            $key = mb_substr(trim((string) $key), 0, 40);

            if ($key === '' || ! (is_scalar($value) || $value === null)) {
                continue;
            }

            $clean[$key] = is_string($value) ? mb_substr($value, 0, 255) : $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function event(string $name, array $properties, ?string $visit, ?string $user, ?string $path): void
    {
        $properties = self::cleanProperties($properties);

        $this->recorder->push(['op' => 'event', 'row' => [
            'visit_token' => $visit,
            'user_id' => $user,
            'name' => mb_substr($name, 0, 100),
            'path' => $path,
            'properties' => $properties === [] ? null : json_encode($properties),
            'created_at' => Date::now(),
        ]]);
    }

    /** The current web request, or null in console commands, queue jobs and before routing. */
    private function request(): ?Request
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        return $request->route() !== null ? $request : null;
    }
}
