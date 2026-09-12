<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Snapshot;
use Bpmore\StatamicLifecycle\Reporting\Snapshots;
use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Bpmore\StatamicLifecycle\Reviews\RecordReview;
use Bpmore\StatamicLifecycle\Widgets\Lifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Widgets\Loader;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-12 10:00:00');
    Collection::make('pages')->save();
    $this->alice = User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
    User::make()->id('bob')->email('bob@example.edu')->save();

    $row = function (string $id, string $status, ?string $owner) {
        Entry::make()->id($id)->collection('pages')->slug($id)->published(true)->data(['title' => ucfirst($id)])->save();
        EntryState::query()->insert([
            'entry_id' => $id, 'site' => 'default', 'collection' => 'pages',
            'owner_id' => $owner, 'owner_type' => $owner === null ? null : 'user', 'owner_source' => $owner === null ? null : 'assigned',
            'interval_days' => 365, 'next_review_at' => '2026-09-01 00:00:00', 'status' => $status, 'baselined' => false, 'computed_at' => '2026-09-12 00:00:00',
        ]);
    };
    $row('a', 'overdue', 'alice');
    $row('b', 'expired', 'alice');
    $row('c', 'due_soon', 'alice');
    $row('d', 'overdue', 'bob');
    $row('e', 'fresh', null);
});

afterEach(fn () => Carbon::setTestNow());

describe('snapshots', function () {
    it('write the day\'s numbers down, once per day, the later run winning', function () {
        app(RecordReview::class)->record(Entry::find('e'), 'bob', Outcome::Current);

        $first = app(Snapshots::class)->record(new DateTimeImmutable('2026-09-12 00:05'));

        expect($first->day->format('Y-m-d'))->toBe('2026-09-12')
            ->and($first->total)->toBe(5)
            ->and($first->overdue)->toBe(2)
            ->and($first->expired)->toBe(1)
            ->and($first->due_soon)->toBe(1)
            ->and($first->late())->toBe(3)
            ->and($first->never_reviewed)->toBe(4)
            ->and($first->no_owner)->toBe(1)   // e: reviewed by bob, but a reviewer is not an owner
            ->and($first->reviews)->toBe(1);

        EntryState::query()->where('entry_id', 'd')->update(['status' => 'fresh']);
        $again = app(Snapshots::class)->record(new DateTimeImmutable('2026-09-12 23:00'));

        expect(Snapshot::query()->count())->toBe(1)
            ->and($again->overdue)->toBe(1)
            ->and($again->recorded_at->format('H:i'))->toBe('23:00');
    });

    it('are taken at the end of every recompute', function () {
        $this->artisan('lifecycle:recompute', ['--sync' => true])->assertExitCode(0);

        expect(Snapshot::query()->count())->toBe(1)
            ->and(Snapshot::query()->first()->day->format('Y-m-d'))->toBe('2026-09-12');
    });

    it('read back the last so many days, oldest first, only the days recorded', function () {
        foreach (['2026-08-01', '2026-08-20', '2026-09-01', '2026-09-12'] as $day) {
            Snapshot::query()->insert(['day' => $day, 'total' => 5, 'fresh' => 1, 'due_soon' => 1, 'overdue' => 2, 'expired' => 1, 'exempt' => 0, 'never_reviewed' => 4, 'no_owner' => 1, 'reviews' => 0, 'recorded_at' => $day.' 00:05:00']);
        }

        $last = app(Snapshots::class)->last(30, new DateTimeImmutable('2026-09-12'));

        expect(array_map(fn (Snapshot $s): string => $s->day->format('Y-m-d'), $last))->toBe(['2026-08-20', '2026-09-01', '2026-09-12']);
    });

    it('have a table with a day as the key', function () {
        expect(Schema::hasTable('lifecycle_snapshots'))->toBeTrue()
            ->and(collect(Schema::getIndexes('lifecycle_snapshots'))->firstWhere('primary', true)['columns'])->toBe(['day']);
    });
});

describe('the widget', function () {
    it('is registered under the handle the site puts in its dashboard config', function () {
        // ['type' => 'lifecycle', 'width' => 50] in config/statamic/cp.php.
        expect(app('statamic.widgets')->get('lifecycle'))->toBe(Lifecycle::class)
            ->and(app(Loader::class)->load('lifecycle', ['title' => 'Reviews']))->toBeInstanceOf(Lifecycle::class);
    });

    it('shows my overdue count, the site\'s, and the thirty-day trend, each a way in', function () {
        $this->actingAs($this->alice);
        Snapshot::query()->insert(['day' => '2026-08-14', 'total' => 5, 'fresh' => 2, 'due_soon' => 0, 'overdue' => 4, 'expired' => 1, 'exempt' => 0, 'never_reviewed' => 5, 'no_owner' => 1, 'reviews' => 0, 'recorded_at' => '2026-08-14 00:05:00']);
        Snapshot::query()->insert(['day' => '2026-09-01', 'total' => 5, 'fresh' => 2, 'due_soon' => 0, 'overdue' => 3, 'expired' => 1, 'exempt' => 0, 'never_reviewed' => 5, 'no_owner' => 1, 'reviews' => 0, 'recorded_at' => '2026-09-01 00:05:00']);

        $props = (new Lifecycle)->component()->toArray();

        expect($props['name'])->toBe('lifecycle-widget')
            ->and($props['props']['installed'])->toBeTrue()
            ->and($props['props']['title'])->toBe('Content review')
            ->and($props['props']['mine'])->toBe(['overdue' => 2, 'due_soon' => 1])
            ->and($props['props']['site'])->toBe(['overdue' => 3, 'due_soon' => 1, 'total' => 5])
            ->and($props['props']['trend'])->toBe([['day' => '2026-08-14', 'late' => 5], ['day' => '2026-09-01', 'late' => 4]])
            // Three late now, five on the oldest day in the window, 29 days ago.
            ->and($props['props']['change'])->toBe(-2)
            ->and($props['props']['since'])->toBe(29)
            ->and($props['props']['days'])->toBe(30)
            ->and($props['props']['mineUrl'])->toContain('/lifecycle/mine')
            ->and($props['props']['overdueUrl'])->toContain('status=overdue');
    });

    it('takes its title from the dashboard config, and has no trend before there is one', function () {
        $this->actingAs($this->alice);
        $widget = new Lifecycle;
        $widget->setConfig(['title' => 'Reviews']);

        $props = $widget->component()->toArray()['props'];

        expect($props['title'])->toBe('Reviews')
            ->and($props['trend'])->toBe([])
            ->and($props['change'])->toBeNull();
    });

    it('does not call today\'s own snapshot a trend', function () {
        $this->actingAs($this->alice);
        app(Snapshots::class)->record(new DateTimeImmutable('2026-09-12 00:05'));

        $props = (new Lifecycle)->component()->toArray()['props'];

        expect($props['trend'])->toHaveCount(1)
            ->and($props['change'])->toBeNull()
            ->and($props['since'])->toBeNull();
    });

    it('says so rather than failing when Lifecycle is not installed', function () {
        config([
            'lifecycle.connection' => 'lifecycle_absent',
            'database.connections.lifecycle_absent' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/absent-'.uniqid().'.sqlite', 'prefix' => ''],
        ]);

        $props = (new Lifecycle)->component()->toArray()['props'];

        expect($props['installed'])->toBeFalse()->and($props['dashboardUrl'])->toContain('/lifecycle');
    });
});
