<?php

use AmjadIqbal\FirstPartyAnalytics\Facades\Analytics;

beforeEach(function () {
    $this->travelTo('2026-01-01 12:00:00');
    $this->browse('/');
    Analytics::track('old_event');
    $this->travelTo('2026-10-20 12:00:00');
    $this->browse('/', ip: '192.0.2.9');
    Analytics::track('new_event');
});

it('deletes visits and events older than the retention period', function () {
    config(['first-party-analytics.retention_days' => 90]);

    $this->artisan('analytics:prune')->expectsOutput('Deleted 1 visits and 2 events older than 90 days.')->assertSuccessful();

    expect($this->visits()->count())->toBe(1)
        ->and($this->events()->pluck('name')->all())->toBe(['$pageview', 'new_event']);
});

it('only counts with --pretend, and takes --days', function () {
    $this->artisan('analytics:prune --days=1 --pretend')->expectsOutput('Would delete 1 visits and 2 events older than 1 day.')->assertSuccessful();
    expect($this->events()->count())->toBe(4);

    $this->artisan('analytics:prune --days=400')->expectsOutput('Deleted 0 visits and 0 events older than 400 days.');
});

it('keeps everything when retention is null and rejects bad input', function () {
    config(['first-party-analytics.retention_days' => null]);
    $this->artisan('analytics:prune')->expectsOutput('retention_days is null: nothing is pruned.')->assertSuccessful();

    $this->artisan('analytics:prune --days=0')->assertFailed();
    expect($this->events()->count())->toBe(4);
});

it('deletes in chunks', function () {
    $rows = array_map(fn ($i) => ['visit_token' => null, 'user_id' => null, 'name' => 'bulk', 'path' => null, 'properties' => null, 'created_at' => '2025-01-01 00:00:00'], range(1, 2500));
    foreach (array_chunk($rows, 500) as $chunk) {
        $this->events()->insert($chunk);
    }

    $this->artisan('analytics:prune --days=30')->expectsOutput('Deleted 1 visits and 2502 events older than 30 days.');
});
