<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment;

/**
 * What an assignment did, or on a dry run would have done.
 *
 * @param  list<string>  $assigned  entries whose owner was (or would be) written
 * @param  list<string>  $unchanged  entries that already had this owner
 */
final readonly class Assignment
{
    public function __construct(
        public string $ownerId,
        public array $assigned,
        public array $unchanged,
        public bool $dryRun,
    ) {}
}
