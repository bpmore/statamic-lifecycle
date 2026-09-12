<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/**
 * What a real run did.
 *
 * @param  list<Candidate>  $applied
 * @param  list<Refusal>  $failed  a candidate that could not be done, and why
 * @param  int  $noticesSent
 * @param  ?string  $noticesHeld  why notices were not sent, when they were not
 * @param  int  $alerted  breaker trips an admin was told about
 */
final readonly class ExpiryOutcome
{
    public function __construct(
        public array $applied,
        public array $failed,
        public int $noticesSent,
        public ?string $noticesHeld,
        public int $alerted,
    ) {}
}
