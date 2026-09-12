<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/**
 * What the never-touch list let through and what it kept out.
 *
 * @param  list<Candidate>  $allowed
 * @param  list<Refusal>  $refused
 */
final readonly class TouchVerdict
{
    public function __construct(
        public array $allowed,
        public array $refused,
    ) {}
}
