<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Notification;
use Bpmore\StatamicLifecycle\Notifications\DigestMail;
use Bpmore\StatamicLifecycle\Notifications\Notifier;
use Bpmore\StatamicLifecycle\Notifications\ReminderPreferences;
use Bpmore\StatamicLifecycle\Notifications\Reminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Preference;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 07:00:00');   // a Friday
    Collection::make('pages')->title('Pages')->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
    User::make()->id('bob')->email('bob@example.edu')->save();
});

afterEach(fn () => Carbon::setTestNow());

function duePage(string $id, string $owner, string $due = '2026-09-15'): void
{
    Entry::make()->id($id)->collection('pages')->slug($id)->published(true)->data(['title' => 'Page '.$id])->save();
    EntryState::query()->insert([
        'entry_id' => $id, 'site' => 'default', 'collection' => 'pages',
        'owner_id' => $owner, 'owner_type' => 'user', 'owner_source' => 'assigned',
        'interval_days' => 365, 'next_review_at' => $due, 'status' => 'due_soon',
        'baselined' => false, 'computed_at' => '2026-09-11 00:00:00',
    ]);
}

describe('the quiet window', function () {
    it('sends nothing on a weekend, keeps what is owed, and sends it on Monday', function () {
        Mail::fake();
        duePage('a', 'alice');

        Carbon::setTestNow('2026-09-12 07:00:00');   // Saturday
        $this->artisan('lifecycle:notify')
            ->expectsOutputToContain('1 reminder is owed today.')
            ->expectsOutputToContain('Quiet day: it is Saturday.')
            ->assertExitCode(0);
        Mail::assertNothingSent();
        expect(Notification::query()->count())->toBe(0);

        Carbon::setTestNow('2026-09-14 07:00:00');   // Monday
        $this->artisan('lifecycle:notify')->expectsOutputToContain('Sent 1 digest')->assertExitCode(0);
        Mail::assertSentCount(1);
    });

    it('respects a named date, and a site with no quiet days at all', function () {
        Mail::fake();
        duePage('a', 'alice');

        config(['lifecycle.notifications.quiet_dates' => ['2026-09-11']]);
        $this->artisan('lifecycle:notify')->expectsOutputToContain('Quiet day: 11 Sep 2026 is a quiet date.')->assertExitCode(0);
        Mail::assertNothingSent();

        config(['lifecycle.notifications.quiet_dates' => [], 'lifecycle.notifications.quiet_days' => []]);
        Carbon::setTestNow('2026-09-13 07:00:00');   // Sunday, but nothing is quiet here
        $this->artisan('lifecycle:notify')->expectsOutputToContain('Sent 1 digest')->assertExitCode(0);
    });
});

describe('the opt-out', function () {
    it('is a field on each person\'s own preferences screen', function () {
        // Statamic builds the tabs when the screen is asked for.
        Preference::boot();
        $tabs = Preference::tabs();

        expect($tabs->has('lifecycle'))->toBeTrue()
            ->and($tabs['lifecycle']['display'])->toBe('Lifecycle')
            ->and($tabs['lifecycle']['fields'])->toHaveKey(ReminderPreferences::HANDLE)
            ->and($tabs['lifecycle']['fields'][ReminderPreferences::HANDLE]['type'])->toBe('select')
            ->and($tabs['lifecycle']['fields'][ReminderPreferences::HANDLE]['default'])->toBe('daily')
            ->and(array_keys($tabs['lifecycle']['fields'][ReminderPreferences::HANDLE]['options']))->toBe(['daily', 'weekly', 'never']);
    });

    it('mutes email for somebody who asked, without forgetting anything', function () {
        Mail::fake();
        User::find('alice')->setPreference(ReminderPreferences::HANDLE, 'never')->save();
        duePage('a', 'alice');
        duePage('b', 'bob');

        $this->artisan('lifecycle:notify')
            ->expectsOutputToContain('held: has muted review reminders')
            ->expectsOutputToContain('Sent 1 digest covering 1 reminder.')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
        Mail::assertSent(DigestMail::class, fn (DigestMail $m): bool => $m->hasTo('bob@example.edu'));

        // Nothing recorded for alice: the reminder stays owed, and the state
        // row — what the dashboard shows — is untouched.
        expect(Notification::query()->where('recipient', 'alice')->count())->toBe(0)
            ->and(EntryState::query()->find('a')->status)->toBe('due_soon');

        // Unmute, and it is all still there to be sent.
        User::find('alice')->setPreference(ReminderPreferences::HANDLE, 'daily')->save();
        $this->artisan('lifecycle:notify')->expectsOutputToContain('Sent 1 digest')->assertExitCode(0);
        expect(Notification::query()->where('recipient', 'alice')->count())->toBe(1);
    });

    it('sends at most one a week to somebody who asked for that, with everything owed by then', function () {
        Mail::fake();
        User::find('alice')->setPreference(ReminderPreferences::HANDLE, 'weekly')->save();
        duePage('a', 'alice', '2026-09-15');

        // Friday: the first one goes.
        $this->artisan('lifecycle:notify')->expectsOutputToContain('Sent 1 digest')->assertExitCode(0);

        // Monday: a second page has come into range. Held — had one three days ago.
        duePage('b', 'alice', '2026-09-16');
        Carbon::setTestNow('2026-09-14 07:00:00');
        $this->artisan('lifecycle:notify')
            ->expectsOutputToContain('held: gets a weekly digest, and had one 3 days ago')
            ->assertExitCode(0);
        Mail::assertSentCount(1);

        // The next Friday: a week on, one digest with everything owed by then.
        Carbon::setTestNow('2026-09-18 07:00:00');
        $this->artisan('lifecycle:notify')->expectsOutputToContain('Sent 1 digest covering')->assertExitCode(0);
        Mail::assertSentCount(2);
        Mail::assertSent(DigestMail::class, fn (DigestMail $m): bool => $m->digest->count() >= 1 && Carbon::now()->isFriday());
    });

    it('tells people in the email where to change it', function () {
        duePage('a', 'alice');

        $digest = app(Notifier::class)->digests(
            app(Reminders::class)->owed(new DateTimeImmutable('2026-09-11'))
        )[0];

        expect((new DigestMail($digest))->render())
            ->toContain('To hear weekly instead, or not by email at all')
            ->toContain(cp_route('preferences.user.edit'));
    });
});
