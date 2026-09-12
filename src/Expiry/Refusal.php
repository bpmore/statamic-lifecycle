<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/** A candidate a rail would not let through, and why, in words that name the rule. */
final readonly class Refusal
{
    public function __construct(
        public Candidate $candidate,
        public string $reason,
    ) {}
}
