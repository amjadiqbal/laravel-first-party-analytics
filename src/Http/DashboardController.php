<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Http;

use AmjadIqbal\FirstPartyAnalytics\FirstPartyAnalyticsServiceProvider;
use AmjadIqbal\FirstPartyAnalytics\Report;
use AmjadIqbal\FirstPartyAnalytics\Store;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Throwable;

final class DashboardController
{
    public const array RANGES = ['today' => 'Today', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days', '12m' => '12 months'];

    public function __invoke(Request $request, Store $store): View
    {
        [$from, $to, $range] = $this->range($request);
        $report = new Report($store, $from, $to, max(1, (int) config('first-party-analytics.dashboard.top', 10)));

        $goals = array_values(array_filter((array) config('first-party-analytics.conversions', []), 'is_string'));
        $funnels = [];
        foreach ((array) config('first-party-analytics.funnels', []) as $name => $steps) {
            $funnels[(string) $name] = $report->funnel(array_values((array) $steps));
        }

        $event = $request->query('event');
        $event = is_string($event) && $event !== '' ? mb_substr($event, 0, 100) : null;

        return view('first-party-analytics::dashboard', [
            'report' => $report,
            'range' => $range,
            'ranges' => self::RANGES,
            'from' => $from,
            'to' => $to->subSecond(),
            'summary' => $report->summary(),
            'timeline' => $report->timeline(),
            'pages' => $report->topPages(),
            'entries' => $report->visitsBy('landing_path'),
            'channels' => $report->channels(),
            'referrers' => $report->visitsBy('referrer_host', true),
            'utmSources' => $report->visitsBy('utm_source', true),
            'utmMediums' => $report->visitsBy('utm_medium', true),
            'utmCampaigns' => $report->visitsBy('utm_campaign', true),
            'devices' => $report->visitsBy('device'),
            'browsers' => $report->visitsBy('browser'),
            'systems' => $report->visitsBy('os'),
            'countries' => $report->visitsBy('country', true),
            'events' => $report->customEvents(),
            'event' => $event,
            'properties' => $event !== null ? $report->properties($event) : [],
            'conversions' => $report->conversions($goals),
            'funnels' => $funnels,
            'version' => FirstPartyAnalyticsServiceProvider::version(),
            'cookies' => (bool) config('first-party-analytics.cookies'),
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function range(Request $request): array
    {
        $now = CarbonImmutable::instance(Date::now());
        $tomorrow = $now->addDay()->startOfDay();
        $range = (string) $request->query('range', '30d');

        if ($range === 'custom') {
            try {
                $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->query('from'));
                $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->query('to'));

                if ($from instanceof CarbonImmutable && $to instanceof CarbonImmutable && $from <= $to) {
                    $from = $from->max($to->subDays(400));

                    return [$from, $to->addDay(), 'custom'];
                }
            } catch (Throwable) {
                // An invalid date falls back to the default range.
            }
        }

        return match ($range) {
            'today' => [$now->startOfDay(), $tomorrow, 'today'],
            '7d' => [$now->subDays(6)->startOfDay(), $tomorrow, '7d'],
            '90d' => [$now->subDays(89)->startOfDay(), $tomorrow, '90d'],
            '12m' => [$now->subMonths(12)->addDay()->startOfDay(), $tomorrow, '12m'],
            default => [$now->subDays(29)->startOfDay(), $tomorrow, '30d'],
        };
    }
}
