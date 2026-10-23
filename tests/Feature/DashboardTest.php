<?php

use AmjadIqbal\FirstPartyAnalytics\Report;
use AmjadIqbal\FirstPartyAnalytics\Store;
use AmjadIqbal\FirstPartyAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** Three visitors on 2026-10-20: a Google visitor who bounces, a newsletter visitor who buys, a direct mobile visitor. */
function seedTraffic(TestCase $test): void
{
    $test->travelTo('2026-10-20 09:00:00');
    $test->browse('/', ['Referer' => 'https://www.google.com/'], ip: '198.51.100.1');

    $test->travelTo('2026-10-20 10:00:00');
    $test->browse('/pricing?utm_source=newsletter&utm_medium=email&utm_campaign=launch', ip: '198.51.100.2');
    $test->travelTo('2026-10-20 10:01:00');
    $test->browse('/checkout', ip: '198.51.100.2');
    $test->travelTo('2026-10-20 10:02:30');
    $test->postAs('/buy', ip: '198.51.100.2');

    $test->travelTo('2026-10-20 11:00:00');
    $test->browse('/checkout', ua: TestCase::IPHONE, ip: '198.51.100.3');
    $test->travelTo('2026-10-20 11:00:40');
    $test->browse('/pricing', ua: TestCase::IPHONE, ip: '198.51.100.3');
}

it('is denied outside the local environment until the app defines the gate', function () {
    $this->get('/analytics')->assertForbidden();

    Gate::define('viewFirstPartyAnalytics', fn ($user = null) => true);
    $this->get('/analytics')->assertOk();
});

it('is open in the local environment by default, and the app gate wins', function () {
    $this->asLocal();
    $this->get('/analytics')->assertOk()->assertSee('Unique visitors');

    Gate::define('viewFirstPartyAnalytics', fn ($user = null) => false);
    $this->get('/analytics')->assertForbidden();
});

it('reports numbers that match the database', function () {
    seedTraffic($this);
    $report = new Report(app(Store::class), now()->startOfDay(), now()->addDay()->startOfDay());

    expect($report->summary())->toBe([
        'visitors' => 3,
        'visits' => 3,
        'pageviews' => 5,
        'events' => 1,
        'bounce_rate' => 33.3,
        'avg_duration' => 63,          // (0 + 150 + 40) / 3
        'pages_per_visit' => 1.7,
    ]);

    expect($report->topPages())->toBe([
        ['label' => '/checkout', 'count' => 2, 'visits' => 2],
        ['label' => '/pricing', 'count' => 2, 'visits' => 2],
        ['label' => '/', 'count' => 1, 'visits' => 1],
    ]);

    expect($report->channels())->toEqualCanonicalizing([
        ['label' => 'Organic search', 'count' => 1],
        ['label' => 'Email', 'count' => 1],
        ['label' => 'Direct', 'count' => 1],
    ]);

    expect($report->visitsBy('device'))->toBe([['label' => 'desktop', 'count' => 2], ['label' => 'mobile', 'count' => 1]])
        ->and($report->visitsBy('referrer_host', true))->toBe([['label' => 'google.com', 'count' => 1]])
        ->and($report->visitsBy('utm_campaign', true))->toBe([['label' => 'launch', 'count' => 1]])
        ->and($report->customEvents())->toBe([['label' => 'purchase', 'count' => 1, 'visits' => 1]])
        ->and($report->properties('purchase'))->toBe(['amount' => [['label' => '49', 'count' => 1]], 'plan' => [['label' => 'pro', 'count' => 1]]])
        ->and($report->conversions(['purchase', 'signup']))->toBe([
            ['label' => 'purchase', 'visits' => 1, 'users' => 0, 'rate' => 33.3],
            ['label' => 'signup', 'visits' => 0, 'users' => 0, 'rate' => 0.0],
        ]);

    expect(collect($report->timeline())->firstWhere('day', '2026-10-20'))->toBe(['day' => '2026-10-20', 'visits' => 3, 'pageviews' => 5]);
});

it('averages whole seconds without float drift', function () {
    // Visits of 3 s and 6 s: 4.5 s rounds to 5 (julianday() arithmetic gave 4.4999...).
    foreach ([['192.0.2.1', 3], ['192.0.2.2', 6]] as [$ip, $seconds]) {
        $this->travelTo('2026-10-20 09:00:00');
        $this->browse('/', ip: $ip);
        $this->travel($seconds)->seconds();
        $this->browse('/pricing', ip: $ip);
    }

    $report = new Report(app(Store::class), now()->startOfDay(), now()->addDay()->startOfDay());
    expect($report->summary()['avg_duration'])->toBe(5);
});

it('does not count a visit with a custom event as a bounce', function () {
    $this->browse('/', ip: '192.0.2.1');                                   // one pageview: bounce
    $this->browse('/', ip: '192.0.2.2');
    $this->postAs('/fpa/event', ['name' => 'video_played', 'path' => '/'], ['Origin' => 'http://localhost'], ip: '192.0.2.2'); // pageview + event
    $this->postAs('/fpa/event', ['name' => 'widget_opened', 'path' => '/embed'], ['Origin' => 'http://localhost'], ip: '192.0.2.3'); // event only

    $report = new Report(app(Store::class), now()->startOfDay(), now()->addDay()->startOfDay());
    expect($report->summary()['visits'])->toBe(3)->and($report->summary()['bounce_rate'])->toBe(33.3);
});

it('counts funnel steps in order within a visit', function () {
    seedTraffic($this);
    $report = new Report(app(Store::class), now()->startOfDay(), now()->addDay()->startOfDay());

    // Visitor 3 saw /checkout before /pricing, so they reach step 1 only.
    expect($report->funnel(['page:/pricing', 'page:/checkout', 'purchase']))->toBe([
        ['label' => 'page:/pricing', 'visits' => 2, 'rate' => 100.0, 'drop' => null],
        ['label' => 'page:/checkout', 'visits' => 1, 'rate' => 50.0, 'drop' => 50.0],
        ['label' => 'purchase', 'visits' => 1, 'rate' => 50.0, 'drop' => 0.0],
    ]);

    expect($report->funnel(['page:/check*', 'purchase'])[0]['visits'])->toBe(2);
});

it('shows the report on the dashboard for the chosen range', function () {
    seedTraffic($this);
    $this->asLocal();
    config(['first-party-analytics.conversions' => ['purchase'], 'first-party-analytics.funnels' => ['Checkout' => ['page:/pricing', 'page:/checkout', 'purchase']]]);

    $this->get('/analytics?range=today')->assertOk()
        ->assertSeeInOrder(['data-metric="visitors">3<', 'data-metric="visits">3<', 'data-metric="pageviews">5<', 'data-metric="bounce">33.3%<', 'data-metric="duration">1m 03s<', 'data-metric="events">1<'], false)
        ->assertSee('google.com')->assertSee('launch')->assertSee('newsletter')->assertSee('Organic search')
        ->assertSee('Funnel: Checkout')->assertSee('33.3%');

    $this->get('/analytics?range=today&event=purchase')->assertOk()->assertSee('Properties of “purchase”', false);

    // Nothing on another day; the dashboard requests themselves are not tracked.
    $this->get('/analytics?range=custom&from=2026-10-01&to=2026-10-19')->assertOk()->assertSee('data-metric="visits">0<', false);
    $this->get('/analytics?range=custom&from=bad&to=2026-10-19')->assertOk()->assertSee('30 days');
    expect($this->events()->where('path', 'like', '/analytics%')->count())->toBe(0);
});

it('escapes stored values on the dashboard', function () {
    $this->asLocal();
    $this->browse('/?utm_source=<script>alert(1)</script>');

    $this->get('/analytics')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
});
