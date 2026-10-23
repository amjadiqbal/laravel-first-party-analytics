<?php

use AmjadIqbal\FirstPartyAnalytics\Facades\Analytics;
use AmjadIqbal\FirstPartyAnalytics\Jobs\RecordAnalytics;
use AmjadIqbal\FirstPartyAnalytics\Recorder;
use AmjadIqbal\FirstPartyAnalytics\Store;
use AmjadIqbal\FirstPartyAnalytics\Tests\TestCase;
use AmjadIqbal\FirstPartyAnalytics\Visits;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;

it('records a PHP event inside the visitor\'s visit', function () {
    $this->browse('/pricing');
    $this->postAs('/buy');

    $event = $this->events()->where('name', 'purchase')->sole();
    expect($event->visit_token)->toBe($this->visits()->value('token'))
        ->and($event->path)->toBe('/buy')
        ->and(json_decode($event->properties, true))->toBe(['plan' => 'pro', 'amount' => 49]);
});

it('records events outside a request without a visit, for a user', function () {
    Analytics::track('invoice_paid', ['amount' => 49.5, 'paid' => true], new GenericUser(['id' => 12]));
    Analytics::track('trial_ended', [], 'uuid-1');

    expect($this->events()->orderBy('id')->get(['name', 'user_id', 'visit_token'])->map(fn ($r) => (array) $r)->all())->toBe([
        ['name' => 'invoice_paid', 'user_id' => '12', 'visit_token' => null],
        ['name' => 'trial_ended', 'user_id' => 'uuid-1', 'visit_token' => null],
    ])->and($this->visits()->count())->toBe(0);
});

it('keeps properties small and flat, and refuses reserved names', function () {
    Analytics::track('big', [
        'nested' => ['a' => 1],
        'long' => str_repeat('x', 400),
        'nothing' => null,
        ...array_combine(array_map(fn ($i) => "k{$i}", range(1, 40)), range(1, 40)),
    ]);
    Analytics::track('$pageview');
    Analytics::track('  ');

    $properties = json_decode($this->events()->sole()->properties, true);
    expect($properties)->toHaveCount(25)->not->toHaveKey('nested')
        ->and(strlen($properties['long']))->toBe(255)
        ->and($properties)->toHaveKey('nothing');
});

it('does not record PHP events for bots or Do Not Track visitors', function () {
    $this->postAs('/buy', ua: 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)');
    $this->postAs('/buy', headers: ['DNT' => '1']);

    expect($this->events()->count())->toBe(0);
});

it('accepts browser events from the same origin', function () {
    $this->browse('/pricing');
    $this->postAs('/fpa/event', ['name' => 'cta_clicked', 'properties' => ['button' => 'hero'], 'path' => '/pricing?x=1#top'], ['Origin' => 'http://localhost'])
        ->assertNoContent();

    $event = $this->events()->where('name', 'cta_clicked')->sole();
    expect($event->path)->toBe('/pricing')
        ->and($event->visit_token)->toBe($this->visits()->value('token'))
        ->and(json_decode($event->properties, true))->toBe(['button' => 'hero']);
});

it('starts a visit for a browser event when none is in progress', function () {
    $this->postAs('/fpa/event', ['name' => 'video_played', 'path' => '/watch'], ['Origin' => 'http://localhost']);

    expect($this->visits()->value('landing_path'))->toBe('/watch');
});

it('rejects browser events whose origin differs in scheme, host or port', function (array $headers) {
    $this->postAs('/fpa/event', ['name' => 'spam', 'path' => '/'], $headers)->assertForbidden();

    expect($this->events()->count())->toBe(0);
})->with([
    'https vs http' => [['Origin' => 'https://localhost']],
    'other port' => [['Origin' => 'http://localhost:8080']],
    'subdomain' => [['Origin' => 'http://evil.localhost']],
    'referer other scheme' => [['Referer' => 'https://localhost/page']],
]);

it('accepts the Referer when a browser sends no Origin, if it is exactly this site', function () {
    $this->postAs('/fpa/event', ['name' => 'from_referer', 'path' => '/'], ['Referer' => 'http://localhost/pricing'])->assertNoContent();

    expect($this->events()->where('name', 'from_referer')->count())->toBe(1);
});

it('rejects browser events from other origins or without an origin', function (array $headers) {
    $this->postAs('/fpa/event', ['name' => 'spam', 'path' => '/'], $headers)->assertForbidden();

    expect($this->events()->count())->toBe(0);
})->with([
    'other origin' => [['Origin' => 'https://evil.example']],
    'other referer' => [['Referer' => 'https://evil.example/page']],
    'none' => [[]],
]);

it('ignores reserved, empty and unlisted browser event names', function () {
    $origin = ['Origin' => 'http://localhost'];
    $this->postAs('/fpa/event', ['name' => '$pageview', 'path' => '/'], $origin)->assertNoContent();
    $this->postAs('/fpa/event', ['name' => '', 'path' => '/'], $origin)->assertNoContent();

    config(['first-party-analytics.js.allowed_events' => ['signup']]);
    $this->postAs('/fpa/event', ['name' => 'random', 'path' => '/'], $origin)->assertNoContent();
    $this->postAs('/fpa/event', ['name' => 'signup', 'path' => '/'], $origin)->assertNoContent();

    expect($this->events()->pluck('name')->all())->toBe(['signup']);
});

it('rate limits the browser endpoint per IP', function () {
    config(['first-party-analytics.js.rate_limit' => 3]);

    foreach (range(1, 3) as $i) {
        $this->postAs('/fpa/event', ['name' => 'e', 'path' => '/'], ['Origin' => 'http://localhost'])->assertNoContent();
    }

    $this->postAs('/fpa/event', ['name' => 'e', 'path' => '/'], ['Origin' => 'http://localhost'])->assertTooManyRequests();
});

it('renders the browser script with the endpoint, and nothing when disabled', function () {
    $html = $this->browse('/directive')->assertOk()->getContent();
    expect($html)->toContain('navigator.sendBeacon')->toContain('"\/fpa\/event"')->toContain('data-analytics-event');

    config(['first-party-analytics.js.enabled' => false]);
    expect(Blade::render('@firstPartyAnalytics'))->toBe('');
});

it('buffers a request\'s writes until the application terminates', function () {
    $recorder = app(Recorder::class);
    $recorder->buffer = true;

    // The test client terminates the application after each request, like a real server does.
    $this->browse('/');
    expect($this->events()->count())->toBe(1);

    $recorder->push(['op' => 'event', 'row' => ['visit_token' => null, 'user_id' => null, 'name' => 'x', 'path' => null, 'properties' => null, 'created_at' => now()]]);
    expect($this->events()->count())->toBe(1)->and($recorder->pending())->toHaveCount(1);

    $this->app->terminate();
    expect($this->events()->count())->toBe(2);
});

it('saves no-cookie visit state to the cache only after the response', function () {
    $recorder = app(Recorder::class);
    $recorder->buffer = true;
    $cache = app('cache')->store();

    $request = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => TestCase::CHROME, 'REMOTE_ADDR' => '192.0.2.1']);
    $visit = app(Visits::class)->resolve($request);

    expect($cache->get('fpa:visit:'.$visit['visitor']))->toBeNull();

    $recorder->flush();
    expect($cache->get('fpa:visit:'.$visit['visitor'])['token'])->toBe($visit['token']);
});

it('writes one queued job per request in queue mode', function () {
    Queue::fake();
    config(['first-party-analytics.write' => 'queue', 'first-party-analytics.queue.queue' => 'analytics']);
    app(Recorder::class)->buffer = true;

    $this->browse('/?utm_source=x');

    Queue::assertPushedOn('analytics', RecordAnalytics::class, fn (RecordAnalytics $job) => array_column($job->operations, 'op') === ['visit', 'event']);
    expect($this->visits()->count())->toBe(0);

    (new RecordAnalytics(Queue::pushedJobs()[RecordAnalytics::class][0]['job']->operations))->handle(app(Store::class));
    expect($this->visits()->count())->toBe(1)->and($this->events()->count())->toBe(1);
});

it('never breaks the page when a write fails', function () {
    config(['first-party-analytics.database.visits_table' => 'missing_table']);

    $this->browse('/')->assertOk()->assertSee('Home');
});
