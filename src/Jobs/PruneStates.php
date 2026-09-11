<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Jobs;

use Bpmore\StatamicLifecycle\StateRecomputer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;

/**
 * Removes the rows that no longer have a published entry behind them.
 *
 * The last job in every recompute batch. It works out for itself what is
 * live when it runs, so a chunk that finishes after it costs at most one
 * stale row until tomorrow night.
 */
class PruneStates implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(
        public readonly ?string $site = null,
        public readonly ?string $collection = null,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }

    public function handle(StateRecomputer $recomputer): void
    {
        $recomputer->prune($this->site, $this->collection);
    }
}
