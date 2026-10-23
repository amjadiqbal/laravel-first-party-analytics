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
    | cookies     false (default): no cookie is set. A visitor is a hash of the
    |             IP, user agent and host with a salt that changes every day
    |             (the IP is hashed, not stored), so nobody can be followed
    |             across days. Visits are kept together for the visit window
    |             with the cache.
    |             true: a first-party visitor cookie (visitor_days) and a visit
    |             cookie, so returning visitors and earlier anonymous visits
    |             can be linked to a user who signs up later.
    |
    | ip          "anonymize" stores the IP with the last IPv4 octet / last
    |             80 IPv6 bits zeroed, "none" stores no IP, "full" stores it.
    |
    | respect_dnt / respect_gpc   a browser that sends DNT: 1 or Sec-GPC: 1 is
    |             not recorded at all.
    |
    | Raw user agents, query strings (other than UTM / ref), request bodies
    | and headers are never stored.
    |
    */

    'cookies' => env('ANALYTICS_COOKIES', false),

    'cookie_names' => ['visitor' => 'fpa_visitor', 'visit' => 'fpa_visit'],

    'visitor_days' => 365,

    'visit_minutes' => 30,

    'ip' => 'anonymize',

    'respect_dnt' => true,

    'respect_gpc' => true,

    'country_header' => 'CF-IPCountry',

    /*
    | Hosts that send visitors back to you mid-visit (payment pages, sign-in
    | providers). Coming back from them continues the visit instead of
    | starting a new one with that site as the source.
    */

    'ignore_referrers' => ['checkout.stripe.com', 'paypal.com', 'accounts.google.com', 'appleid.apple.com'],

    'cache_store' => null,

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
    | navigator.sendBeacon. The endpoint checks the Origin, is rate limited
    | per IP and ignores names starting with "$" (reserved for pageviews).
    | allowed_events: null accepts any name, or list the names you accept.
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
