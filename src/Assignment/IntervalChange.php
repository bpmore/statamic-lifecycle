<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment;

/**
 * What setting an interval did, or on a dry run would have done.
 *
 * @param  ?string  $interval  the interval name written, or null for the default
 * @param  list<string>  $changed  entries written (or that would be)
 * @param  list<string>  $unchanged  entries that already had it
 */
final readonly class IntervalChange
{
    public function __construct(
        public ?string $interval,
        public array $changed,
        public array $unchanged,
        public bool $dryRun,
    ) {}
}
