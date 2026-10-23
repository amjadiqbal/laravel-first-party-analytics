<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Analytics · {{ config('app.name') }}</title>
    <style>
        :root {
            --bg: #F8FAFC; --panel: #FFFFFF; --border: #E2E8F0; --text: #0F172A; --muted: #64748B; --soft: #F1F5F9;
            --accent: #059669; --accent-text: #047857; --on-accent: #FFFFFF; --bar: #A7F3D0; --bar-2: #059669; --good: #047857; --bad: #DC2626;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                /* Solid colours only: translucent warm fills read as brown on dark. */
                --bg: #0B1220; --panel: #111C33; --border: #24324F; --text: #F8FAFC; --muted: #94A3B8; --soft: #16213A;
                --accent: #34D399; --accent-text: #6EE7B7; --on-accent: #0B1220; --bar: #1F4D45; --bar-2: #34D399; --good: #6EE7B7; --bad: #F87171;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Helvetica, Arial, sans-serif; }
        a { color: var(--accent-text); }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 28px 16px 56px; }
        header.top { display: flex; flex-wrap: wrap; gap: 12px 24px; align-items: center; justify-content: space-between; margin-bottom: 18px; }
        .brand { display: flex; align-items: center; gap: 12px; color: inherit; text-decoration: none; }
        .brand h1 { font-size: 22px; margin: 0; letter-spacing: -0.01em; }
        .sub { color: var(--muted); font-size: 13.5px; }
        .ranges { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .ranges a, .ranges button { display: inline-block; padding: 6px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--panel); color: var(--text); text-decoration: none; font: inherit; font-size: 13.5px; cursor: pointer; }
        .ranges a.on { background: var(--accent); border-color: var(--accent); color: var(--on-accent); font-weight: 600; }
        .ranges form { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        input { font: inherit; font-size: 13.5px; padding: 5px 8px; border: 1px solid var(--border); border-radius: 8px; background: var(--panel); color: var(--text); }
        .summary { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
        .stat { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 12px 14px; }
        .stat .n { font-size: 25px; font-weight: 700; line-height: 1.15; }
        .stat .l { color: var(--muted); font-size: 13px; }
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; min-width: 0; }
        .panel h2 { font-size: 15px; margin: 0 0 10px; display: flex; justify-content: space-between; gap: 8px; }
        .panel h2 small { color: var(--muted); font-weight: 500; font-size: 12.5px; }
        .grid2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; }
        .grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .grid2 > .panel, .grid3 > .panel { margin-bottom: 0; }
        .stack { display: grid; gap: 14px; margin-bottom: 14px; }
        ol.bars { list-style: none; margin: 0; padding: 0; }
        ol.bars li { position: relative; display: flex; justify-content: space-between; gap: 12px; padding: 5px 8px; margin-bottom: 4px; border-radius: 6px; font-size: 14px; }
        ol.bars li .fill { position: absolute; inset: 0 auto 0 0; background: var(--soft); border-radius: 6px; z-index: 0; }
        ol.bars li span { position: relative; z-index: 1; }
        ol.bars li .k { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
        ol.bars li .v { font-variant-numeric: tabular-nums; color: var(--muted); white-space: nowrap; }
        ol.bars li .v b { color: var(--text); font-weight: 600; }
        .empty { color: var(--muted); font-size: 13.5px; padding: 6px 0; }
        .chart { width: 100%; height: 150px; display: block; }
        .chart rect.v { fill: var(--bar); }
        .chart rect.v:hover { fill: var(--bar-2); }
        .axis { display: flex; justify-content: space-between; gap: 8px; color: var(--muted); font-size: 12px; margin-top: 6px; }
        table.t { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.t th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; padding: 6px 8px; border-bottom: 1px solid var(--border); }
        table.t td { padding: 7px 8px; border-top: 1px solid var(--border); font-variant-numeric: tabular-nums; }
        table.t tr:first-child td { border-top: 0; }
        table.t td.num, table.t th.num { text-align: right; }
        .rate { color: var(--good); font-weight: 600; }
        .drop { color: var(--bad); font-size: 12.5px; }
        .note { border: 1px solid var(--border); border-left: 4px solid var(--accent); background: var(--panel); border-radius: 10px; padding: 11px 14px; margin-bottom: 14px; font-size: 13.5px; color: var(--muted); }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; }
        footer { margin-top: 30px; color: var(--muted); font-size: 13px; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; }
        @media (max-width: 900px) { .summary { grid-template-columns: repeat(3, minmax(0, 1fr)); } .grid3 { grid-template-columns: minmax(0, 1fr); } }
        @media (max-width: 680px) { .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } .grid2 { grid-template-columns: minmax(0, 1fr); } }
    </style>
</head>
<body>
@php
    $fmtDuration = function (?int $s): string {
        if ($s === null) { return '–'; }
        return $s >= 60 ? intdiv($s, 60).'m '.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT).'s' : $s.'s';
    };
    $query = fn (array $extra = []): array => array_filter([...request()->only(['range', 'from', 'to', 'event']), ...$extra], fn ($v) => $v !== null && $v !== '');
    $maxDay = max(1, ...array_map(fn ($d) => $d['visits'], $timeline));
@endphp
<div class="wrap">
    <header class="top">
        <a class="brand" href="{{ route('first-party-analytics.dashboard') }}">
            <svg width="38" height="38" viewBox="0 0 512 512" aria-hidden="true">
                <rect x="8" y="8" width="496" height="496" rx="108" fill="#0F172A" stroke="#334155" stroke-width="16"/>
                <rect x="122" y="292" width="40" height="80" rx="8" fill="#475569"/>
                <rect x="186" y="252" width="40" height="120" rx="8" fill="#475569"/>
                <rect x="250" y="272" width="40" height="100" rx="8" fill="#475569"/>
                <rect x="314" y="212" width="40" height="160" rx="8" fill="#475569"/>
                <path d="M120 236 L196 186 L262 206 L344 132" fill="none" stroke="#34D399" stroke-width="26" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M396 300 L462 326 V378 C462 420 434 448 396 462 C358 448 330 420 330 378 V326 Z" fill="#059669" stroke="#0F172A" stroke-width="14" stroke-linejoin="round"/>
            </svg>
            <div>
                <h1>Analytics</h1>
                <div class="sub">{{ $from->format('j M Y') }} – {{ $to->format('j M Y') }} · first-party, {{ $cookies ? 'with cookies' : 'no cookies' }}</div>
            </div>
        </a>
        <nav class="ranges" aria-label="Date range">
            @foreach ($ranges as $key => $label)
                <a href="{{ route('first-party-analytics.dashboard', ['range' => $key]) }}" class="{{ $range === $key ? 'on' : '' }}">{{ $label }}</a>
            @endforeach
            <form method="get" action="{{ route('first-party-analytics.dashboard') }}">
                <input type="hidden" name="range" value="custom">
                <input type="date" name="from" value="{{ $from->format('Y-m-d') }}" aria-label="From">
                <input type="date" name="to" value="{{ $to->format('Y-m-d') }}" aria-label="To">
                <button type="submit">Apply</button>
            </form>
        </nav>
    </header>

    <section class="summary" aria-label="Summary">
        <div class="stat"><div class="n" data-metric="visitors">{{ number_format($summary['visitors']) }}</div><div class="l">Unique visitors</div></div>
        <div class="stat"><div class="n" data-metric="visits">{{ number_format($summary['visits']) }}</div><div class="l">Visits</div></div>
        <div class="stat"><div class="n" data-metric="pageviews">{{ number_format($summary['pageviews']) }}</div><div class="l">Pageviews</div></div>
        <div class="stat"><div class="n" data-metric="bounce">{{ $summary['bounce_rate'] === null ? '–' : $summary['bounce_rate'].'%' }}</div><div class="l">Bounce rate</div></div>
        <div class="stat"><div class="n" data-metric="duration">{{ $fmtDuration($summary['avg_duration']) }}</div><div class="l">Avg. visit</div></div>
        <div class="stat"><div class="n" data-metric="events">{{ number_format($summary['events']) }}</div><div class="l">Events</div></div>
    </section>

    <section class="panel" aria-label="Visits per day">
        <h2>Visits per day <small>{{ $summary['pages_per_visit'] === null ? '' : $summary['pages_per_visit'].' pages per visit' }}</small></h2>
        @php $n = count($timeline); $w = 1000 / max(1, $n); @endphp
        <svg class="chart" viewBox="0 0 1000 150" preserveAspectRatio="none" role="img" aria-label="Visits per day">
            @foreach ($timeline as $i => $d)
                @php $h = round(145 * $d['visits'] / $maxDay, 2); @endphp
                <rect class="v" x="{{ round($i * $w + $w * 0.12, 2) }}" y="{{ 150 - max($h, 0.5) }}" width="{{ round($w * 0.76, 2) }}" height="{{ max($h, 0.5) }}" rx="2"><title>{{ $d['day'] }}: {{ $d['visits'] }} visits, {{ $d['pageviews'] }} pageviews</title></rect>
            @endforeach
        </svg>
        <div class="axis"><span>{{ $timeline[0]['day'] ?? '' }}</span><span>max {{ $maxDay }} visits a day</span><span>{{ $timeline[$n - 1]['day'] ?? '' }}</span></div>
    </section>

    @php
        $list = function (array $rows, string $empty = 'No data in this range.', ?string $nullLabel = null) {
            $max = max(1, ...array_map(fn ($r) => $r['count'], $rows ?: [['count' => 1]]));
            if ($rows === []) { return '<p class="empty">'.e($empty).'</p>'; }
            $html = '<ol class="bars">';
            foreach ($rows as $r) {
                $label = $r['label'] ?? $nullLabel ?? '(unknown)';
                $extra = isset($r['visits']) ? ' <span class="sub">'.number_format($r['visits']).' visits</span>' : '';
                $html .= '<li><span class="fill" style="width:'.round(100 * $r['count'] / $max, 1).'%"></span><span class="k" title="'.e($label).'">'.e($label).'</span><span class="v"><b>'.number_format($r['count']).'</b>'.$extra.'</span></li>';
            }
            return $html.'</ol>';
        };
    @endphp

    <div class="grid2 stack">
        <section class="panel" data-panel="pages"><h2>Top pages <small>pageviews</small></h2>{!! $list($pages) !!}</section>
        <section class="panel" data-panel="entries"><h2>Entry pages <small>visits</small></h2>{!! $list($entries) !!}</section>
    </div>

    <div class="grid3 stack">
        <section class="panel" data-panel="channels"><h2>Channels <small>visits</small></h2>{!! $list($channels) !!}</section>
        <section class="panel" data-panel="referrers"><h2>Referrers <small>visits</small></h2>{!! $list($referrers, 'No visits from other sites yet.') !!}</section>
        <section class="panel" data-panel="utm_source"><h2>UTM source <small>visits</small></h2>{!! $list($utmSources, 'No tagged links yet.') !!}</section>
        <section class="panel" data-panel="utm_medium"><h2>UTM medium</h2>{!! $list($utmMediums, 'No tagged links yet.') !!}</section>
        <section class="panel" data-panel="utm_campaign"><h2>UTM campaign</h2>{!! $list($utmCampaigns, 'No tagged links yet.') !!}</section>
        <section class="panel" data-panel="countries"><h2>Countries <small>from your CDN header</small></h2>{!! $list($countries, 'No country header seen.') !!}</section>
        <section class="panel" data-panel="devices"><h2>Devices</h2>{!! $list($devices) !!}</section>
        <section class="panel" data-panel="browsers"><h2>Browsers</h2>{!! $list($browsers) !!}</section>
        <section class="panel" data-panel="os"><h2>Operating systems</h2>{!! $list($systems) !!}</section>
    </div>

    <div class="grid2 stack">
        <section class="panel" data-panel="events">
            <h2>Events <small>click one for its properties</small></h2>
            @if ($events === [])
                <p class="empty">No custom events yet. Call <code>Analytics::track('signup')</code> or <code>analytics.track('signup')</code>.</p>
            @else
                <table class="t">
                    <thead><tr><th>Event</th><th class="num">Count</th><th class="num">Visits</th></tr></thead>
                    <tbody>
                    @foreach ($events as $e)
                        <tr><td><a href="{{ route('first-party-analytics.dashboard', $query(['event' => $e['label']])) }}">{{ $e['label'] }}</a></td><td class="num">{{ number_format($e['count']) }}</td><td class="num">{{ number_format($e['visits']) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
        <section class="panel" data-panel="conversions">
            <h2>Conversions <small>visits that reached the goal</small></h2>
            @if ($conversions === [])
                <p class="empty">List goal events in <code>conversions</code> in config/first-party-analytics.php.</p>
            @else
                <table class="t">
                    <thead><tr><th>Goal</th><th class="num">Visits</th><th class="num">Users</th><th class="num">Rate</th></tr></thead>
                    <tbody>
                    @foreach ($conversions as $c)
                        <tr><td>{{ $c['label'] }}</td><td class="num">{{ number_format($c['visits']) }}</td><td class="num">{{ number_format($c['users']) }}</td><td class="num rate">{{ $c['rate'] === null ? '–' : $c['rate'].'%' }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>

    @if ($event !== null)
        <section class="panel" data-panel="properties">
            <h2>Properties of “{{ $event }}” <small><a href="{{ route('first-party-analytics.dashboard', $query(['event' => null])) }}">close</a></small></h2>
            @forelse ($properties as $key => $values)
                <h3 class="sub" style="margin:10px 0 6px"><code>{{ $key }}</code></h3>
                {!! $list($values) !!}
            @empty
                <p class="empty">This event has no properties in this range.</p>
            @endforelse
        </section>
    @endif

    @foreach ($funnels as $name => $steps)
        <section class="panel" data-panel="funnel">
            <h2>Funnel: {{ $name }} <small>ordered steps within one visit</small></h2>
            @if ($steps === [])
                <p class="empty">This funnel has no steps.</p>
            @else
                <table class="t">
                    <thead><tr><th>Step</th><th class="num">Visits</th><th class="num">Of step 1</th><th class="num">Drop-off</th></tr></thead>
                    <tbody>
                    @foreach ($steps as $i => $s)
                        <tr><td>{{ $i + 1 }}. <code>{{ $s['label'] }}</code></td><td class="num">{{ number_format($s['visits']) }}</td><td class="num rate">{{ $s['rate'] === null ? '–' : $s['rate'].'%' }}</td><td class="num drop">{{ $s['drop'] === null ? '' : $s['drop'].'%' }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endforeach

    <footer>
        <span>laravel-first-party-analytics {{ $version }}</span>
        <span>Stored in your own database. Bots, Do-Not-Track and GPC are not recorded.</span>
    </footer>
</div>
</body>
</html>
