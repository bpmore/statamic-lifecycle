<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Jobs;

use Bpmore\StatamicLifecycle\StateRecomputer;
use DateTimeImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;

/**
 * Recomputes one chunk of entries.
 *
 * Chunked rather than one job per entry because 40,000 rows of queue
 * overhead for work that takes a millisecond each is absurd; chunked rather
 * than one job for everything because a chunk is the unit that can be
 * retried and watched.
 */
class RecomputeStates implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, SerializesModels;

    /** @param  list<string>  $entryIds */
    public function __construct(public readonly array $entryIds) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }

    public function handle(StateRecomputer $recomputer): void
    {
        $recomputer->recompute($this->entryIds, new DateTimeImmutable);
    }
}
