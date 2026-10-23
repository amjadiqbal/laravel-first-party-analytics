<?php

use AmjadIqbal\FirstPartyAnalytics\Facades\Analytics;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\GenericUser;
use Illuminate\Testing\TestResponse;

function cookiesOf(TestResponse $response): array
{
    $cookies = [];
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $response->getCookie($cookie->getName())?->getValue();
    }

    return $cookies;
}

it('sets first-party visitor and visit cookies in cookie mode and keeps the visit', function () {
    config(['first-party-analytics.cookies' => true]);

    $first = $this->browse('/?utm_source=newsletter');
    $cookies = cookiesOf($first);
    expect($cookies)->toHaveKeys(['fpa_visitor', 'fpa_visit']);

    $raw = collect($first->headers->getCookies())->keyBy(fn ($c) => $c->getName());
    expect($raw['fpa_visitor']->isHttpOnly())->toBeTrue()
        ->and($raw['fpa_visitor']->getSameSite())->toBe('lax');

    $this->withCookies($cookies)->browse('/pricing', ['Referer' => 'http://localhost/']);

    expect($this->visits()->count())->toBe(1)
        ->and($this->visits()->value('visitor'))->toBe($cookies['fpa_visitor'])
        ->and($this->events()->distinct()->count('visit_token'))->toBe(1);
});

it('recognises a returning visitor in cookie mode after the visit ended', function () {
    config(['first-party-analytics.cookies' => true]);
    $this->travelTo('2026-10-20 10:00:00');
    $visitor = cookiesOf($this->browse('/'))['fpa_visitor'];

    $this->travelTo('2026-10-25 10:00:00');
    $this->withCookies(['fpa_visitor' => $visitor])->browse('/');

    expect($this->visits()->count())->toBe(2)
        ->and($this->visits()->distinct()->count('visitor'))->toBe(1);
});

it('links the visit and the visitor\'s earlier anonymous visits when the user signs in (cookie mode)', function () {
    config(['first-party-analytics.cookies' => true]);
    $this->travelTo('2026-10-20 10:00:00');
    $visitor = cookiesOf($this->browse('/?utm_source=google&utm_medium=cpc'))['fpa_visitor'];

    $this->travelTo('2026-10-22 10:00:00');
    $cookies = cookiesOf($this->withCookies(['fpa_visitor' => $visitor])->browse('/pricing'));
    $this->withCookies($cookies)->postAs('/login-as/42')->assertOk();

    expect($this->visits()->pluck('user_id')->all())->toBe(['42', '42'])
        ->and($this->events()->pluck('user_id')->unique()->all())->toBe(['42']);

    // The next page knows the visit is linked: no second link.
    $this->withCookies($cookies)->actingAs(new GenericUser(['id' => 42]))->browse('/checkout');
    expect($this->events()->where('path', '/checkout')->value('user_id'))->toBe('42');
});

it('links only the current visit without cookies', function () {
    $this->browse('/');
    $this->postAs('/login-as/7')->assertOk();
    $this->actingAs(new GenericUser(['id' => 7]))->browse('/pricing');

    expect($this->visits()->sole()->user_id)->toBe('7')
        ->and($this->events()->pluck('user_id')->all())->toBe(['7', '7']);
});

it('identifies a user who registered (the Registered event)', function () {
    $this->browse('/');

    $this->app['router']->post('/register', function () {
        event(new Registered(new GenericUser(['id' => 99])));

        return 'ok';
    })->middleware('web');

    $this->postAs('/register')->assertOk();

    expect($this->visits()->value('user_id'))->toBe('99');
});

it('does not start a visit just to identify a user', function () {
    $this->postAs('/login-as/5')->assertOk();

    expect($this->visits()->count())->toBe(0);
});

it('exposes the current visit token', function () {
    $this->app['router']->get('/token', fn () => (string) Analytics::currentVisit())->middleware('web');

    $this->browse('/');
    $token = $this->browse('/token')->getContent();

    expect($token)->toBe($this->visits()->value('token'));
});
