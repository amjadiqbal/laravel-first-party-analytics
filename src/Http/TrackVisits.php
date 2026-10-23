<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Http;

use AmjadIqbal\FirstPartyAnalytics\Analytics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records successful HTML pages as pageviews. Added to the "web" group automatically (auto_track),
 * or use the "analytics.track" alias on the routes you choose. Nothing is written here: the
 * pageview is collected and written after the response has been sent.
 */
final class TrackVisits
{
    public function __construct(private readonly Analytics $analytics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->analytics->recordPageview($request, $response);
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }
}
