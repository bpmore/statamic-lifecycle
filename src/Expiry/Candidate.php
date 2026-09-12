<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/** One thing a run proposes to do to one page. Nothing happens to it until every rail has agreed. */
final readonly class Candidate
{
    public function __construct(
        public string $entryId,
        public string $site,
        public string $collection,
        public ActionType $action,
    ) {}
}
