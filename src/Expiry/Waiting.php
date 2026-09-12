<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use DateTimeImmutable;

/** A candidate whose notice has gone but whose period has not yet passed. */
final readonly class Waiting
{
    public function __construct(
        public Candidate $candidate,
        public DateTimeImmutable $noticedOn,
        public DateTimeImmutable $actionableFrom,
    ) {}
}
