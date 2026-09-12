<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionApplier;
use Bpmore\StatamicLifecycle\Expiry\ActionLog;
use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\TripAlertMail;
use Bpmore\StatamicLifecycle\Models\AutomatedAction;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Notification;
use Bpmore\StatamicLifecycle\Notifications\DigestMail;
use Bpmore\StatamicLifecycle\Notifications\ReminderPreferences;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-12 06:30:00');   // a Saturday, so quiet — tests say otherwise where it matters
    config([
        'lifecycle.expiry.circuit_breaker' => ['max_share' => '100%'],
        'lifecycle.notifications.quiet_days' => [],
    ]);
    Collection::make('news')->title('News')->routes('/news/{slug}')->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
    User::make()->id('root')->email('root@example.edu')->makeSuper()->save();
});

afterEach(fn () => Carbon::setTestNow());

function expiredEntry(string $id, string $owner = 'alice', array $data = []): void
{
    Entry::make()->id($id)->collection('news')->slug($id)->published(true)->data(array_merge(['title' => ucfirst($id)], $data))->save();
    EntryState::query()->insert([
        'entry_id' => $id, 'site' => 'default', 'collection' => 'news',
        'owner_id' => $owner, 'owner_type' => 'user', 'owner_source' => 'assigned',
        'interval_days' => 365, 'next_review_at' => '2026-07-01 00:00:00', 'status' => 'expired',
        'baselined' => false, 'computed_at' => '2026-09-12 00:00:00',
    ]);
}

function noticed(string $id, string $action, string $sentAt = '2026-09-01 07:00:00'): void
{
    Notification::query()->insert(['entry_id' => $id, 'type' => 'notice', 'action' => $action, 'due_at' => '2026-07-01 00:00:00', 'recipient' => 'alice', 'sent_at' => $sentAt]);
}

describe('the actions', function () {
    it('sets a banner flag on the entry, and nothing more', function () {
        expiredEntry('old');
        $previous = app(ActionApplier::class)->apply(Entry::find('old'), ActionType::Banner);

        expect($previous)->toBe(['lifecycle_banner' => null])
            ->and(Entry::find('old')->get('lifecycle_banner'))->toBeTrue()
            ->and(Entry::find('old')->published())->toBeTrue()
            ->and(file_get_contents(Entry::find('old')->path()))->toContain('lifecycle_banner: true');
    });

    it('sets a noindex flag, on whatever field the site names', function () {
        config(['lifecycle.expiry.fields' => ['noindex' => 'seo_noindex']]);
        expiredEntry('old', data: ['seo_noindex' => false]);

        $previous = app(ActionApplier::class)->apply(Entry::find('old'), ActionType::Noindex);

        expect($previous)->toBe(['seo_noindex' => false])
            ->and(Entry::find('old')->get('seo_noindex'))->toBeTrue();
    });

    it('unpublishes through Statamic\'s own path', function () {
        expiredEntry('old');
        $previous = app(ActionApplier::class)->apply(Entry::find('old'), ActionType::Unpublish);

        expect($previous)->toBe(['published' => true])
            ->and(Entry::find('old')->published())->toBeFalse();
    });

    it('never makes Lifecycle the last author', function () {
        expiredEntry('old', data: ['updated_by' => 'alice']);
        app(ActionApplier::class)->apply(Entry::find('old'), ActionType::Banner);

        expect(Entry::find('old')->get('updated_by'))->toBe('alice');
    });

    it('refuses a field handle that is not one, and a restore', function () {
        expect(fn () => ActionApplier::fromConfig(['fields' => ['banner' => 'not a handle!']]))->toThrow(InvalidArgumentException::class);
        expiredEntry('old');
        expect(fn () => app(ActionApplier::class)->apply(Entry::find('old'), ActionType::Restore))->toThrow(InvalidArgumentException::class);
    });
});

describe('a real run', function () {
    it('does nothing at all until a collection opts in', function () {
        Mail::fake();
        expiredEntry('old');

        $this->artisan('lifecycle:expire')->expectsOutputToContain('No collection has opted into any expiry action')->assertExitCode(0);

        Mail::assertNothingSent();
        expect(AutomatedAction::query()->count())->toBe(0)->and(Entry::find('old')->published())->toBeTrue();
    });

    it('first notifies, then acts after the period, writing each action to the log', function () {
        Mail::fake();
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true, 'unpublish' => true]]]);
        expiredEntry('old');

        // Day one: two notices, no change.
        $this->artisan('lifecycle:expire')
            ->expectsOutputToContain('Notified owners of 2 coming actions.')
            ->expectsOutputToContain('Took 0 actions')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
        Mail::assertSent(DigestMail::class, fn (DigestMail $m): bool => $m->hasTo('alice@example.edu') && $m->digest->count() === 2);
        expect(Entry::find('old')->published())->toBeTrue()
            ->and(Notification::query()->where('type', 'notice')->count())->toBe(2)
            ->and(AutomatedAction::query()->count())->toBe(0);

        // Day two: waiting.
        Carbon::setTestNow('2026-09-13 06:30:00');
        $this->artisan('lifecycle:expire')->expectsOutputToContain('Took 0 actions')->assertExitCode(0);
        Mail::assertSentCount(1);

        // Day eight: the period has passed. Both actions, both logged.
        Carbon::setTestNow('2026-09-19 06:30:00');
        $this->artisan('lifecycle:expire')
            ->expectsOutputToContain('Took 2 actions, each written to the log with what it replaced.')
            ->assertExitCode(0);

        expect(Entry::find('old')->get('lifecycle_banner'))->toBeTrue()
            ->and(Entry::find('old')->published())->toBeFalse();

        $log = AutomatedAction::query()->orderBy('id')->get();
        expect($log)->toHaveCount(2)
            ->and($log[0]->action)->toBe(ActionType::Banner)
            ->and($log[0]->previous_state)->toBe(['lifecycle_banner' => null])
            ->and($log[0]->triggered_by)->toBe('lifecycle:expire')
            ->and($log[0]->applied_at->format('Y-m-d H:i'))->toBe('2026-09-19 06:30')
            ->and($log[1]->action)->toBe(ActionType::Unpublish)
            ->and($log[1]->previous_state)->toBe(['published' => true]);

        // And put back, from the log, as a person would.
        app(ActionLog::class)->revert($log[1], 'root');
        app(ActionLog::class)->revert($log[0], 'root');

        expect(Entry::find('old')->published())->toBeTrue()
            ->and(Entry::find('old')->data()->has('lifecycle_banner'))->toBeFalse();
    });

    it('does not do again this cycle what a person put back', function () {
        Mail::fake();
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true]]]);
        expiredEntry('old');
        noticed('old', 'banner');

        $this->artisan('lifecycle:expire')->expectsOutputToContain('Took 1 action')->assertExitCode(0);
        app(ActionLog::class)->revert(AutomatedAction::query()->first(), 'root');

        Carbon::setTestNow('2026-09-13 06:30:00');
        $this->artisan('lifecycle:expire')->expectsOutputToContain('Nothing to do.')->assertExitCode(0);

        expect(Entry::find('old')->get('lifecycle_banner'))->toBeNull();
    });

    it('sends notices past a muted preference, but not on a quiet day', function () {
        Mail::fake();
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true]]]);
        User::find('alice')->setPreference(ReminderPreferences::HANDLE, 'never')->save();
        expiredEntry('old');

        // Muted: the notice still goes. "Your page will change" is not a
        // reminder anybody has opted out of.
        $this->artisan('lifecycle:expire')->expectsOutputToContain('Notified owners of 1 coming action.')->assertExitCode(0);
        Mail::assertSentCount(1);

        // Quiet day: held, planned again tomorrow.
        Notification::query()->delete();
        config(['lifecycle.notifications.quiet_days' => ['saturday']]);
        $this->artisan('lifecycle:expire')
            ->expectsOutputToContain('Notices held: it is Saturday.')
            ->assertExitCode(0);
        Mail::assertSentCount(1);
        expect(Notification::query()->count())->toBe(0);
    });

    it('tells an admin when the breaker refuses, and takes nothing in that collection', function () {
        Mail::fake();
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true]], 'lifecycle.expiry.circuit_breaker' => ['max_share' => '10%']]);
        expiredEntry('old');
        noticed('old', 'banner');

        $this->artisan('lifecycle:expire')
            ->expectsOutputToContain('Refused by the circuit breaker (1)')
            ->expectsOutputToContain('Told an admin about 1 collection the circuit breaker refused.')
            ->assertExitCode(0);

        Mail::assertSent(TripAlertMail::class, fn (TripAlertMail $m): bool => $m->hasTo('root@example.edu'));
        expect(AutomatedAction::query()->count())->toBe(0)->and(Entry::find('old')->get('lifecycle_banner'))->toBeNull();
    });

    it('records what it managed when one page fails, and says which failed', function () {
        Mail::fake();
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true]]]);
        expiredEntry('old');
        expiredEntry('gone');
        noticed('old', 'banner');
        noticed('gone', 'banner');
        Entry::find('gone')->deleteQuietly();

        $this->artisan('lifecycle:expire')
            ->expectsOutputToContain('Took 1 action')
            ->expectsOutputToContain('the page no longer exists')
            ->assertExitCode(1);

        expect(AutomatedAction::query()->count())->toBe(1);
    });

    it('is scheduled daily, before the digests, once installed', function () {
        $events = collect(app(LaravelSchedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'lifecycle:expire'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('30 6 * * *')
            ->and($events->first()->filtersPass(app()))->toBeTrue();
    });
});
