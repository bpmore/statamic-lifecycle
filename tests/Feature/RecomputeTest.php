<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Jobs\PruneStates;
use Bpmore\StatamicLifecycle\Jobs\RecomputeStates;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\StateRecomputer;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    Collection::make('pages')->save();
    Collection::make('news')->save();
    User::make()->id('alice')->email('alice@example.edu')->save();
});

afterEach(fn () => Carbon::setTestNow());

function liveEntry(string $id, string $collection, string $modified, array $data = []): void
{
    Entry::make()->id($id)->collection($collection)->slug($id)->published(true)->data(array_merge([
        'title' => $id,
        'updated_at' => (new DateTimeImmutable($modified))->getTimestamp(),
    ], $data))->save();
}

describe('the unit of work', function () {
    it('computes and writes a row for each published entry', function () {
        liveEntry('a', 'pages', '2026-01-01');
        liveEntry('b', 'news', '2026-02-01', [LifecycleFields::REVIEW_INTERVAL => 'quarterly']);

        $written = app(StateRecomputer::class)->recompute(['a', 'b'], new DateTimeImmutable('2026-09-11'));

        expect($written)->toBe(2)
            ->and(EntryState::query()->find('a')->interval_days)->toBe(365)
            ->and(EntryState::query()->find('a')->next_review_at->format('Y-m-d'))->toBe('2027-01-01')
            ->and(EntryState::query()->find('b')->interval_days)->toBe(90)
            // Due 2 May, and it is 11 September: 132 days overdue is past
            // the 30 the policy gives before "expired".
            ->and(EntryState::query()->find('b')->status)->toBe('expired');
    });

    it('keeps the baseline date and flag, so the spread survives the first night', function () {
        liveEntry('a', 'pages', '2020-01-01');
        $this->artisan('lifecycle:baseline')->assertExitCode(0);

        $before = EntryState::query()->find('a');
        expect($before->baselined)->toBeTrue()->and($before->baseline_review_at)->not->toBeNull();

        app(StateRecomputer::class)->recompute(['a'], new DateTimeImmutable('2026-09-12'));

        $after = EntryState::query()->find('a');

        expect($after->baselined)->toBeTrue()
            ->and($after->baseline_review_at)->toEqual($before->baseline_review_at)
            ->and($after->next_review_at)->toEqual($before->next_review_at)
            ->and($after->next_review_source)->toBe('baseline')
            ->and($after->computed_at->format('Y-m-d'))->toBe('2026-09-12');
    });

    it('moves with the calendar', function () {
        liveEntry('a', 'pages', '2020-01-01');
        $this->artisan('lifecycle:baseline')->assertExitCode(0);

        // A lone baselined entry is due tomorrow. Come back in two months.
        expect(EntryState::query()->find('a')->status)->toBe('due_soon');

        app(StateRecomputer::class)->recompute(['a'], new DateTimeImmutable('2026-11-15'));

        expect(EntryState::query()->find('a')->status)->toBe('expired');
    });

    it('picks up an edit to the entry', function () {
        liveEntry('a', 'pages', '2026-01-01');
        app(StateRecomputer::class)->recompute(['a'], new DateTimeImmutable('2026-09-11'));
        expect(EntryState::query()->find('a')->interval_days)->toBe(365);

        Entry::find('a')->set(LifecycleFields::REVIEW_INTERVAL, 'monthly')->set(LifecycleFields::CONTENT_OWNER, 'alice')->save();
        app(StateRecomputer::class)->recompute(['a'], new DateTimeImmutable('2026-09-11'));

        $row = EntryState::query()->find('a');

        expect($row->interval_days)->toBe(30)
            ->and($row->interval_source)->toBe('entry')
            ->and($row->owner_id)->toBe('alice')
            ->and($row->owner_source)->toBe('assigned');
    });

    it('builds on a recorded review', function () {
        liveEntry('a', 'pages', '2026-01-01');
        EntryState::query()->insert([
            'entry_id' => 'a', 'site' => 'default', 'collection' => 'pages', 'status' => 'fresh',
            'last_reviewed_at' => '2026-08-01 10:00:00', 'last_reviewed_by' => 'alice',
            'next_review_at' => '2027-08-01 10:00:00', 'baselined' => false, 'computed_at' => '2026-08-01 10:00:00',
        ]);

        app(StateRecomputer::class)->recompute(['a'], new DateTimeImmutable('2026-09-11'));

        $row = EntryState::query()->find('a');

        expect($row->last_reviewed_by)->toBe('alice')
            ->and($row->next_review_at->format('Y-m-d'))->toBe('2027-08-01')
            ->and($row->next_review_source)->toBe('last_review');
    });

    it('skips what is gone or no longer published, and leaves their rows alone', function () {
        liveEntry('live', 'pages', '2026-01-01');
        liveEntry('was-live', 'pages', '2026-01-01');
        app(StateRecomputer::class)->recompute(['live', 'was-live'], new DateTimeImmutable('2026-09-11'));

        Entry::find('was-live')->published(false)->save();

        $written = app(StateRecomputer::class)->recompute(['live', 'was-live', 'deleted-meanwhile'], new DateTimeImmutable('2026-09-12'));

        expect($written)->toBe(1)
            ->and(EntryState::query()->find('live')->computed_at->format('Y-m-d'))->toBe('2026-09-12')
            // Not touched — and not removed either; that is the cleanup pass's job.
            ->and(EntryState::query()->find('was-live')->computed_at->format('Y-m-d'))->toBe('2026-09-11')
            ->and(EntryState::query()->find('deleted-meanwhile'))->toBeNull();
    });

    it('does nothing with nothing', function () {
        expect(app(StateRecomputer::class)->recompute([], new DateTimeImmutable))->toBe(0);
    });
});

describe('scope', function () {
    it('enumerates published entries, optionally by collection and by site', function () {
        Site::setSites([
            'default' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
            'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => '/fr/'],
        ]);
        Collection::make('pages')->sites(['default', 'fr'])->save();

        liveEntry('p1', 'pages', '2026-01-01');
        liveEntry('n1', 'news', '2026-01-01');
        Entry::make()->id('p-fr')->collection('pages')->locale('fr')->slug('p-fr')->published(true)->data(['title' => 'fr'])->save();
        Entry::make()->id('draft')->collection('pages')->slug('draft')->published(false)->data(['title' => 'd'])->save();

        $recomputer = app(StateRecomputer::class);

        expect($recomputer->entries()->sort()->values()->all())->toBe(['n1', 'p-fr', 'p1'])
            ->and($recomputer->entries(collection: 'news')->all())->toBe(['n1'])
            ->and($recomputer->entries(site: 'fr')->all())->toBe(['p-fr'])
            ->and($recomputer->entries(site: 'default', collection: 'pages')->all())->toBe(['p1']);
    });
});

describe('the command', function () {
    it('refuses to run before the tables exist', function () {
        config([
            'lifecycle.connection' => 'lifecycle_absent',
            'database.connections.lifecycle_absent' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/absent-'.uniqid().'.sqlite', 'prefix' => ''],
        ]);

        $this->artisan('lifecycle:recompute')->expectsOutputToContain('lifecycle:install')->assertExitCode(1);
    });

    it('refuses a collection or site that does not exist', function () {
        $this->artisan('lifecycle:recompute', ['--collection' => 'nope'])
            ->expectsOutputToContain('There is no collection called [nope].')
            ->assertExitCode(1);

        $this->artisan('lifecycle:recompute', ['--site' => 'mars'])
            ->expectsOutputToContain('There is no site called [mars].')
            ->assertExitCode(1);
    });

    it('queues a batch of chunked jobs', function () {
        config(['lifecycle.recompute.chunk_size' => 2, 'lifecycle.recompute.queue' => 'lifecycle']);
        Bus::fake();

        for ($i = 0; $i < 5; $i++) {
            liveEntry("e-{$i}", 'pages', '2026-01-01');
        }

        $this->artisan('lifecycle:recompute')
            ->expectsOutputToContain('Queued 5 entries in 3 jobs, plus one to remove rows for entries that are gone.')
            ->assertExitCode(0);

        Bus::assertBatched(function ($batch): bool {
            $compute = $batch->jobs->filter(fn ($job): bool => $job instanceof RecomputeStates);
            $prune = $batch->jobs->filter(fn ($job): bool => $job instanceof PruneStates);

            return $batch->name === 'Lifecycle recompute'
                && $batch->jobs->count() === 4
                && $compute->count() === 3
                && $compute->sum(fn (RecomputeStates $job): int => count($job->entryIds)) === 5
                && $prune->count() === 1
                && $batch->jobs->last() instanceof PruneStates
                && $batch->options['allowFailures'] === true
                && $batch->options['queue'] === 'lifecycle';
        });
    });

    it('runs the batch for real on the sync driver, once installed', function () {
        // A flat-file site: no job_batches table until lifecycle:install
        // makes one. Then the batch runs inline and the rows appear.
        expect(Schema::hasTable('job_batches'))->toBeFalse();
        app(LifecycleDatabase::class)->install();
        expect(Schema::hasTable('job_batches'))->toBeTrue();

        liveEntry('a', 'pages', '2026-01-01');
        liveEntry('b', 'pages', '2026-02-01');
        // And a row for something that is not there any more.
        EntryState::query()->insert(['entry_id' => 'ghost', 'site' => 'default', 'collection' => 'pages', 'status' => 'overdue', 'baselined' => false, 'computed_at' => '2026-01-01 00:00:00']);

        $this->artisan('lifecycle:recompute')
            ->expectsOutputToContain('Queued 2 entries in 1 job, plus one to remove rows for entries that are gone.')
            ->assertExitCode(0);

        expect(EntryState::query()->pluck('entry_id')->sort()->values()->all())->toBe(['a', 'b']);
    });

    it('says so when there is nothing in scope', function () {
        $this->artisan('lifecycle:recompute', ['--sync' => true])
            ->expectsOutputToContain('No published entries to recompute.')
            ->assertExitCode(0);
    });

    it('runs here and now with --sync, within the scope given', function () {
        liveEntry('p1', 'pages', '2026-01-01');
        liveEntry('n1', 'news', '2026-01-01');

        $this->artisan('lifecycle:recompute', ['--sync' => true, '--collection' => 'news'])
            ->expectsOutputToContain('Recomputed 1 entry.')
            ->assertExitCode(0);

        expect(EntryState::query()->pluck('entry_id')->all())->toBe(['n1']);
    });

    it('is scheduled daily, once installed', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'lifecycle:recompute'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 0 * * *')
            // Installed here (the tables exist on the test connection), so
            // the filter lets it run.
            ->and($events->first()->filtersPass(app()))->toBeTrue();
    });
});

describe('orphans', function () {
    it('finds rows whose entry is gone, and rows whose entry is no longer published', function () {
        liveEntry('live', 'pages', '2026-01-01');
        liveEntry('was-live', 'pages', '2026-01-01');
        liveEntry('deleted', 'pages', '2026-01-01');
        app(StateRecomputer::class)->recompute(['live', 'was-live', 'deleted'], new DateTimeImmutable('2026-09-11'));

        Entry::find('was-live')->published(false)->save();
        Entry::find('deleted')->deleteQuietly();   // no event: the sweep's case

        expect(app(StateRecomputer::class)->orphans())->toEqualCanonicalizing(['deleted', 'was-live']);
    });

    it('stays within the scope it was given', function () {
        liveEntry('p', 'pages', '2026-01-01');
        liveEntry('n', 'news', '2026-01-01');
        app(StateRecomputer::class)->recompute(['p', 'n'], new DateTimeImmutable('2026-09-11'));
        Entry::find('p')->deleteQuietly();
        Entry::find('n')->deleteQuietly();

        // Asked about news only, it must not touch the pages row.
        expect(app(StateRecomputer::class)->orphans(collection: 'news'))->toBe(['n'])
            ->and(app(StateRecomputer::class)->prune(collection: 'news'))->toBe(1)
            ->and(EntryState::query()->pluck('entry_id')->all())->toBe(['p']);
    });

    it('removes them and leaves the live rows alone', function () {
        liveEntry('live', 'pages', '2026-01-01');
        liveEntry('gone', 'pages', '2026-01-01');
        app(StateRecomputer::class)->recompute(['live', 'gone'], new DateTimeImmutable('2026-09-11'));
        Entry::find('gone')->deleteQuietly();

        expect(app(StateRecomputer::class)->prune())->toBe(1)
            ->and(EntryState::query()->pluck('entry_id')->all())->toBe(['live']);
    });

    it('walks a large table in chunks without loading it', function () {
        // 1,200 ghost rows, no entries at all: every one is an orphan, and
        // the walk and the delete each take a handful of statements.
        $rows = [];
        for ($i = 0; $i < 1_200; $i++) {
            $rows[] = ['entry_id' => "ghost-{$i}", 'site' => 'default', 'collection' => 'pages', 'status' => 'overdue', 'baselined' => false, 'computed_at' => '2026-01-01 00:00:00'];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            EntryState::query()->insert($chunk);
        }

        DB::connection()->enableQueryLog();

        expect(app(StateRecomputer::class)->prune())->toBe(1_200)
            ->and(EntryState::query()->count())->toBe(0);

        // The walk is keyset-paginated selects of IDs; the removal is chunked
        // deletes. (The day's snapshot, taken at the end, has its own queries.)
        $log = collect(DB::connection()->getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q));
        expect($log->filter(fn ($q) => str_starts_with($q, 'delete from "lifecycle_states"'))->count())->toBe(3)
            ->and($log->filter(fn ($q) => str_starts_with($q, 'select "entry_id" from "lifecycle_states"'))->count())->toBeLessThanOrEqual(4);
    });

    it('is a job of its own, last in the batch', function () {
        liveEntry('gone', 'pages', '2026-01-01');
        app(StateRecomputer::class)->recompute(['gone'], new DateTimeImmutable('2026-09-11'));
        Entry::find('gone')->deleteQuietly();

        (new PruneStates)->handle(app(StateRecomputer::class));

        expect(EntryState::query()->count())->toBe(0);
    });

    it('is reported by a sync run', function () {
        liveEntry('live', 'pages', '2026-01-01');
        liveEntry('gone', 'pages', '2026-01-01');
        app(StateRecomputer::class)->recompute(['live', 'gone'], new DateTimeImmutable('2026-09-11'));
        Entry::find('gone')->deleteQuietly();

        $this->artisan('lifecycle:recompute', ['--sync' => true])
            ->expectsOutputToContain('Recomputed 1 entry.')
            ->expectsOutputToContain('Removed 1 row for entries that are gone or no longer published.')
            ->assertExitCode(0);
    });

    it('is swept even when nothing is live any more', function () {
        EntryState::query()->insert(['entry_id' => 'ghost', 'site' => 'default', 'collection' => 'pages', 'status' => 'overdue', 'baselined' => false, 'computed_at' => '2026-01-01 00:00:00']);

        $this->artisan('lifecycle:recompute', ['--sync' => true])
            ->expectsOutputToContain('No published entries to recompute.')
            ->expectsOutputToContain('Removed 1 row for entries that are gone or no longer published.')
            ->assertExitCode(0);

        expect(EntryState::query()->count())->toBe(0);
    });
});

describe('on deletion', function () {
    it('removes the row the moment the entry is deleted', function () {
        liveEntry('doomed', 'pages', '2026-01-01');
        liveEntry('other', 'pages', '2026-01-01');
        app(StateRecomputer::class)->recompute(['doomed', 'other'], new DateTimeImmutable('2026-09-11'));

        Entry::find('doomed')->delete();

        expect(EntryState::query()->pluck('entry_id')->all())->toBe(['other']);
    });

    it('never gets in the way of deleting an entry', function () {
        // The tables are not there — the site has not installed yet — and
        // the entry still goes.
        config([
            'lifecycle.connection' => 'lifecycle_absent',
            'database.connections.lifecycle_absent' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/absent-'.uniqid().'.sqlite', 'prefix' => ''],
        ]);
        liveEntry('doomed', 'pages', '2026-01-01');

        Entry::find('doomed')->delete();

        expect(Entry::find('doomed'))->toBeNull();
    });
});
