<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Http;

use AmjadIqbal\FirstPartyAnalytics\Analytics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives events from the browser script (navigator.sendBeacon). The route is exempt from CSRF so a
 * beacon needs no token, so instead the Origin header (or, when a browser sends none, the Referer) must
 * be exactly this site: same scheme, host and port. It is rate limited per IP.
 * Always answers 204, so the endpoint tells a caller nothing.
 */
final class EventController
{
    public function __invoke(Request $request, Analytics $analytics): Response
    {
        if (! $this->sameOrigin($request)) {
            return response('', 403);
        }

        $payload = $request->json()->all() ?: $request->all();
        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        $allowed = config('first-party-analytics.js.allowed_events');

        if ($name === '' || mb_strlen($name) > 100 || str_starts_with($name, '$')
            || (is_array($allowed) && ! in_array($name, $allowed, true))) {
            return response('', 204);
        }

        $properties = is_array($payload['properties'] ?? null) ? $payload['properties'] : [];
        $path = is_string($payload['path'] ?? null) && str_starts_with($payload['path'], '/')
            ? mb_substr((string) strtok($payload['path'], '?#'), 0, 2048)
            : null;

        $analytics->recordBrowserEvent($request, $name, $properties, $path);

        return response('', 204);
    }

    private function sameOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');

        if ($origin === null || $origin === '' || $origin === 'null') {
            return false;
        }

        $parts = parse_url($origin);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        // The full origin must match: scheme, host and port (behind a TLS-terminating proxy, configure
        // trusted proxies so the request knows it is https).
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme === $request->getScheme()
            && strcasecmp($parts['host'], $request->getHost()) === 0
            && $port === (int) $request->getPort();
    }
}
