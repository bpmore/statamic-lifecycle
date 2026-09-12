<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use DateTimeImmutable;

/**
 * What the planner needs to know about one entry: when it is due, who to
 * tell, and who to tell when the owner has had long enough.
 */
final readonly class Reminderable
{
    /**
     * @param  ?string  $escalateTo  the collection's escalation contact, if any
     * @param  ?string  $lastResort  the site's owner of last resort, if any
     */
    public function __construct(
        public string $entryId,
        public DateTimeImmutable $dueAt,
        public string $recipient,
        public ?string $escalateTo = null,
        public ?string $lastResort = null,
    ) {}
}
