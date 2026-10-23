# Changelog

All notable changes to `laravel-first-party-analytics` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
