<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Whole days between two moments, counted by PHP's own DateTime.
 *
 * `->diff()->days` on a Carbon date is not safe across the Carbon versions
 * this addon allows. Carbon 3.8 returns a CarbonInterval whose `days` is
 * false, which an (int) cast turns into 0: a twelve-month baseline horizon
 * read as zero days, and nothing was ever overdue. Both ends are made plain
 * DateTimeImmutable first, so diff() returns PHP's own interval, whose
 * `days` it always sets.
 *
 * Like `days`, the result is never negative. A caller that needs to know
 * which way round compares the two dates itself.
 */
final class Days
{
    public static function between(DateTimeInterface $from, DateTimeInterface $to): int
    {
        return (int) DateTimeImmutable::createFromInterface($from)
            ->diff(DateTimeImmutable::createFromInterface($to))
            ->days;
    }
}
