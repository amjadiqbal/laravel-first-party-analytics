<?php

use AmjadIqbal\FirstPartyAnalytics\Facades\Analytics;
use AmjadIqbal\FirstPartyAnalytics\Tests\TestCase;
use AmjadIqbal\FirstPartyAnalytics\Visits;
use Illuminate\Http\Request;

it('records a pageview and starts a visit with its source', function () {
    $this->browse('/pricing?utm_source=newsletter&utm_medium=email&utm_campaign=launch&utm_term=t&utm_content=c&email=x@example.com', [
        'Referer' => 'https://www.google.com/search?q=secret+terms',
        'CF-IPCountry' => 'de',
    ])->assertOk();

    $visit = $this->visits()->sole();
    expect($visit->landing_path)->toBe('/pricing')
        ->and($visit->utm_source)->toBe('newsletter')
        ->and($visit->utm_medium)->toBe('email')
        ->and($visit->utm_campaign)->toBe('launch')
        ->and($visit->utm_term)->toBe('t')
        ->and($visit->utm_content)->toBe('c')
        ->and($visit->referrer)->toBe('https://www.google.com/search')
        ->and($visit->referrer_host)->toBe('google.com')
        ->and($visit->browser)->toBe('Chrome')
        ->and($visit->os)->toBe('macOS')
        ->and($visit->device)->toBe('desktop')
        ->and($visit->country)->toBe('DE')
        ->and($visit->ip)->toBeNull()
        ->and(strlen($visit->visitor))->toBe(32);

    $event = $this->events()->sole();
    expect($event->name)->toBe('$pageview')
        ->and($event->path)->toBe('/pricing')
        ->and($event->visit_token)->toBe($visit->token);

    // Nothing from the query string other than UTM is stored, and no raw user agent column exists.
    expect(json_encode([$visit, $event]))->not->toContain('example.com')->not->toContain('secret');
});

it('keeps one visit across pages without cookies, and counts each page', function () {
    $this->browse('/');
    $this->browse('/pricing', ['Referer' => 'http://localhost/']);
    $this->browse('/checkout', ['Referer' => 'http://localhost/pricing']);

    expect($this->visits()->count())->toBe(1)
        ->and($this->events()->pluck('path')->all())->toBe(['/', '/pricing', '/checkout'])
        ->and($this->events()->distinct()->pluck('visit_token')->all())->toHaveCount(1);

    $this->assertEmpty($this->app['cookie']->getQueuedCookies());
});

it('tells visitors apart without cookies by IP and browser', function () {
    $this->browse('/', ip: '198.51.100.1');
    $this->browse('/', ip: '192.0.2.44');
    $this->browse('/', ua: TestCase::IPHONE, ip: '198.51.100.1');

    expect($this->visits()->count())->toBe(3)
        ->and($this->visits()->distinct()->count('visitor'))->toBe(3)
        ->and($this->visits()->where('device', 'mobile')->value('os'))->toBe('iOS');
});

it('gives the same person a new visitor id every day without cookies', function () {
    $this->travelTo('2026-10-20 10:00:00');
    $this->browse('/');
    $this->travelTo('2026-10-21 10:00:00');
    $this->browse('/');

    expect($this->visits()->distinct()->count('visitor'))->toBe(2);
});

it('starts a new visit after the visit window or from a new source', function () {
    $this->travelTo('2026-10-20 10:00:00');
    $this->browse('/');
    $this->travelTo('2026-10-20 10:20:00');
    $this->browse('/pricing');
    expect($this->visits()->count())->toBe(1);

    $this->travelTo('2026-10-20 10:51:00');
    $this->browse('/pricing');
    expect($this->visits()->count())->toBe(2);

    $this->browse('/?utm_source=twitter&utm_campaign=x');
    $this->browse('/?utm_source=twitter&utm_campaign=x'); // a reload of the same link continues the visit
    $this->browse('/', ['Referer' => 'https://news.ycombinator.com/item?id=1']);
    expect($this->visits()->count())->toBe(4)
        ->and($this->visits()->orderBy('id')->pluck('referrer_host')->last())->toBe('news.ycombinator.com');
});

it('continues the visit when coming back from an ignored referrer like Stripe Checkout', function () {
    $this->browse('/checkout');
    $this->browse('/pricing', ['Referer' => 'https://checkout.stripe.com/c/pay/cs_live_secret']);

    expect($this->visits()->count())->toBe(1);
});

it('treats ?ref= as the source', function () {
    $this->browse('/?ref=producthunt');

    expect($this->visits()->value('utm_source'))->toBe('producthunt');
});

it('skips what is not a pageview', function (string $method, string $uri, array $headers = []) {
    $method === 'post' ? $this->postAs($uri, [], $headers) : $this->browse($uri, $headers);

    expect($this->visits()->count())->toBe(0)->and($this->events()->count())->toBe(0);
})->with([
    'json' => ['get', '/json'],
    'redirect' => ['get', '/go'],
    'not found' => ['get', '/missing'],
    'post' => ['post', '/form'],
    'ajax' => ['get', '/', ['X-Requested-With' => 'XMLHttpRequest']],
    'prefetch' => ['get', '/', ['Sec-Purpose' => 'prefetch']],
    'livewire' => ['get', '/', ['X-Livewire' => '1']],
    'excluded path' => ['get', '/admin/users'],
    'dashboard path' => ['get', '/analytics'],
]);

it('does not record bots, crawlers or empty user agents', function (string $ua) {
    $this->browse('/', ua: $ua);

    expect($this->visits()->count())->toBe(0);
})->with([
    'googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'gptbot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
    'headless chrome' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/141.0.0.0 Safari/537.36',
    'curl' => 'curl/8.7.1',
    'uptime monitor' => 'Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
    'empty' => '',
]);

it('respects Do Not Track and Global Privacy Control', function () {
    $this->browse('/', ['DNT' => '1']);
    $this->browse('/', ['Sec-GPC' => '1']);
    expect($this->visits()->count())->toBe(0);

    config(['first-party-analytics.respect_dnt' => false, 'first-party-analytics.respect_gpc' => false]);
    $this->browse('/', ['DNT' => '1', 'Sec-GPC' => '1']);
    expect($this->visits()->count())->toBe(1);
});

it('skips visitors the app ignores', function () {
    Analytics::ignoreUsing(fn (Request $request) => $request->ip() === '10.0.0.5');

    $this->browse('/', ip: '10.0.0.5');
    $this->browse('/', ip: '10.0.0.6');

    expect($this->visits()->count())->toBe(1);
});

it('stores no IP by default', function () {
    $this->browse('/', ip: '203.0.113.77');

    expect($this->visits()->value('ip'))->toBeNull()
        ->and(json_encode($this->visits()->first()))->not->toContain('203.0.113');
});

it('makes the no-cookie visitor id from a random daily salt, not from APP_KEY', function () {
    $this->travelTo('2026-10-20 10:00:00');
    $this->browse('/', ip: '198.51.100.9');
    $visitor = $this->visits()->value('visitor');

    $salt = app('cache')->store()->get('fpa:salt:2026-10-20');
    expect($salt)->toBeString()->toHaveLength(64);

    // The old scheme (HMAC with the app key) cannot reproduce the id.
    $withAppKey = substr(hash_hmac('sha256', implode('|', ['2026-10-20', '198.51.100.9', TestCase::CHROME, 'localhost']), (string) config('app.key')), 0, 32);
    expect($visitor)->not->toBe($withAppKey)
        ->and($visitor)->toBe(substr(hash_hmac('sha256', implode('|', ['198.51.100.9', TestCase::CHROME, 'localhost']), $salt), 0, 32));
});

it('cannot recompute a visitor id once the day\'s salt is gone', function () {
    $this->travelTo('2026-10-20 10:00:00');
    $this->browse('/', ip: '198.51.100.9');

    // The salt expires after two days; dropping it (and the process copy) gives the same person a new id.
    app('cache')->store()->forget('fpa:salt:2026-10-20');
    app('cache')->store()->forget('fpa:visit:'.$this->visits()->value('visitor'));
    $this->app->forgetInstance(Visits::class);
    $this->app->forgetInstance(AmjadIqbal\FirstPartyAnalytics\Analytics::class);
    $this->browse('/', ip: '198.51.100.9');

    expect($this->visits()->distinct()->count('visitor'))->toBe(2);
});

it('splits a no-cookie visit at midnight UTC', function () {
    $this->travelTo('2026-10-20 23:50:00');
    $this->browse('/');
    $this->travelTo('2026-10-21 00:05:00');
    $this->browse('/pricing');

    expect($this->visits()->count())->toBe(2)->and($this->visits()->distinct()->count('visitor'))->toBe(2);
});

it('keeps one visit across midnight with cookies', function () {
    config(['first-party-analytics.cookies' => true]);
    $this->travelTo('2026-10-20 23:50:00');
    $response = $this->browse('/');
    $cookies = [];
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $response->getCookie($cookie->getName())?->getValue();
    }
    $this->travelTo('2026-10-21 00:05:00');
    $this->withCookies($cookies)->browse('/pricing');

    expect($this->visits()->count())->toBe(1);
});

it('stores the IP as configured', function (string $mode, string $ip, ?string $stored) {
    config(['first-party-analytics.ip' => $mode]);
    $this->browse('/', ip: $ip);

    expect($this->visits()->value('ip'))->toBe($stored);
})->with([
    'anonymized v4' => ['anonymize', '203.0.113.77', '203.0.113.0'],
    'anonymized v6' => ['anonymize', '2001:db8:85a3:1234:5678:8a2e:370:7334', '2001:db8:85a3::'],
    'none' => ['none', '203.0.113.77', null],
    'full' => ['full', '203.0.113.77', '203.0.113.77'],
]);

it('records nothing when disabled', function () {
    config(['first-party-analytics.enabled' => false]);
    $this->browse('/');
    Analytics::track('signup');

    expect($this->visits()->count() + $this->events()->count())->toBe(0);
});
