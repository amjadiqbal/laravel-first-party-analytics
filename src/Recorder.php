<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics;

use AmjadIqbal\FirstPartyAnalytics\Jobs\RecordAnalytics;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Collects what a request records and writes it in one go: after the response has been sent
 * ("after_response", flushed by the application's terminating callback), as one queued job ("queue"),
 * or at once ("sync", and always in console commands and queue jobs). A failing write is reported,
 * never thrown: analytics must not break a page.
 */
final class Recorder
{
    /** @var list<array<string, mixed>> */
    private array $pending = [];

    /** Overrides the console check (tests). */
    public ?bool $buffer = null;

    public function __construct(private readonly Application $app, private readonly Store $store) {}

    /**
     * @param  array<string, mixed>  $operation
     */
    public function push(array $operation): void
    {
        $this->pending[] = $operation;

        if (! $this->buffers()) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $operations = $this->pending;
        $this->pending = [];

        try {
            if (config('first-party-analytics.write') === 'queue') {
                RecordAnalytics::dispatch($operations)
                    ->onConnection(config('first-party-analytics.queue.connection'))
                    ->onQueue(config('first-party-analytics.queue.queue'));
            } else {
                $this->store->apply($operations);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    private function buffers(): bool
    {
        if ($this->buffer !== null) {
            return $this->buffer;
        }

        return in_array(config('first-party-analytics.write'), ['after_response', 'queue'], true)
            && ! $this->app->runningInConsole();
    }
}
