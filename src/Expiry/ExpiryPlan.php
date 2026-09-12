<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use Bpmore\StatamicLifecycle\Notifications\Reminder;

/**
 * Exactly what a run would do, after every rail has had its say — the
 * thing `lifecycle:expire --dry-run` prints and a real run carries out.
 *
 * @param  list<Candidate>  $allowed  happen now
 * @param  list<Reminder>  $notices  tell the owner now; act after the notice period
 * @param  list<Waiting>  $waiting  noticed, not long enough ago
 * @param  list<Candidate>  $deferred  held by the daily cap until a later day
 * @param  list<Refusal>  $refused  the never-touch list and the navigation check
 * @param  list<Trip>  $trips  the circuit breaker, per collection
 * @param  list<Candidate>  $unowned  nobody to notify, so nothing can happen
 * @param  int  $expired  entries in an opted-in collection that are expired at all
 */
final readonly class ExpiryPlan
{
    public function __construct(
        public array $allowed,
        public array $notices,
        public array $waiting,
        public array $deferred,
        public array $refused,
        public array $trips,
        public array $unowned,
        public int $expired,
    ) {}

    public function isEmpty(): bool
    {
        return $this->allowed === [] && $this->notices === [] && $this->waiting === []
            && $this->deferred === [] && $this->refused === [] && $this->trips === [] && $this->unowned === [];
    }

    /** Would a real run change anything on the live site? */
    public function changesAnything(): bool
    {
        return $this->allowed !== [];
    }
}
