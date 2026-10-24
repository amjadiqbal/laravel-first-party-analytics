<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tracking on or off
    |--------------------------------------------------------------------------
    |
    | With auto_track on, every page your "web" routes serve is recorded as a
    | pageview (only successful HTML responses to GET requests, never bots,
    | prefetches, JSON or the excluded paths below). Turn it off to add the
    | AmjadIqbal\FirstPartyAnalytics\Http\TrackVisits middleware (alias
    | "analytics.track") to the routes you choose instead.
    |
    */

    'enabled' => env('ANALYTICS_ENABLED', true),

    'auto_track' => true,

    'exclude_paths' => [
        'livewire/*', 'livewire-*', '_debugbar/*', 'telescope*', 'horizon*', 'pulse*',
        'nova*', 'admin*', 'filament*', 'up', 'sanctum/*', 'broadcasting/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | cookies     false (default): no cookie is set. A visitor id is a keyed
    |             hash of the IP, user agent and host. The key is a random
    |             salt made for each UTC day and kept in the cache until that
    |             day ends (midnight UTC), so an id cannot be recomputed once
    |             its day is over (not even with APP_KEY). The same person gets a new
    |             id every day, and a visit that crosses midnight UTC is
    |             split in two. Visits are kept together with the cache.
    |             true: a first-party visitor cookie (visitor_days) and a visit
    |             cookie, so returning visitors and earlier anonymous visits
    |             can be linked to a user who signs up later. Turning cookies
    |             on is a config switch: asking for consent is up to your app.
    |
    | ip          "none" (default) stores no IP. "anonymize" stores it with
    |             the last IPv4 octet / last 80 IPv6 bits zeroed, "full"
    |             stores it as is. No report uses the IP. Any other value
    |             stores no IP and logs a warning naming it (also shown by
    |             php artisan about).
    |
    | respect_dnt / respect_gpc   a browser that sends DNT: 1 or Sec-GPC: 1 is
    |             not recorded at all.
    |
    | country_header  the one header that is stored: its two-letter value
    |             becomes the visit's country (CF-IPCountry, sent by
    |             Cloudflare). null (ANALYTICS_COUNTRY_HEADER=) stores none.
    |
    | Raw user agents and other headers are never stored, nor request bodies (except
    | the event name and properties the tracking script sends). Of the query
    | string, only the UTM parameters and ?ref= are kept.
    |
    */

    'cookies' => env('ANALYTICS_COOKIES', false),

    'cookie_names' => ['visitor' => 'fpa_visitor', 'visit' => 'fpa_visit'],

    'visitor_days' => 365,

    'visit_minutes' => 30,

    'ip' => env('ANALYTICS_IP', 'none'),

    'respect_dnt' => true,

    'respect_gpc' => true,

    'country_header' => env('ANALYTICS_COUNTRY_HEADER', 'CF-IPCountry'),

    /*
    | Hosts that send visitors back to you mid-visit (payment pages, sign-in
    | providers). Coming back from them continues the visit instead of
    | starting a new one with that site as the source.
    */

    'ignore_referrers' => ['checkout.stripe.com', 'paypal.com', 'accounts.google.com', 'appleid.apple.com'],

    /*
    | The cache store (null = your default) that keeps no-cookie visits
    | together and holds each day's salt. Use a store that lives across
    | requests and is shared by all your servers (redis, database,
    | memcached): with "array" every request is a new visit, and with a
    | per-server store (file, apc) each server makes its own salt, so the
    | same person counts as one visitor per server.
    */

    'cache_store' => env('ANALYTICS_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    |
    | "after_response" (default) collects everything recorded during a request
    | and inserts it in one go after the response has been sent. "queue"
    | dispatches one job per request instead (set the connection and queue).
    | "sync" writes at once. Console commands and queue jobs always write at
    | once (or queue, with "queue").
    |
    */

    'write' => env('ANALYTICS_WRITE', 'after_response'),

    'queue' => [
        'connection' => env('ANALYTICS_QUEUE_CONNECTION'),
        'queue' => env('ANALYTICS_QUEUE'),
    ],

    'database' => [
        'connection' => env('ANALYTICS_DB_CONNECTION'),
        'visits_table' => 'analytics_visits',
        'events_table' => 'analytics_events',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | php artisan analytics:prune deletes visits and events older than this
    | many days. Schedule it daily. null keeps everything.
    |
    */

    'retention_days' => 395,

    /*
    |--------------------------------------------------------------------------
    | JavaScript events
    |--------------------------------------------------------------------------
    |
    | The @firstPartyAnalytics Blade directive adds a small inline script with
    | analytics.track(name, properties) and click tracking for elements with
    | data-analytics-event. Events are sent to this endpoint with
    | navigator.sendBeacon. The endpoint needs the request's Origin header (or
    | its Referer when a browser sends no Origin) to be exactly this site:
    | same scheme, host and port. It is rate limited per IP, refuses names
    | starting with "$" (reserved for pageviews) or over 100 characters, and
    | keeps up to 25 properties (keys cut to 40 characters, string values to
    | 255). allowed_events: null accepts any name, or list the names you accept.
    |
    */

    'js' => [
        'enabled' => true,
        'endpoint' => 'fpa/event',
        'middleware' => ['web'],
        'rate_limit' => 120,
        'allowed_events' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversions and funnels
    |--------------------------------------------------------------------------
    |
    | conversions: event names that count as goals. The dashboard shows how
    | many visits reached each one and the conversion rate.
    |
    | funnels: ordered steps. A step is an event name, or "page:/path" for a
    | pageview ("page:/blog/*" matches a prefix). A visit counts for a step
    | only after it completed the step before it.
    |
    */

    'conversions' => [
        // 'signup',
        // 'purchase',
    ],

    'funnels' => [
        // 'Checkout' => ['page:/pricing', 'checkout_started', 'purchase'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Served at /{path} behind the gate "viewFirstPartyAnalytics", which allows
    | the local environment only until your app defines it:
    |
    |   Gate::define('viewFirstPartyAnalytics', fn ($user) => $user->isAdmin());
    |
    | The gate is always checked after the middleware below.
    |
    */

    'dashboard' => [
        'enabled' => env('ANALYTICS_DASHBOARD', true),
        'path' => env('ANALYTICS_PATH', 'analytics'),
        'domain' => env('ANALYTICS_DOMAIN'),
        'middleware' => ['web'],
        'top' => 10,
    ],

];
