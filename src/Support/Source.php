<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Support;

use Illuminate\Http\Request;

/**
 * Where a visit came from: UTM parameters (or ?ref=), and the referrer when it is another site.
 * The referrer is stored without its query string, which can carry search terms or tokens.
 */
final class Source
{
    public const array UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    private const array SEARCH = ['google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'yandex.', 'baidu.com', 'ecosia.org', 'search.brave.com', 'startpage.com', 'kagi.com', 'qwant.com', 'naver.com'];

    private const array SOCIAL = ['facebook.com', 'fb.com', 'm.facebook.com', 'l.facebook.com', 't.co', 'twitter.com', 'x.com', 'linkedin.com', 'lnkd.in', 'reddit.com', 'instagram.com', 'youtube.com', 'youtu.be', 'pinterest.', 'tiktok.com', 'threads.net', 'bsky.app', 'news.ycombinator.com', 'mastodon.', 'whatsapp.com', 'telegram.org', 't.me'];

    private const array PAID = ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-social', 'paid_social', 'display', 'cpm', 'cpv', 'ads', 'banner'];

    /**
     * @return array{referrer: ?string, referrer_host: ?string, utm_source: ?string, utm_medium: ?string, utm_campaign: ?string, utm_term: ?string, utm_content: ?string}
     */
    public static function fromRequest(Request $request, ?string $referrer = null): array
    {
        $values = [];
        foreach (self::UTM as $key) {
            $value = $request->query($key);
            $values[$key] = is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : null;
        }

        $ref = $request->query('ref');
        if ($values['utm_source'] === null && is_string($ref) && trim($ref) !== '') {
            $values['utm_source'] = mb_substr(trim($ref), 0, 255);
        }

        [$url, $host] = self::external($referrer ?? $request->headers->get('referer'), $request->getHost());

        return ['referrer' => $url, 'referrer_host' => $host] + $values;
    }

    /**
     * A key that changes when a visitor arrives from somewhere new: a new campaign or another site.
     * Null for internal navigation and for direct visits.
     */
    public static function key(Request $request): ?string
    {
        $source = self::fromRequest($request);

        return $source['utm_source'] !== null
            ? 'utm:'.$source['utm_source'].'|'.$source['utm_campaign']
            : ($source['referrer_host'] !== null ? 'ref:'.$source['referrer_host'] : null);
    }

    /**
     * @return array{0: ?string, 1: ?string} the referrer without its query string, and its host, when it is another site
     */
    public static function external(?string $referrer, string $ownHost): array
    {
        if ($referrer === null || $referrer === '') {
            return [null, null];
        }

        $parts = parse_url($referrer);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if ($host === '' || ! in_array($parts['scheme'] ?? '', ['http', 'https', 'android-app'], true)) {
            return [null, null];
        }

        $own = strtolower($ownHost);
        $ignored = array_map(fn ($h): string => self::bare(strtolower((string) $h)), (array) config('first-party-analytics.ignore_referrers', []));
        if ($host === $own || self::bare($host) === self::bare($own) || in_array(self::bare($host), $ignored, true)) {
            return [null, null];
        }

        $url = $parts['scheme'].'://'.$host.($parts['path'] ?? '');

        return [mb_substr($url, 0, 2048), mb_substr(self::bare($host), 0, 255)];
    }

    /** The channel a visit belongs to, from its UTM medium/source and referrer host. */
    public static function channel(?string $source, ?string $medium, ?string $host): string
    {
        $medium = strtolower((string) $medium);
        $probe = strtolower((string) ($host ?? $source));

        return match (true) {
            in_array($medium, self::PAID, true) => 'Paid',
            in_array($medium, ['email', 'e-mail', 'newsletter', 'mail'], true) => 'Email',
            in_array($medium, ['social', 'social-media', 'social_media', 'sm'], true) => 'Social',
            in_array($medium, ['organic', 'seo'], true) => 'Organic search',
            in_array($medium, ['affiliate', 'partner', 'referral'], true) => 'Referral',
            $probe !== '' && self::matches($probe, self::SEARCH) => 'Organic search',
            $probe !== '' && self::matches($probe, self::SOCIAL) => 'Social',
            $source !== null && $source !== '' => 'Campaign',
            $host !== null && $host !== '' => 'Referral',
            default => 'Direct',
        };
    }

    /**
     * @param  list<string>  $needles
     */
    private static function matches(string $host, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($host === rtrim($needle, '.') || str_starts_with($host, $needle) || str_contains($host, '.'.$needle) || (str_ends_with($needle, '.') && str_contains($host, $needle))) {
                return true;
            }
        }

        return false;
    }

    private static function bare(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
