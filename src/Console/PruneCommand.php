<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Console;

use AmjadIqbal\FirstPartyAnalytics\Store;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;

final class PruneCommand extends Command
{
    protected $signature = 'analytics:prune
        {--days= : Delete visits and events older than this many days (default: retention_days)}
        {--pretend : Count what would be deleted, delete nothing}';

    protected $description = 'Delete analytics visits and events older than the retention period';

    public function handle(Store $store): int
    {
        $days = $this->option('days') ?? config('first-party-analytics.retention_days');

        if ($days === null || $days === '') {
            $this->info('retention_days is null: nothing is pruned.');

            return self::SUCCESS;
        }

        if (! is_numeric($days) || (int) $days < 1) {
            $this->error('--days must be a whole number of at least 1.');

            return self::FAILURE;
        }

        $days = (int) $days;
        $cutoff = Date::now()->subDays($days);
        $events = $store->events()->where('created_at', '<', $cutoff);
        $visits = $store->visits()->where('started_at', '<', $cutoff);
        $unit = $days === 1 ? 'day' : 'days';

        if ($this->option('pretend')) {
            $this->info(sprintf('Would delete %d visits and %d events older than %d %s.', $visits->count(), $events->count(), $days, $unit));

            return self::SUCCESS;
        }

        $deletedEvents = $this->chunkedDelete($events);
        $deletedVisits = $this->chunkedDelete($visits);

        $this->info(sprintf('Deleted %d visits and %d events older than %d %s.', $deletedVisits, $deletedEvents, $days, $unit));

        return self::SUCCESS;
    }

    /** Deletes in chunks of 1,000 ids, so a large table is never locked by one huge DELETE. */
    private function chunkedDelete(Builder $query): int
    {
        $deleted = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit(1000)->pluck('id')->all();

            if ($ids !== []) {
                $deleted += $query->newQuery()->from($query->from)->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === 1000);

        return $deleted;
    }
}
