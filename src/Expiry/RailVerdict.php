<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/**
 * What a rail let through and what it kept out, each refusal with its
 * reason in words that name the rule.
 *
 * @param  list<Candidate>  $allowed
 * @param  list<Refusal>  $refused
 */
final readonly class RailVerdict
{
    public function __construct(
        public array $allowed,
        public array $refused,
    ) {}
}
