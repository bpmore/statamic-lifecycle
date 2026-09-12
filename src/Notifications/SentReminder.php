<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use DateTimeImmutable;

/** One row of lifecycle_notifications, as the planner and the notice period read it. */
final readonly class SentReminder
{
    public function __construct(
        public string $entryId,
        public ReminderType $type,
        public ?int $daysBefore,
        public ?DateTimeImmutable $dueAt,
        public DateTimeImmutable $sentAt,
        public string $recipient = '',
        public ?ActionType $action = null,
    ) {}
}
