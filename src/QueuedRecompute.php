<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle;

use Illuminate\Bus\Batch;

/**
 * What a queued recompute turned out to be. The batch knows how many jobs it
 * holds and nothing about how many entries they cover, and "queued 160 jobs"
 * is not the number anybody wants.
 */
final readonly class QueuedRecompute
{
    public function __construct(
        public Batch $batch,
        public int $entries,
        public int $jobs,
    ) {}
}
