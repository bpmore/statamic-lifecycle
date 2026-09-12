<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use DateTimeImmutable;

/**
 * One thing a run proposes to do to one page. Nothing happens to it until
 * every rail has agreed.
 *
 * @param  ?DateTimeImmutable  $dueAt  the review date the page is overdue against — the cycle a
 *                                     notice is counted in. Null for a restore.
 */
final readonly class Candidate
{
    public function __construct(
        public string $entryId,
        public string $site,
        public string $collection,
        public ActionType $action,
        public ?DateTimeImmutable $dueAt = null,
    ) {}
}
