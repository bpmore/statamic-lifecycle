<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use DateTimeImmutable;

/** A notice that went out: this page, this action, this cycle, sent then. */
final readonly class Notice
{
    public function __construct(
        public string $entryId,
        public ActionType $action,
        public ?DateTimeImmutable $dueAt,
        public DateTimeImmutable $sentAt,
    ) {}
}
