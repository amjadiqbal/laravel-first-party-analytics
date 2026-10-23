<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics;

use AmjadIqbal\FirstPartyAnalytics\Jobs\RecordAnalytics;
use Closure;
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

    /** @var array<string, Closure(): void> */
    private array $afterwards = [];

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

    /**
     * Runs a small in-process step (saving visit state to the cache) with the next flush, so it never
     * slows the response either. A later call with the same key replaces an earlier one.
     *
     * @param  Closure(): mixed  $callback
     */
    public function afterwards(string $key, Closure $callback): void
    {
        $this->afterwards[$key] = $callback;

        if (! $this->buffers()) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $afterwards = $this->afterwards;
        $this->afterwards = [];

        foreach ($afterwards as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                report($e);
            }
        }

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
