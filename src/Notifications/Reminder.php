<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use DateTimeImmutable;

/**
 * One reminder to send: this entry, at this stage of this cycle, to this
 * person. For an escalation the person is not the owner, so the owner is
 * carried too — the email names them.
 */
final readonly class Reminder
{
    public function __construct(
        public string $entryId,
        public ReminderType $type,
        public ?int $daysBefore,
        public DateTimeImmutable $dueAt,
        public string $recipient,
        public ?string $ownerId = null,
    ) {}
}
