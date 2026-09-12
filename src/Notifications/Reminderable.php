<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use DateTimeImmutable;

/** What the planner needs to know about one entry: when it is due, and who to tell. */
final readonly class Reminderable
{
    public function __construct(
        public string $entryId,
        public DateTimeImmutable $dueAt,
        public string $recipient,
    ) {}
}
