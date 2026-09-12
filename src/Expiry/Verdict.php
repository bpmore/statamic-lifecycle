<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/**
 * What a rail let through and what it refused.
 *
 * @param  list<Candidate>  $allowed
 * @param  list<Candidate>  $refused
 * @param  list<Trip>  $trips  why, per collection
 */
final readonly class Verdict
{
    public function __construct(
        public array $allowed,
        public array $refused,
        public array $trips,
    ) {}

    public function tripped(): bool
    {
        return $this->trips !== [];
    }
}
