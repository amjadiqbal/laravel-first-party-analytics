<?php

use AmjadIqbal\FirstPartyAnalytics\Support\Ip;
use AmjadIqbal\FirstPartyAnalytics\Support\Source;
use AmjadIqbal\FirstPartyAnalytics\Support\UserAgent;

it('parses browsers, systems and devices', function (string $ua, array $expected) {
    expect(UserAgent::parse($ua))->toBe($expected);
})->with([
    'chrome mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', ['browser' => 'Chrome', 'os' => 'macOS', 'device' => 'desktop']],
    'edge windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', ['browser' => 'Edge', 'os' => 'Windows', 'device' => 'desktop']],
    'firefox linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:144.0) Gecko/20100101 Firefox/144.0', ['browser' => 'Firefox', 'os' => 'Linux', 'device' => 'desktop']],
    'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1', ['browser' => 'Safari', 'os' => 'iOS', 'device' => 'mobile']],
    'chrome ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0 Mobile/15E148 Safari/604.1', ['browser' => 'Chrome', 'os' => 'iOS', 'device' => 'mobile']],
    'samsung android' => ['Mozilla/5.0 (Linux; Android 15; SM-S928B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Mobile Safari/537.36', ['browser' => 'Samsung Internet', 'os' => 'Android', 'device' => 'mobile']],
    'android tablet' => ['Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', ['browser' => 'Chrome', 'os' => 'Android', 'device' => 'tablet']],
    'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', ['browser' => 'Safari', 'os' => 'iPadOS', 'device' => 'tablet']],
    'opera' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 OPR/125.0.0.0', ['browser' => 'Opera', 'os' => 'Windows', 'device' => 'desktop']],
    'empty' => ['', ['browser' => null, 'os' => null, 'device' => null]],
]);

it('anonymises IPv4 and IPv6 addresses', function () {
    expect(Ip::anonymize('192.168.10.42'))->toBe('192.168.10.0')
        ->and(Ip::anonymize('2001:0db8:85a3:0000:0000:8a2e:0370:7334'))->toBe('2001:db8:85a3::')
        ->and(Ip::anonymize('not an ip'))->toBeNull()
        ->and(Ip::anonymize(null))->toBeNull()
        ->and(Ip::forStorage('not an ip', 'full'))->toBeNull();
});

it('keeps only external referrers, without their query string', function () {
    expect(Source::external('https://www.google.com/search?q=private', 'example.com'))->toBe(['https://www.google.com/search', 'google.com'])
        ->and(Source::external('https://example.com/page', 'example.com'))->toBe([null, null])
        ->and(Source::external('https://www.example.com/page', 'example.com'))->toBe([null, null])
        ->and(Source::external('javascript:alert(1)', 'example.com'))->toBe([null, null])
        ->and(Source::external('', 'example.com'))->toBe([null, null]);
});

it('classifies channels', function (?string $source, ?string $medium, ?string $host, string $channel) {
    expect(Source::channel($source, $medium, $host))->toBe($channel);
})->with([
    'direct' => [null, null, null, 'Direct'],
    'google' => [null, null, 'google.com', 'Organic search'],
    'google uk' => [null, null, 'google.co.uk', 'Organic search'],
    'duckduckgo' => [null, null, 'duckduckgo.com', 'Organic search'],
    'hacker news' => [null, null, 'news.ycombinator.com', 'Social'],
    't.co' => [null, null, 't.co', 'Social'],
    'blog' => [null, null, 'laravel-news.com', 'Referral'],
    'paid' => ['google', 'cpc', 'google.com', 'Paid'],
    'email' => ['newsletter', 'email', null, 'Email'],
    'social medium' => ['partner', 'social', null, 'Social'],
    'campaign' => ['producthunt', null, null, 'Campaign'],
]);
