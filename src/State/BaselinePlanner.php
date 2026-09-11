<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The staggering algorithm: give every existing entry a first review date
 * spread across a horizon, oldest content first, so that day one on a
 * 40,000-page site is a plan rather than 40,000 overdue rows.
 *
 * Entries are grouped by window — the horizon, or the entry's own interval
 * where that is shorter, because a quarterly page's first review cannot
 * sensibly be eleven months out. Within a group, entries are laid out evenly
 * across (0, window] in order of last modification. Even, not capped: clamping
 * everything past day 90 to day 90 would pile a cluster there, which is the
 * thing this exists to prevent.
 *
 * Pure. The command decides which entries are in; this decides when.
 */
final class BaselinePlanner
{
    /**
     * @param  iterable<BaselineCandidate>  $candidates
     * @return array<string, DateTimeImmutable> entry ID => first review date, at the start of that day
     */
    public function plan(iterable $candidates, int $horizonDays, DateTimeImmutable $today): array
    {
        if ($horizonDays < 1) {
            throw new InvalidArgumentException("The horizon must be at least one day; got {$horizonDays}.");
        }

        $today = $today->setTime(0, 0);

        // Group by window, keeping each group in last-modified order. Ties
        // are broken by ID so two runs over the same site agree.
        $groups = [];

        foreach ($candidates as $candidate) {
            $window = max(1, min($horizonDays, $candidate->intervalDays));
            $groups[$window][] = $candidate;
        }

        $dates = [];

        foreach ($groups as $window => $group) {
            usort($group, static fn (BaselineCandidate $a, BaselineCandidate $b): int => [$a->lastModifiedAt, $a->entryId] <=> [$b->lastModifiedAt, $b->entryId]);

            $count = count($group);

            foreach ($group as $index => $candidate) {
                // Entry i of N lands on day 1 + floor(i × window / N): the
                // oldest tomorrow, the newest at the far end, the rest evenly
                // between, and never further out than the window.
                $offset = 1 + intdiv($index * $window, $count);

                $dates[$candidate->entryId] = $today->modify("+{$offset} days");
            }
        }

        return $dates;
    }
}
