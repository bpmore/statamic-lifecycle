<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Exemptions;

/**
 * What an exemption change did, or on a dry run would have done.
 *
 * @param  bool  $exempt  true for exempted, false for exemptions cleared
 * @param  list<string>  $changed  entries written (or that would be)
 * @param  list<string>  $unchanged  entries already in that state
 */
final readonly class ExemptionChange
{
    public function __construct(
        public bool $exempt,
        public array $changed,
        public array $unchanged,
        public bool $dryRun,
    ) {}
}
