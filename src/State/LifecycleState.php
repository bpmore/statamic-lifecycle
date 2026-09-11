<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

use DateTimeImmutable;

/**
 * The computed state of one entry: one row of lifecycle_states, plus where
 * each piece came from so a screen can explain itself.
 */
final readonly class LifecycleState
{
    public function __construct(
        public string $entryId,
        public string $site,
        public string $collection,
        public ?string $ownerId,
        public ?string $ownerType,
        public ?OwnerSource $ownerSource,
        public ?int $intervalDays,
        public ?IntervalSource $intervalSource,
        public ?DateTimeImmutable $lastReviewedAt,
        public ?string $lastReviewedBy,
        public ?DateTimeImmutable $nextReviewAt,
        public ?NextReviewSource $nextReviewSource,
        public Status $status,
        public bool $baselined,
        public DateTimeImmutable $computedAt,
    ) {}
}
