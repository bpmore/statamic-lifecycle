<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use DateTimeImmutable;

/** One row of lifecycle_notifications, as the planner reads it. */
final readonly class SentReminder
{
    public function __construct(
        public string $entryId,
        public ReminderType $type,
        public ?int $daysBefore,
        public ?DateTimeImmutable $dueAt,
        public DateTimeImmutable $sentAt,
    ) {}
}
