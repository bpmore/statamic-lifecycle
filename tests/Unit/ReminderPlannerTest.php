<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Notifications\Reminder;
use Bpmore\StatamicLifecycle\Notifications\Reminderable;
use Bpmore\StatamicLifecycle\Notifications\ReminderPlanner;
use Bpmore\StatamicLifecycle\Notifications\ReminderType;
use Bpmore\StatamicLifecycle\Notifications\Schedule;
use Bpmore\StatamicLifecycle\Notifications\SentReminder;

// No Statamic here. Dates, a schedule, and what has been sent.

function due(string $date, string $id = 'page', string $to = 'alice'): Reminderable
{
    return new Reminderable($id, new DateTimeImmutable($date), $to);
}

function sent(string $type, ?int $daysBefore, string $dueAt, string $sentAt, string $id = 'page'): SentReminder
{
    return new SentReminder($id, ReminderType::from($type), $daysBefore, new DateTimeImmutable($dueAt), new DateTimeImmutable($sentAt));
}

/** @return list<string> "type:days" per reminder, for terse assertions */
function owed(array $entries, array $sent, string $today, ?Schedule $schedule = null): array
{
    return array_map(
        fn (Reminder $r): string => $r->entryId.' '.$r->type->value.($r->daysBefore === null ? '' : ':'.$r->daysBefore).' → '.$r->recipient,
        (new ReminderPlanner($schedule ?? new Schedule))->plan($entries, $sent, new DateTimeImmutable($today)),
    );
}

describe('the schedule', function () {
    it('is 30 / 7 / 0 and then weekly by default', function () {
        $schedule = Schedule::fromConfig([]);

        expect($schedule->daysBefore)->toBe([30, 7])
            ->and($schedule->onDueDay)->toBeTrue()
            ->and($schedule->overdueEvery)->toBe(7)
            ->and($schedule->horizonDays())->toBe(30);
    });

    it('takes whatever days the site writes, in any order, and knows the day itself from 0', function () {
        $schedule = Schedule::fromConfig(['schedule' => [7, 60, '14', 7], 'overdue_every' => 0]);

        expect($schedule->daysBefore)->toBe([60, 14, 7])
            ->and($schedule->onDueDay)->toBeFalse()
            ->and($schedule->overdueEvery)->toBe(0);
    });

    it('picks the latest stage to have begun', function () {
        $schedule = new Schedule([30, 7, 0]);

        expect($schedule->stageFor(45))->toBeNull()
            ->and($schedule->stageFor(30))->toBe(30)
            ->and($schedule->stageFor(20))->toBe(30)
            ->and($schedule->stageFor(7))->toBe(7)
            ->and($schedule->stageFor(1))->toBe(7);
    });

    it('refuses nonsense', function () {
        expect(fn () => new Schedule(['soon']))->toThrow(InvalidArgumentException::class);
        expect(fn () => new Schedule([30], overdueEvery: -1))->toThrow(InvalidArgumentException::class);
    });
});

describe('ahead of the date', function () {
    it('owes nothing before the first stage', function () {
        expect(owed([due('2026-12-01')], [], '2026-10-01'))->toBe([]);
    });

    it('owes the 30-day reminder from 30 days out, once', function () {
        expect(owed([due('2026-12-01')], [], '2026-11-01'))->toBe(['page due_soon:30 → alice'])
            ->and(owed([due('2026-12-01')], [], '2026-11-10'))->toBe(['page due_soon:30 → alice'])
            ->and(owed([due('2026-12-01')], [sent('due_soon', 30, '2026-12-01', '2026-11-01')], '2026-11-10'))->toBe([]);
    });

    it('owes the 7-day reminder from 7 days out, whatever went before', function () {
        $sent30 = sent('due_soon', 30, '2026-12-01', '2026-11-01');

        expect(owed([due('2026-12-01')], [$sent30], '2026-11-24'))->toBe(['page due_soon:7 → alice'])
            ->and(owed([due('2026-12-01')], [$sent30, sent('due_soon', 7, '2026-12-01', '2026-11-24')], '2026-11-27'))->toBe([]);
    });

    it('does not send a stage that was missed, only the latest one', function () {
        // Baselined with five days to go: the 7-day reminder, not the 30-day
        // one twenty-five days late.
        expect(owed([due('2026-12-01')], [], '2026-11-26'))->toBe(['page due_soon:7 → alice']);
    });

    it('owes the day-of reminder on the day, once, and not when the schedule has no 0', function () {
        expect(owed([due('2026-12-01')], [], '2026-12-01'))->toBe(['page due:0 → alice'])
            ->and(owed([due('2026-12-01')], [sent('due', 0, '2026-12-01', '2026-12-01 09:00')], '2026-12-01 17:00'))->toBe([])
            ->and(owed([due('2026-12-01')], [], '2026-12-01', new Schedule([30, 7])))->toBe([]);
    });
});

describe('once overdue', function () {
    it('owes an overdue reminder the day after, at once', function () {
        expect(owed([due('2026-12-01')], [sent('due', 0, '2026-12-01', '2026-12-01')], '2026-12-02'))->toBe(['page overdue → alice']);
    });

    it('then every so many days, not before', function () {
        $first = sent('overdue', null, '2026-12-01', '2026-12-02');

        expect(owed([due('2026-12-01')], [$first], '2026-12-05'))->toBe([])
            ->and(owed([due('2026-12-01')], [$first], '2026-12-09'))->toBe(['page overdue → alice'])
            ->and(owed([due('2026-12-01')], [$first, sent('overdue', null, '2026-12-01', '2026-12-09')], '2026-12-15'))->toBe([])
            ->and(owed([due('2026-12-01')], [$first, sent('overdue', null, '2026-12-01', '2026-12-09')], '2026-12-16'))->toBe(['page overdue → alice']);
    });

    it('or only once, when the site says so', function () {
        $once = new Schedule([30, 7, 0], overdueEvery: 0);
        $first = sent('overdue', null, '2026-12-01', '2026-12-02');

        expect(owed([due('2026-12-01')], [$first], '2027-03-01', $once))->toBe([]);
    });

    it('counts the cadence by day, not by the hour it was sent', function () {
        $first = sent('overdue', null, '2026-12-01', '2026-12-02 23:59');

        expect(owed([due('2026-12-01')], [$first], '2026-12-09 00:01'))->toBe(['page overdue → alice']);
    });
});

describe('cycles', function () {
    it('starts over when the review date moves', function () {
        // Everything for the old date was sent. The page was reviewed; its
        // date is now next year. Nothing from the old cycle counts.
        $old = [
            sent('due_soon', 30, '2026-12-01', '2026-11-01'),
            sent('due_soon', 7, '2026-12-01', '2026-11-24'),
            sent('due', 0, '2026-12-01', '2026-12-01'),
            sent('overdue', null, '2026-12-01', '2026-12-02'),
        ];

        expect(owed([due('2027-12-01')], $old, '2027-11-05'))->toBe(['page due_soon:30 → alice'])
            ->and(owed([due('2027-12-01')], $old, '2027-12-02'))->toBe(['page overdue → alice']);
    });

    it('keeps entries apart', function () {
        $entries = [due('2026-12-01', 'a', 'alice'), due('2026-12-01', 'b', 'bob'), due('2027-06-01', 'c', 'alice')];
        $sent = [sent('due_soon', 30, '2026-12-01', '2026-11-01', 'a')];

        expect(owed($entries, $sent, '2026-11-10'))->toBe(['b due_soon:30 → bob']);
    });

    it('sends nothing to nobody, and nothing for nothing', function () {
        expect(owed([], [], '2026-11-10'))->toBe([]);
    });
});
