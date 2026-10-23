<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Writes recorded operations: new visits and events as batched inserts, and user links as updates.
 *
 * Operations:
 *   ['op' => 'visit', 'row' => [...]]
 *   ['op' => 'event', 'row' => [...]]
 *   ['op' => 'link', 'token' => string, 'visitor' => ?string, 'user' => string]
 *     sets the user on the visit, and (with a visitor cookie) on the visitor's earlier anonymous visits
 */
final class Store
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     */
    public function apply(array $operations): void
    {
        $visits = $events = $links = [];

        foreach ($operations as $operation) {
            match ($operation['op'] ?? null) {
                'visit' => $visits[] = $operation['row'],
                'event' => $events[] = $operation['row'],
                'link' => $links[] = $operation,
                default => null,
            };
        }

        foreach (array_chunk($visits, 200) as $chunk) {
            $this->visits()->insert($chunk);
        }

        foreach (array_chunk($events, 200) as $chunk) {
            $this->events()->insert($chunk);
        }

        foreach ($links as $link) {
            $this->visits()->where('token', $link['token'])->update(['user_id' => $link['user']]);
            $tokens = [$link['token']];

            if (($link['visitor'] ?? null) !== null) {
                $this->visits()->where('visitor', $link['visitor'])->whereNull('user_id')->update(['user_id' => $link['user']]);
                $tokens = $this->visits()->where('visitor', $link['visitor'])->where('user_id', $link['user'])->pluck('token')->all();
            }

            foreach (array_chunk($tokens, 500) as $chunk) {
                $this->events()->whereIn('visit_token', $chunk)->whereNull('user_id')->update(['user_id' => $link['user']]);
            }
        }
    }

    public function connection(): Connection
    {
        return $this->db->connection(config('first-party-analytics.database.connection'));
    }

    public function visits(): Builder
    {
        return $this->connection()->table(self::visitsTable());
    }

    public function events(): Builder
    {
        return $this->connection()->table(self::eventsTable());
    }

    public static function visitsTable(): string
    {
        return (string) config('first-party-analytics.database.visits_table', 'analytics_visits');
    }

    public static function eventsTable(): string
    {
        return (string) config('first-party-analytics.database.events_table', 'analytics_events');
    }
}
