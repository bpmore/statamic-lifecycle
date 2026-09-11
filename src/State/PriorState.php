<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

use DateTimeImmutable;

/**
 * What the tables already know about an entry: the newest attestation, and
 * whether the baseline pass gave it a date.
 *
 * The baseline date is kept until the first real review. Changing an interval
 * must not collapse the spread lifecycle:baseline created — that spread is
 * the whole answer to "day one on a 40,000-page site".
 */
final readonly class PriorState
{
    public function __construct(
        public ?DateTimeImmutable $lastReviewedAt = null,
        public ?string $lastReviewedBy = null,
        public bool $baselined = false,
        public ?DateTimeImmutable $baselineNextReviewAt = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
