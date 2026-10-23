<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics\Jobs;

use AmjadIqbal\FirstPartyAnalytics\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Writes one request's analytics (write mode "queue"). */
final class RecordAnalytics implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    /**
     * @param  list<array<string, mixed>>  $operations
     */
    public function __construct(public readonly array $operations) {}

    public function handle(Store $store): void
    {
        $store->apply($this->operations);
    }
}
