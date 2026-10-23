<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics;

use AmjadIqbal\FirstPartyAnalytics\Support\Source;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;

/**
 * The numbers behind the dashboard, for one date range [from, to). Visit numbers count visits that
 * started in the range; pageview and event numbers count rows created in the range. Every query is a
 * plain aggregate on the two tables, using the started_at / created_at indexes.
 */
final class Report
{
    public function __construct(
        private readonly Store $store,
        public readonly CarbonInterface $from,
        public readonly CarbonInterface $to,
        private readonly int $top = 10,
    ) {}

    /**
     * @return array{visitors: int, visits: int, pageviews: int, events: int, bounce_rate: ?float, avg_duration: ?int, pages_per_visit: ?float}
     */
    public function summary(): array
    {
        $visits = $this->visits()->count();
        $visitors = (int) $this->visits()->distinct()->count('visitor');
        $pageviews = $this->events()->where('name', Analytics::PAGEVIEW)->count();
        $events = $this->events()->where('name', 'not like', '$%')->count();

        $perVisit = $this->store->connection()->query()
            ->fromSub($this->perVisit(), 'v')
            ->selectRaw('COUNT(*) as n, SUM(CASE WHEN pv <= 1 THEN 1 ELSE 0 END) as bounces, AVG(secs) as secs, AVG(pv) as pv')
            ->first();

        $n = (int) ($perVisit->n ?? 0);

        return [
            'visitors' => $visitors,
            'visits' => $visits,
            'pageviews' => $pageviews,
            'events' => $events,
            'bounce_rate' => $n > 0 ? round(100 * (int) $perVisit->bounces / $n, 1) : null,
            'avg_duration' => $n > 0 ? (int) round((float) $perVisit->secs) : null,
            'pages_per_visit' => $n > 0 ? round((float) $perVisit->pv, 1) : null,
        ];
    }

    /**
     * Visits and pageviews per day, every day of the range present.
     *
     * @return list<array{day: string, visits: int, pageviews: int}>
     */
    public function timeline(): array
    {
        $visits = $this->visits()->selectRaw($this->day('started_at').' as day, COUNT(*) as n')->groupBy('day')->pluck('n', 'day');
        $views = $this->events()->where('name', Analytics::PAGEVIEW)->selectRaw($this->day('created_at').' as day, COUNT(*) as n')->groupBy('day')->pluck('n', 'day');

        $days = [];
        for ($day = CarbonImmutable::parse($this->from)->startOfDay(); $day < $this->to; $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $days[] = ['day' => $key, 'visits' => (int) ($visits[$key] ?? 0), 'pageviews' => (int) ($views[$key] ?? 0)];
        }

        return $days;
    }

    /**
     * @return list<array{label: string, count: int, visits: int}>
     */
    public function topPages(): array
    {
        return $this->events()->where('name', Analytics::PAGEVIEW)
            ->selectRaw('path as label, COUNT(*) as count, COUNT(DISTINCT visit_token) as visits')
            ->groupBy('path')->orderByDesc('count')->orderBy('path')->limit($this->top)
            ->get()->map(fn ($row): array => ['label' => (string) $row->label, 'count' => (int) $row->count, 'visits' => (int) $row->visits])->all();
    }

    /**
     * Visits grouped by one visit column (landing_path, referrer_host, utm_source, browser, os, device, country...).
     *
     * @return list<array{label: ?string, count: int}>
     */
    public function visitsBy(string $column, bool $withoutEmpty = false): array
    {
        abort_unless(in_array($column, ['landing_path', 'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'browser', 'os', 'device', 'country'], true), 400);

        return $this->visits()
            ->when($withoutEmpty, fn (Builder $q) => $q->whereNotNull($column))
            ->selectRaw("{$column} as label, COUNT(*) as count")
            ->groupBy($column)->orderByDesc('count')->orderBy($column)->limit($this->top)
            ->get()->map(fn ($row): array => ['label' => $row->label === null ? null : (string) $row->label, 'count' => (int) $row->count])->all();
    }

    /**
     * Visits per channel (Direct, Organic search, Social, Email, Paid, Campaign, Referral).
     *
     * @return list<array{label: string, count: int}>
     */
    public function channels(): array
    {
        $channels = [];

        $rows = $this->visits()
            ->selectRaw('utm_source, utm_medium, referrer_host, COUNT(*) as count')
            ->groupBy('utm_source', 'utm_medium', 'referrer_host')->get();

        foreach ($rows as $row) {
            $channel = Source::channel($row->utm_source, $row->utm_medium, $row->referrer_host);
            $channels[$channel] = ($channels[$channel] ?? 0) + (int) $row->count;
        }

        arsort($channels);

        return array_map(fn (string $label, int $count): array => ['label' => $label, 'count' => $count], array_keys($channels), array_values($channels));
    }

    /**
     * Custom events: how often, and in how many visits.
     *
     * @return list<array{label: string, count: int, visits: int}>
     */
    public function customEvents(): array
    {
        return $this->events()->where('name', 'not like', '$%')
            ->selectRaw('name as label, COUNT(*) as count, COUNT(DISTINCT visit_token) as visits')
            ->groupBy('name')->orderByDesc('count')->orderBy('name')->limit(50)
            ->get()->map(fn ($row): array => ['label' => (string) $row->label, 'count' => (int) $row->count, 'visits' => (int) $row->visits])->all();
    }

    /**
     * The most common values of each property of one event (newest 5,000 rows).
     *
     * @return array<string, list<array{label: string, count: int}>>
     */
    public function properties(string $event): array
    {
        $tally = [];

        $rows = $this->events()->where('name', $event)->whereNotNull('properties')
            ->orderByDesc('id')->limit(5000)->pluck('properties');

        foreach ($rows as $json) {
            $properties = json_decode((string) $json, true);

            foreach (is_array($properties) ? $properties : [] as $key => $value) {
                $label = is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? 'null');
                $tally[(string) $key][$label] = ($tally[(string) $key][$label] ?? 0) + 1;
            }
        }

        ksort($tally);

        return array_map(function (array $values): array {
            arsort($values);

            return array_map(fn ($label, int $count): array => ['label' => (string) $label, 'count' => $count], array_keys(array_slice($values, 0, $this->top, true)), array_values(array_slice($values, 0, $this->top, true)));
        }, $tally);
    }

    /**
     * For each configured conversion event: the visits that reached it and the conversion rate
     * (converting visits / visits started in the range).
     *
     * @param  list<string>  $goals
     * @return list<array{label: string, visits: int, users: int, rate: ?float}>
     */
    public function conversions(array $goals): array
    {
        if ($goals === []) {
            return [];
        }

        $visits = $this->visits()->count();
        $rows = $this->events()->whereIn('name', $goals)
            ->selectRaw('name, COUNT(DISTINCT visit_token) as visits, COUNT(DISTINCT user_id) as users')
            ->groupBy('name')->get()->keyBy('name');

        return array_map(function (string $goal) use ($rows, $visits): array {
            $converted = (int) ($rows[$goal]->visits ?? 0);

            return [
                'label' => $goal,
                'visits' => $converted,
                'users' => (int) ($rows[$goal]->users ?? 0),
                'rate' => $visits > 0 ? round(100 * $converted / $visits, 1) : null,
            ];
        }, $goals);
    }

    /**
     * An ordered funnel: a visit counts for a step only when it completed the previous step earlier
     * in the same visit. Steps are event names or "page:/path" ("page:/blog/*" matches a prefix).
     *
     * @param  list<string>  $steps
     * @return list<array{label: string, visits: int, rate: ?float, drop: ?float}>
     */
    public function funnel(array $steps): array
    {
        $steps = array_values(array_filter($steps, fn (string $s): bool => trim($s) !== ''));

        if ($steps === []) {
            return [];
        }

        $names = $pages = [];
        foreach ($steps as $step) {
            if (str_starts_with($step, 'page:')) {
                $pages[] = substr($step, 5);
            } else {
                $names[] = $step;
            }
        }

        $query = $this->events()->whereNotNull('visit_token')
            ->where(function (Builder $q) use ($names, $pages): void {
                if ($names !== []) {
                    $q->orWhereIn('name', $names);
                }

                foreach ($pages as $page) {
                    $q->orWhere(function (Builder $q) use ($page): void {
                        $q->where('name', Analytics::PAGEVIEW);
                        str_ends_with($page, '*')
                            ? $q->where('path', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], rtrim($page, '*')).'%')
                            : $q->where('path', $page);
                    });
                }
            })
            ->orderBy('visit_token')->orderBy('created_at')->orderBy('id')
            ->select(['id', 'visit_token', 'name', 'path']);

        $reached = array_fill(0, count($steps), 0);
        $current = null;
        $next = 0;

        foreach ($query->cursor() as $row) {
            if ($row->visit_token !== $current) {
                $current = $row->visit_token;
                $next = 0;
            }

            if ($next < count($steps) && self::matches($steps[$next], (string) $row->name, $row->path)) {
                $reached[$next]++;
                $next++;
            }
        }

        $first = $reached[0];

        return array_map(fn (string $step, int $i): array => [
            'label' => $step,
            'visits' => $reached[$i],
            'rate' => $first > 0 ? round(100 * $reached[$i] / $first, 1) : null,
            'drop' => $i > 0 && $reached[$i - 1] > 0 ? round(100 - 100 * $reached[$i] / $reached[$i - 1], 1) : null,
        ], $steps, array_keys($steps));
    }

    private static function matches(string $step, string $name, ?string $path): bool
    {
        if (! str_starts_with($step, 'page:')) {
            return $name === $step;
        }

        $page = substr($step, 5);

        return $name === Analytics::PAGEVIEW && (str_ends_with($page, '*')
            ? str_starts_with((string) $path, rtrim($page, '*'))
            : $path === $page);
    }

    /** One row per visit started in the range: its pageview count and duration in seconds. */
    private function perVisit(): Builder
    {
        $grammar = $this->store->connection()->getQueryGrammar();
        $created = $grammar->wrap('created_at');

        return $this->store->events()
            ->whereIn('visit_token', $this->visits()->select('token'))
            ->groupBy('visit_token')
            ->select('visit_token')
            ->selectRaw('SUM(CASE WHEN name = ? THEN 1 ELSE 0 END) as pv', [Analytics::PAGEVIEW])
            ->selectRaw($this->seconds("MIN({$created})", "MAX({$created})").' as secs');
    }

    private function visits(): Builder
    {
        return $this->store->visits()->where('started_at', '>=', $this->from)->where('started_at', '<', $this->to);
    }

    private function events(): Builder
    {
        return $this->store->events()->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to);
    }

    private function day(string $column): string
    {
        $column = $this->store->connection()->getQueryGrammar()->wrap($column);

        return match ($this->store->connection()->getDriverName()) {
            'sqlsrv' => "CAST({$column} AS date)",
            'pgsql' => "TO_CHAR({$column}, 'YYYY-MM-DD')",
            default => "DATE({$column})",
        };
    }

    private function seconds(string $from, string $to): string
    {
        return match ($this->store->connection()->getDriverName()) {
            'sqlite' => "(CAST(strftime('%s', {$to}) AS INTEGER) - CAST(strftime('%s', {$from}) AS INTEGER))",
            'pgsql' => "EXTRACT(EPOCH FROM ({$to} - {$from}))",
            'sqlsrv' => "DATEDIFF(SECOND, {$from}, {$to})",
            default => "TIMESTAMPDIFF(SECOND, {$from}, {$to})",
        };
    }
}
