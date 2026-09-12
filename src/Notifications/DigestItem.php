<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use DateTimeImmutable;

/**
 * One page in a digest: what to call it, where to go, when it is due, why it
 * is listed — and, when it is listed because it was escalated, whose it is;
 * when it is listed as a notice, what will happen to it and from when.
 */
final readonly class DigestItem
{
    public function __construct(
        public string $entryId,
        public string $title,
        public ?string $editUrl,
        public string $collection,
        public DateTimeImmutable $dueAt,
        public ReminderType $type,
        public ?int $daysBefore,
        public ?string $ownerName = null,
        public ?ActionType $action = null,
        public ?DateTimeImmutable $actionableFrom = null,
    ) {}
}
