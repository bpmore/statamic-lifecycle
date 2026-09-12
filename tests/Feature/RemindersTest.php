<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Notification;
use Bpmore\StatamicLifecycle\Notifications\Reminder;
use Bpmore\StatamicLifecycle\Notifications\Reminders;
use Bpmore\StatamicLifecycle\Notifications\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    Collection::make('pages')->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
    User::make()->id('bob')->email('bob@example.edu')->save();
});

afterEach(fn () => Carbon::setTestNow());

/** A state row with just what the reminders read. */
function stateRow(string $id, ?string $due, ?string $owner = 'alice', string $status = 'fresh', string $ownerType = 'user'): void
{
    EntryState::query()->insert([
        'entry_id' => $id, 'site' => 'default', 'collection' => 'pages',
        'owner_id' => $owner, 'owner_type' => $owner === null ? null : $ownerType, 'owner_source' => $owner === null ? null : 'assigned',
        'interval_days' => 365, 'next_review_at' => $due, 'status' => $status,
        'baselined' => false, 'computed_at' => '2026-09-11 00:00:00',
    ]);
}

function sentRow(string $id, string $type, ?int $daysBefore, string $dueAt, string $sentAt, string $to = 'alice'): void
{
    Notification::query()->insert([
        'entry_id' => $id, 'type' => $type, 'days_before' => $daysBefore, 'due_at' => $dueAt, 'recipient' => $to, 'sent_at' => $sentAt,
    ]);
}

function owedToday(): array
{
    return array_map(
        fn (Reminder $r): string => $r->entryId.' '.$r->type->value.($r->daysBefore === null ? '' : ':'.$r->daysBefore).' → '.$r->recipient,
        app(Reminders::class)->owed(new DateTimeImmutable('2026-09-11')),
    );
}

it('reads the schedule from config', function () {
    config(['lifecycle.notifications' => ['schedule' => [14, 0], 'overdue_every' => 3]]);

    $schedule = app(Schedule::class);

    expect($schedule->daysBefore)->toBe([14])
        ->and($schedule->onDueDay)->toBeTrue()
        ->and($schedule->overdueEvery)->toBe(3);
});

it('owes reminders for what is inside the horizon or past it, to the owner', function () {
    stateRow('far', '2027-03-01');            // 171 days: nothing yet
    stateRow('soon', '2026-10-01');           // 20 days: the 30-day stage
    stateRow('closer', '2026-09-15', 'bob');  // 4 days: the 7-day stage
    stateRow('today', '2026-09-11');          // due today
    stateRow('late', '2026-09-01', 'bob', 'overdue');
    stateRow('very-late', '2026-06-01', 'alice', 'expired');

    expect(owedToday())->toBe([
        'very-late overdue → alice',
        'late overdue → bob',
        'today due:0 → alice',
        'closer due_soon:7 → bob',
        'soon due_soon:30 → alice',
    ]);
});

it('leaves out what has no date, no owner, or is exempt', function () {
    stateRow('exempt', null, 'alice', 'exempt');
    stateRow('nobody', '2026-09-15', null);
    stateRow('group-owned', '2026-09-15', 'editors', 'fresh', 'group');   // groups are for later
    stateRow('fine', '2026-09-15');

    expect(owedToday())->toBe(['fine due_soon:7 → alice']);
});

it('does not repeat what has been sent for the same review date', function () {
    stateRow('a', '2026-09-15');
    stateRow('b', '2026-09-15');
    stateRow('c', '2026-09-01', 'alice', 'overdue');
    stateRow('d', '2026-09-01', 'alice', 'overdue');
    sentRow('a', 'due_soon', 7, '2026-09-15 00:00:00', '2026-09-09 08:00:00');
    sentRow('c', 'overdue', null, '2026-09-01 00:00:00', '2026-09-09 08:00:00');   // two days ago: not yet
    sentRow('d', 'overdue', null, '2026-09-01 00:00:00', '2026-09-02 08:00:00');   // nine days ago: again

    expect(owedToday())->toBe(['d overdue → alice', 'b due_soon:7 → alice']);
});

it('starts over once a review moves the date', function () {
    stateRow('a', '2026-09-15');
    // Everything for last year's date went out. It is a new cycle.
    sentRow('a', 'due_soon', 7, '2025-09-15 00:00:00', '2025-09-09 08:00:00');
    sentRow('a', 'overdue', null, '2025-09-15 00:00:00', '2025-09-20 08:00:00');

    expect(owedToday())->toBe(['a due_soon:7 → alice']);
});

it('follows the site\'s schedule', function () {
    config(['lifecycle.notifications' => ['schedule' => [60, 14], 'overdue_every' => 0]]);
    stateRow('sixty', '2026-11-05');   // 55 days
    stateRow('today', '2026-09-11');   // no 0 in the schedule: nothing on the day
    stateRow('late', '2026-09-01', 'alice', 'overdue');
    sentRow('late', 'overdue', null, '2026-09-01 00:00:00', '2026-09-02 08:00:00');   // once only

    expect(owedToday())->toBe(['sixty due_soon:60 → alice']);
});

describe('the command', function () {
    it('refuses to run before the tables exist', function () {
        config([
            'lifecycle.connection' => 'lifecycle_absent',
            'database.connections.lifecycle_absent' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/absent-'.uniqid().'.sqlite', 'prefix' => ''],
        ]);

        $this->artisan('lifecycle:notify', ['--dry-run' => true])->expectsOutputToContain('lifecycle:install')->assertExitCode(1);
    });

    it('shows the schedule and who would hear about what, per person, and sends nothing', function () {
        stateRow('soon', '2026-10-01');
        stateRow('closer', '2026-09-15', 'bob');
        stateRow('today', '2026-09-11');
        stateRow('late', '2026-09-01', 'bob', 'overdue');

        $this->artisan('lifecycle:notify', ['--dry-run' => true])
            ->expectsOutputToContain('Schedule: 30 / 7 / 0 days, then every 7 days overdue. 4 reminders are owed today.')
            // One phrase per line: the name and the counts share a line, and
            // expectsOutputToContain satisfies one expectation per line.
            ->expectsOutputToContain('1 due soon, 1 due today')
            ->expectsOutputToContain('1 due soon, 1 overdue')
            ->expectsOutputToContain('Dry run: nothing was sent.')
            ->assertExitCode(0);

        expect(Notification::query()->count())->toBe(0);
    });

    it('says so when nothing is owed', function () {
        $this->artisan('lifecycle:notify', ['--dry-run' => true])
            ->expectsOutputToContain('0 reminders are owed today.')
            ->assertExitCode(0);
    });

    it('does not pretend to send until sending exists', function () {
        stateRow('today', '2026-09-11');

        $this->artisan('lifecycle:notify')
            ->expectsOutputToContain('Sending is not built yet: nothing was sent.')
            ->assertExitCode(1);

        expect(Notification::query()->count())->toBe(0);
    });
});
