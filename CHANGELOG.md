# Changelog

All notable changes to `laravel-first-party-analytics` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.1.1] - 2026-10-23

Privacy fixes after a review of v0.1.0's claims against its code.

### Security / Privacy
- **No IP address is stored by default.** The `ip` option now defaults to `none` (it was `anonymize`, while
  the README said IPs were never stored). `anonymize` and `full` are opt-in (`ANALYTICS_IP`).
- **The no-cookie visitor id no longer derives from `APP_KEY`.** It is an HMAC of the IP, user agent and
  host keyed with a random salt made for each UTC day, kept in the cache and deleted after two days. Before,
  anyone with `APP_KEY` could recompute an id from a known IP and browser for any past day.
- **The browser event endpoint checks the full origin.** The `Origin` (or, without one, the `Referer`) must
  match scheme, host and port; v0.1.0 compared the host only.

### Fixed
- Bounce rate: a visit with a custom event (including an event-only visit) is no longer counted as a bounce.
  A bounce is now a visit with at most one pageview and no custom event.

### Documentation
- Wording matches the code: the IP default; how the daily salt works and who can recompute an id during the
  day; that a no-cookie visit crossing midnight UTC splits in two; that UTM parameters and `?ref=` are the
  only query-string values kept; that event names and properties are the only request-body data stored;
  that there is no consent mechanism (cookie mode is a config switch); the 400-day custom-range cap; property
  keys cut to 40 characters; browser event names over 100 characters dropped; `Purpose` / `X-Moz` prefetches
  skipped; trusted proxies are needed behind a TLS-terminating proxy.

### Upgrading from 0.1.0
- New visits store no IP. Rows recorded by 0.1.0 keep their anonymised IP; to remove it, run
  `UPDATE analytics_visits SET ip = NULL` (or let `analytics:prune` age them out). To keep storing anonymised
  IPs, set `ANALYTICS_IP=anonymize` (or `'ip' => 'anonymize'` in a published config).
- If you published the config, change `'ip' => 'anonymize'` to `'ip' => env('ANALYTICS_IP', 'none')`.
- No-cookie visitor ids change once on upgrade (new salt), so a visit in progress at deploy time splits in two.
- Behind a load balancer or proxy that ends TLS, make sure trusted proxies are configured, or browser events
  are refused with 403.

## [0.1.0] - 2026-10-23

First release.

### Added
- Pageview tracking for successful HTML responses on `web` routes (auto-added to the `web` group, or the
  `analytics.track` middleware alias). JSON, AJAX, Livewire updates, redirects, errors, prefetches, the
  dashboard and `exclude_paths` are skipped.
- Visits: landing page, referrer (without its query string) and host, `utm_*` (and `?ref=`), browser, OS
  and device type (the user agent itself is never stored), country from a CDN header, anonymised IP. A
  visit ends after 30 idle minutes or on a new source; `ignore_referrers` (Stripe Checkout, PayPal,
  Google and Apple sign-in) continue it.
- No-cookie mode by default (a daily salted hash of IP, user agent and host; the IP is only hashed), or
  first-party `fpa_visitor` / `fpa_visit` cookies with `ANALYTICS_COOKIES=true`.
- Bot filtering with jaybizzle/crawler-detect behind a swappable `DetectsBots` contract; Do-Not-Track and
  Global Privacy Control respected; `Analytics::ignoreUsing()`.
- Custom events: `Analytics::track()` from PHP (joins the visit in a request, or stored for a user from jobs
  and commands), and from the browser with `@firstPartyAnalytics` (`analytics.track()` and
  `data-analytics-event` clicks, sent with `sendBeacon` to a same-origin-checked, rate-limited endpoint).
- User linking on `Login` and `Registered`, and `Analytics::identify()`; with cookies, the visitor's
  earlier anonymous visits are linked too.
- Conversions and ordered funnels from config.
- A gated Blade dashboard (`viewFirstPartyAnalytics`, local-only by default): summary, visits per day, top
  and entry pages, channels, referrers, UTM, countries, devices, browsers, OS, events with property
  breakdowns, conversions and funnels, for preset or custom ranges.
- Batched writes after the response (default), one queued job per request, or sync; write failures are
  reported, never thrown.
- `analytics:prune` (`retention_days`, `--days`, `--pretend`), deleting in chunks.
- `php artisan about` entry.

### Not tested yet
- Queue write mode was tested in the suite (job dispatched and applied), not on a real queue worker.
- MySQL and PostgreSQL are covered by the local CI integration run; the browser test used SQLite.
