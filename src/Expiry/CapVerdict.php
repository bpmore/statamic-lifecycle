<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/**
 * What the daily cap let through today and what it put off until tomorrow.
 *
 * Deferred, not refused: a page the cap holds back is still due for its
 * action, and gets it on a later day. Only the pace is limited.
 *
 * @param  list<Candidate>  $allowed
 * @param  list<Candidate>  $deferred
 */
final readonly class CapVerdict
{
    public function __construct(
        public array $allowed,
        public array $deferred,
        public int $cap,
        public int $usedToday,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->cap - $this->usedToday);
    }

    /** In words, or null when nothing was held back. */
    public function reason(): ?string
    {
        if ($this->deferred === []) {
            return null;
        }

        return sprintf(
            'Put off %s %s until tomorrow: %s a day is the cap, and %s %s already taken today.',
            number_format(count($this->deferred)),
            count($this->deferred) === 1 ? 'action' : 'actions',
            number_format($this->cap),
            number_format($this->usedToday),
            $this->usedToday === 1 ? 'was' : 'were',
        );
    }
}
