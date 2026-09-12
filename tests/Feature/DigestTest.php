<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Notification;
use Bpmore\StatamicLifecycle\Notifications\DigestMail;
use Bpmore\StatamicLifecycle\Notifications\Notifier;
use Bpmore\StatamicLifecycle\Notifications\Reminder;
use Bpmore\StatamicLifecycle\Notifications\Reminders;
use Bpmore\StatamicLifecycle\Notifications\ReminderType;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 07:00:00');
    Collection::make('pages')->title('Pages')->save();
    Collection::make('news')->title('News')->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
    User::make()->id('bob')->email('bob@example.edu')->save();
    User::make()->id('no-email')->save();
});

afterEach(fn () => Carbon::setTestNow());

function pageOwnedBy(string $id, string $owner, string $due, string $collection = 'pages', string $status = 'fresh'): void
{
    Entry::make()->id($id)->collection($collection)->slug($id)->published(true)->data(['title' => 'Page '.$id])->save();
    EntryState::query()->insert([
        'entry_id' => $id, 'site' => 'default', 'collection' => $collection,
        'owner_id' => $owner, 'owner_type' => 'user', 'owner_source' => 'assigned',
        'interval_days' => 365, 'next_review_at' => $due, 'status' => $status,
        'baselined' => false, 'computed_at' => '2026-09-11 00:00:00',
    ]);
}

function reminder(string $id, string $to, string $type = 'due_soon', ?int $days = 7, string $due = '2026-09-15'): Reminder
{
    return new Reminder($id, ReminderType::from($type), $days, new DateTimeImmutable($due), $to);
}

describe('one email per person', function () {
    it('sends one digest to an owner of three hundred pages, never three hundred emails', function () {
        Mail::fake();

        for ($i = 0; $i < 300; $i++) {
            pageOwnedBy("p-{$i}", 'alice', '2026-09-01', 'pages', 'overdue');
        }

        $owed = app(Reminders::class)->owed(new DateTimeImmutable('2026-09-11'));
        expect($owed)->toHaveCount(300);

        $digests = app(Notifier::class)->digests($owed);
        $result = app(Notifier::class)->send($digests, new DateTimeImmutable('2026-09-11 07:00'));

        Mail::assertSentCount(1);
        Mail::assertSent(DigestMail::class, fn (DigestMail $mail): bool => $mail->hasTo('alice@example.edu') && $mail->digest->count() === 300);

        expect($result)->toBe(['sent' => 1, 'reminders' => 300, 'failed' => []])
            ->and(Notification::query()->count())->toBe(300)
            ->and(Notification::query()->where('recipient', 'alice')->distinct('sent_at')->count('sent_at'))->toBe(1);
    });

    it('sends each person their own, and nothing to anyone with nothing', function () {
        Mail::fake();
        pageOwnedBy('a', 'alice', '2026-09-15');
        pageOwnedBy('b', 'bob', '2026-09-11');
        pageOwnedBy('c', 'bob', '2026-09-01', 'news', 'overdue');

        $digests = app(Notifier::class)->digests(app(Reminders::class)->owed(new DateTimeImmutable('2026-09-11')));
        app(Notifier::class)->send($digests, new DateTimeImmutable('2026-09-11 07:00'));

        Mail::assertSentCount(2);
        Mail::assertSent(DigestMail::class, fn (DigestMail $m): bool => $m->hasTo('alice@example.edu') && $m->digest->count() === 1);
        Mail::assertSent(DigestMail::class, fn (DigestMail $m): bool => $m->hasTo('bob@example.edu') && $m->digest->count() === 2);
    });

    it('records what went out only once it has gone, so tomorrow owes nothing it already said', function () {
        Mail::fake();
        pageOwnedBy('a', 'alice', '2026-09-15');

        $this->artisan('lifecycle:notify')->expectsOutputToContain('Sent 1 digest covering 1 reminder.')->assertExitCode(0);

        $row = Notification::query()->first();

        expect($row->entry_id)->toBe('a')
            ->and($row->type)->toBe(ReminderType::DueSoon)
            ->and($row->days_before)->toBe(7)
            ->and($row->due_at->format('Y-m-d'))->toBe('2026-09-15')
            ->and($row->recipient)->toBe('alice')
            ->and($row->sent_at->format('Y-m-d H:i'))->toBe('2026-09-11 07:00');

        Mail::fake();
        $this->artisan('lifecycle:notify')->expectsOutputToContain('0 reminders are owed today.')->assertExitCode(0);
        Mail::assertNothingSent();
    });
});

describe('what the email says', function () {
    it('lists overdue first, then due today, then due soon, with a way in', function () {
        pageOwnedBy('late', 'alice', '2026-09-01', 'news', 'overdue');
        pageOwnedBy('today', 'alice', '2026-09-11');
        pageOwnedBy('soon', 'alice', '2026-09-15');

        $digest = app(Notifier::class)->digests(app(Reminders::class)->owed(new DateTimeImmutable('2026-09-11')))[0];

        expect($digest->subject())->toBe('3 pages you own need a review')
            ->and(array_map(fn ($i) => $i->entryId, $digest->items))->toBe(['late', 'today', 'soon']);

        $html = (new DigestMail($digest))->render();

        expect($html)->toContain('Hello Alice Ahmed')
            ->and($html)->toContain('Overdue (1)')
            ->and($html)->toContain('Due today (1)')
            ->and($html)->toContain('Due soon (1)')
            ->and($html)->toContain('Page late')
            ->and($html)->toContain('News')
            ->and($html)->toContain('was due 1 Sep 2026')
            ->and($html)->toContain('due 15 Sep 2026')
            ->and($html)->toContain('Mark reviewed')
            ->and(substr_count($html, 'Review it'))->toBe(3)
            ->and($html)->toContain(Entry::find('late')->editUrl());

        // Order in the email follows the order in the digest.
        expect(strpos($html, 'Page late'))->toBeLessThan(strpos($html, 'Page today'))
            ->and(strpos($html, 'Page today'))->toBeLessThan(strpos($html, 'Page soon'));
    });

    it('says overdue in the subject when that is all there is', function () {
        pageOwnedBy('late', 'alice', '2026-09-01', 'pages', 'overdue');
        pageOwnedBy('later', 'alice', '2026-08-01', 'pages', 'expired');

        $digest = app(Notifier::class)->digests(app(Reminders::class)->owed(new DateTimeImmutable('2026-09-11')))[0];

        expect($digest->subject())->toBe('2 pages you own are overdue for review');
    });

    it('speaks in the singular for one page', function () {
        pageOwnedBy('soon', 'alice', '2026-09-15');
        $digest = app(Notifier::class)->digests(app(Reminders::class)->owed(new DateTimeImmutable('2026-09-11')))[0];

        expect($digest->subject())->toBe('A page you own needs a review')
            ->and((new DigestMail($digest))->render())->toContain('owner of a page that needs a person');
    });
});

describe('who cannot be reached', function () {
    it('skips a person with no email, or who has gone, says so, and records nothing for them', function () {
        Mail::fake();
        pageOwnedBy('a', 'no-email', '2026-09-15');
        pageOwnedBy('b', 'someone-who-left', '2026-09-15');
        pageOwnedBy('c', 'alice', '2026-09-15');

        $this->artisan('lifecycle:notify')
            ->expectsOutputToContain('[no-email] cannot be reminded: no email address.')
            ->expectsOutputToContain('[someone-who-left] cannot be reminded: no longer a user.')
            ->expectsOutputToContain('Sent 1 digest covering 1 reminder.')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
        expect(Notification::query()->pluck('entry_id')->all())->toBe(['c']);
    });

    it('leaves out a page that has gone since it was computed', function () {
        Mail::fake();
        pageOwnedBy('gone', 'alice', '2026-09-15');
        pageOwnedBy('here', 'alice', '2026-09-15');
        Entry::find('gone')->deleteQuietly();

        $digests = app(Notifier::class)->digests(app(Reminders::class)->owed(new DateTimeImmutable('2026-09-11')));

        expect($digests)->toHaveCount(1)
            ->and($digests[0]->count())->toBe(1)
            ->and($digests[0]->items[0]->entryId)->toBe('here');
    });
});

it('is scheduled daily, after the recompute, at an hour people read email', function () {
    // The hour is `notifications.send_at`, read when the schedule is built at
    // boot — so this sees the default. Recompute runs at midnight.
    $events = collect(app(LaravelSchedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'lifecycle:notify'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 7 * * *')
        ->and($events->first()->filtersPass(app()))->toBeTrue();
});
