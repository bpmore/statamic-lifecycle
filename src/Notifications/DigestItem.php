<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use DateTimeImmutable;

/** One page in a digest: what to call it, where to go, when it is due, and why it is listed. */
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
    ) {}
}
