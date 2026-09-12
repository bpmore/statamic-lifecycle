<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use DateTimeImmutable;

/**
 * One reminder to send: this entry, at this stage of this cycle, to this
 * person. For an escalation the person is not the owner, so the owner is
 * carried too — the email names them. For a notice, the action it warns of
 * and the day it may happen from are carried, so the email can say both.
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
        public ?ActionType $action = null,
        public ?DateTimeImmutable $actionableFrom = null,
    ) {}
}
