<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use InvalidArgumentException;

/**
 * When reminders go: how many days ahead of the review date, whether on
 * the day itself, and how often once it has passed.
 *
 * Spec §5's default is 30 / 7 / 0 and then overdue. Configured as a list of
 * days, 0 meaning the day itself, so a site that wants 60 / 30 / 7 / 0 or
 * just 14 writes exactly that.
 */
final readonly class Schedule
{
    /** @var list<int> days before the review date, largest first, none zero */
    public array $daysBefore;

    /** Whether a reminder goes on the review date itself. */
    public bool $onDueDay;

    /**
     * @param  list<int>  $stages  days before the review date; 0 for the day itself
     * @param  int  $overdueEvery  days between reminders once overdue; 0 to remind once and stop
     * @param  int  $escalateAfter  days overdue before the collection's escalation contact hears
     * @param  int  $lastResortAfter  days overdue before the site's owner of last resort hears
     */
    public function __construct(
        array $stages = [30, 7, 0],
        public int $overdueEvery = 7,
        public int $escalateAfter = 14,
        public int $lastResortAfter = 30,
    ) {
        $days = [];

        foreach ($stages as $stage) {
            if (! is_int($stage) && ! (is_string($stage) && ctype_digit($stage))) {
                throw new InvalidArgumentException('Every reminder stage must be a whole number of days; got '.var_export($stage, true).'.');
            }
            $days[] = (int) $stage;
        }

        if ($overdueEvery < 0) {
            throw new InvalidArgumentException('overdue_every cannot be negative.');
        }

        if ($escalateAfter < 1 || $lastResortAfter < 1) {
            throw new InvalidArgumentException('Escalation happens some days after the review date, at least one.');
        }

        $this->onDueDay = in_array(0, $days, true);
        $before = array_values(array_unique(array_filter($days, fn (int $d): bool => $d > 0)));
        rsort($before);
        $this->daysBefore = $before;
    }

    /**
     * @param  array<string, mixed>  $config  the `lifecycle.notifications` array
     * @param  array<string, mixed>  $escalation  the `lifecycle.escalation` array
     */
    public static function fromConfig(array $config, array $escalation = []): self
    {
        return new self(
            stages: array_values((array) ($config['schedule'] ?? [30, 7, 0])),
            overdueEvery: (int) ($config['overdue_every'] ?? 7),
            escalateAfter: (int) ($escalation['after_days'] ?? 14),
            lastResortAfter: (int) ($escalation['last_resort_after_days'] ?? 30),
        );
    }

    /** The furthest ahead any reminder looks: how far ahead to fetch. */
    public function horizonDays(): int
    {
        return $this->daysBefore[0] ?? 0;
    }

    /** The stage that has most recently begun for a date this many days away, or null when none has. */
    public function stageFor(int $daysUntil): ?int
    {
        $stage = null;

        foreach ($this->daysBefore as $days) {
            if ($days >= $daysUntil) {
                $stage = $days;   // ever smaller: the latest one to have begun
            }
        }

        return $stage;
    }
}
