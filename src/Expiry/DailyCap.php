<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use InvalidArgumentException;

/**
 * Rail 2 (spec §6): no more than so many automated actions a day, whatever
 * the breaker allowed. Twenty-five by default.
 *
 * The breaker guards against a run that is too big for one collection; the
 * cap guards against a day that is too big for the site. Together they
 * make a backlog of a thousand expired pages a month of small, watchable
 * steps rather than an afternoon nobody can undo.
 *
 * Pure. Takes candidates in the order the run wants them done and lets the
 * first ones through until the day is full; restores are never counted or
 * held, because a way back is not a risk.
 */
final readonly class DailyCap
{
    public function __construct(public int $perDay = 25)
    {
        if ($perDay < 0) {
            throw new InvalidArgumentException('The daily cap cannot be negative.');
        }
    }

    /** @param  array<string, mixed>  $config  the `lifecycle.expiry` array */
    public static function fromConfig(array $config): self
    {
        return new self(max(0, (int) ($config['daily_cap'] ?? 25)));
    }

    /**
     * @param  list<Candidate>  $candidates  in the order they should happen
     * @param  int  $usedToday  actions already taken today, restores excluded
     */
    public function check(array $candidates, int $usedToday): CapVerdict
    {
        $remaining = max(0, $this->perDay - $usedToday);
        $allowed = [];
        $deferred = [];

        foreach ($candidates as $candidate) {
            if ($candidate->action === ActionType::Restore) {
                $allowed[] = $candidate;

                continue;
            }

            if ($remaining > 0) {
                $allowed[] = $candidate;
                $remaining--;
            } else {
                $deferred[] = $candidate;
            }
        }

        return new CapVerdict($allowed, $deferred, $this->perDay, $usedToday);
    }
}
