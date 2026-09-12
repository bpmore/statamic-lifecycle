<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionLog;
use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\ExpiryPlanner;
use Bpmore\StatamicLifecycle\Models\AutomatedAction;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-12 03:00:00');
    // Three-entry collections would trip the breaker at once, which is right,
    // and not what most of these are about. The breaker's own test sets it.
    config(['lifecycle.expiry.circuit_breaker' => ['max_share' => '100%']]);
    Collection::make('news')->title('News')->routes('/news/{slug}')->save();
    Collection::make('pages')->title('Pages')->routes('/{slug}')->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
});

afterEach(fn () => Carbon::setTestNow());

/** A published entry with a state row. */
function entryIn(string $collection, string $id, string $status = 'expired', ?string $owner = 'alice', string $due = '2026-07-01'): void
{
    Entry::make()->id($id)->collection($collection)->slug($id)->published(true)->data(['title' => ucfirst($id)])->save();
    EntryState::query()->insert([
        'entry_id' => $id, 'site' => 'default', 'collection' => $collection,
        'owner_id' => $owner, 'owner_type' => $owner === null ? null : 'user', 'owner_source' => $owner === null ? null : 'assigned',
        'interval_days' => 365, 'next_review_at' => $due.' 00:00:00', 'status' => $status,
        'baselined' => false, 'computed_at' => '2026-09-12 00:00:00',
    ]);
}

function planned(?string $collection = null, array $allowNav = []): array
{
    $plan = app(ExpiryPlanner::class)->plan(new DateTimeImmutable('2026-09-12 03:00'), $collection, $allowNav);

    return [
        'allowed' => array_map(fn ($c) => "{$c->action->value} {$c->entryId}", $plan->allowed),
        'notices' => array_map(fn ($n) => "{$n->action->value} {$n->entryId} → {$n->recipient}", $plan->notices),
        'waiting' => array_map(fn ($w) => "{$w->candidate->action->value} {$w->candidate->entryId} until ".$w->actionableFrom->format('Y-m-d'), $plan->waiting),
        'deferred' => array_map(fn ($c) => "{$c->action->value} {$c->entryId}", $plan->deferred),
        'refused' => array_map(fn ($r) => "{$r->candidate->action->value} {$r->candidate->entryId}", $plan->refused),
        'trips' => array_map(fn ($t) => $t->collection, $plan->trips),
        'unowned' => array_map(fn ($c) => "{$c->action->value} {$c->entryId}", $plan->unowned),
        'expired' => $plan->expired,
    ];
}

describe('candidates', function () {
    it('are expired entries in opted-in collections, one per enabled action, and nothing else', function () {
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true, 'unpublish' => true]]]);
        entryIn('news', 'old');
        entryIn('news', 'late', 'overdue');   // not yet expired
        entryIn('news', 'fine', 'fresh');
        entryIn('pages', 'ancient');           // pages has not opted in

        $plan = planned();

        expect($plan['expired'])->toBe(1)
            ->and($plan['notices'])->toBe(['banner old → alice', 'unpublish old → alice'])
            ->and($plan['allowed'])->toBe([])
            ->and($plan['refused'])->toBe([]);
    });

    it('are nothing at all when nothing has opted in', function () {
        entryIn('news', 'old');

        expect(app(ExpiryPlanner::class)->plan(new DateTimeImmutable)->isEmpty())->toBeTrue();
    });

    it('skip an action already applied this cycle, or applied and put back', function () {
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true, 'noindex' => true]]]);
        entryIn('news', 'old');
        $log = app(ActionLog::class);
        $log->record(Entry::find('old'), ActionType::Banner, [], 'lifecycle:expire', new DateTimeImmutable('2026-08-01'));
        $noindex = $log->record(Entry::find('old'), ActionType::Noindex, [], 'lifecycle:expire', new DateTimeImmutable('2026-08-02'));
        $log->revert($noindex, 'alice');   // a person undid it: not again this cycle

        expect(planned()['notices'])->toBe([]);
    });

    it('count an action from a previous cycle as not applied', function () {
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true]]]);
        entryIn('news', 'old', due: '2026-07-01');
        app(ActionLog::class)->record(Entry::find('old'), ActionType::Banner, [], 'lifecycle:expire', new DateTimeImmutable('2025-06-01'));

        expect(planned()['notices'])->toBe(['banner old → alice']);
    });

    it('cannot proceed with nobody to notify, and say so', function () {
        config(['lifecycle.expiry.collections' => ['news' => ['unpublish' => true]]]);
        entryIn('news', 'orphan', owner: null);

        expect(planned()['unowned'])->toBe(['unpublish orphan'])
            ->and(planned()['notices'])->toBe([]);
    });

    it('can be narrowed to one collection', function () {
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true], 'pages' => ['banner' => true]]]);
        entryIn('news', 'old');
        entryIn('pages', 'ancient');

        expect(planned('pages')['notices'])->toBe(['banner ancient → alice'])
            ->and(planned('nope')['expired'])->toBe(0);
    });
});

describe('the rails, in order', function () {
    it('keeps out the never-touch list and the navigation before anything is counted', function () {
        config([
            'lifecycle.expiry.collections' => ['pages' => ['unpublish' => true]],
            'lifecycle.expiry.never_touch' => ['urls' => ['/legal-*']],
        ]);
        entryIn('pages', 'legal-privacy');   // at /legal-privacy
        entryIn('pages', 'about');
        entryIn('pages', 'lonely');
        $nav = Nav::make('main')->title('Main navigation');
        $nav->save();
        $nav->makeTree('default', [['entry' => 'about']])->save();

        $plan = planned();

        expect($plan['refused'])->toBe(['unpublish legal-privacy', 'unpublish about'])
            ->and($plan['notices'])->toBe(['unpublish lonely → alice']);

        // Allowed for this run: the navigation check stands aside.
        expect(planned(allowNav: ['about'])['notices'])->toBe(['unpublish about → alice', 'unpublish lonely → alice']);
    });

    it('trips the breaker before anyone is warned', function () {
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true]], 'lifecycle.expiry.circuit_breaker' => ['max_share' => '50%']]);
        for ($i = 0; $i < 4; $i++) {
            entryIn('news', "n{$i}");
        }
        entryIn('news', 'fine', 'fresh');   // 4 of 5 expired: 80%

        $plan = planned();

        expect($plan['trips'])->toBe(['news'])
            ->and($plan['notices'])->toBe([])
            ->and($plan['allowed'])->toBe([]);
    });

    it('lets a noticed page through after the period, and holds the rest to the cap', function () {
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true]], 'lifecycle.expiry.daily_cap' => 2, 'lifecycle.expiry.circuit_breaker' => ['max_share' => '100%']]);
        foreach (['a', 'b', 'c', 'd'] as $id) {
            entryIn('news', $id);
        }
        // a, b, c were noticed 8 days ago; d two days ago.
        foreach (['a', 'b', 'c'] as $id) {
            Notification::query()->insert(['entry_id' => $id, 'type' => 'notice', 'action' => 'banner', 'due_at' => '2026-07-01 00:00:00', 'recipient' => 'alice', 'sent_at' => '2026-09-04 07:00:00']);
        }
        Notification::query()->insert(['entry_id' => 'd', 'type' => 'notice', 'action' => 'banner', 'due_at' => '2026-07-01 00:00:00', 'recipient' => 'alice', 'sent_at' => '2026-09-10 07:00:00']);

        $plan = planned();

        expect($plan['allowed'])->toBe(['banner a', 'banner b'])
            ->and($plan['deferred'])->toBe(['banner c'])
            ->and($plan['waiting'])->toBe(['banner d until 2026-09-17'])
            ->and($plan['notices'])->toBe([]);
    });
});

describe('the command', function () {
    it('says it is notify-only until a collection opts in', function () {
        $this->artisan('lifecycle:expire', ['--dry-run' => true])
            ->expectsOutputToContain('No collection has opted into any expiry action, so nothing can happen.')
            ->assertExitCode(0);
    });

    it('prints exactly what would happen, and changes nothing', function () {
        Mail::fake();
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true, 'unpublish' => true]]]);
        entryIn('news', 'old');
        entryIn('news', 'orphan', owner: null);
        Notification::query()->insert(['entry_id' => 'old', 'type' => 'notice', 'action' => 'banner', 'due_at' => '2026-07-01 00:00:00', 'recipient' => 'alice', 'sent_at' => '2026-09-01 07:00:00']);

        $this->artisan('lifecycle:expire', ['--dry-run' => true])
            ->expectsOutputToContain('show a banner on it, unpublish it')
            ->expectsOutputToContain('2 expired entries in opted-in collections.')
            ->expectsOutputToContain('Would happen now (1)')
            ->expectsOutputToContain('show a banner on it — Old (/news/old)')
            ->expectsOutputToContain('Would notify the owner now, to act on or after 19 Sep 2026 (1)')
            ->expectsOutputToContain('unpublish it — Old (/news/old) → Alice Ahmed')
            ->expectsOutputToContain('Nobody to notify, so nothing can happen (2)')
            ->expectsOutputToContain('Dry run: nothing was changed and nobody was notified.')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        expect(AutomatedAction::query()->count())->toBe(0)
            ->and(Notification::query()->count())->toBe(1)
            ->and(Entry::find('old')->get('lifecycle_banner'))->toBeNull();
    });

    it('refuses a collection that does not exist', function () {
        config(['lifecycle.expiry.collections' => ['news' => ['banner' => true]]]);

        $this->artisan('lifecycle:expire', ['--dry-run' => true, '--collection' => 'nope'])
            ->expectsOutputToContain('There is no collection called [nope].')
            ->assertExitCode(1);
    });
});
