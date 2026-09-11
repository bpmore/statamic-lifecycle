<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

use DateTimeImmutable;

/** What the planner needs to know about one entry: when it was last touched, and how often it wants looking at. */
final readonly class BaselineCandidate
{
    public function __construct(
        public string $entryId,
        public DateTimeImmutable $lastModifiedAt,
        public int $intervalDays,
    ) {}
}
