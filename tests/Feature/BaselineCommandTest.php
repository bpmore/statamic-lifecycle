<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\State\IntervalSource;
use Bpmore\StatamicLifecycle\State\LifecycleState;
use Bpmore\StatamicLifecycle\State\NextReviewSource;
use Bpmore\StatamicLifecycle\State\OwnerSource;
use Bpmore\StatamicLifecycle\State\Status;
use Bpmore\StatamicLifecycle\Storage\StateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    Collection::make('pages')->save();
    Collection::make('news')->save();
    User::make()->id('alice')->email('alice@example.edu')->save();
});

afterEach(fn () => Carbon::setTestNow());

/** A published entry last modified on the given day. */
function publishedEntry(string $id, string $collection, string $modified, array $data = []): void
{
    Entry::make()->id($id)->collection($collection)->slug($id)->published(true)->data(array_merge([
        'title' => $id,
        'updated_at' => (new DateTimeImmutable($modified))->getTimestamp(),
    ], $data))->save();
}

it('refuses to run before the tables exist', function () {
    config([
        'lifecycle.connection' => 'lifecycle_absent',
        'database.connections.lifecycle_absent' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/absent-'.uniqid().'.sqlite', 'prefix' => ''],
    ]);

    $this->artisan('lifecycle:baseline', ['--dry-run' => true])
        ->expectsOutputToContain('lifecycle:install')
        ->assertExitCode(1);
});

it('refuses a horizon of no months', function () {
    $this->artisan('lifecycle:baseline', ['--horizon' => 0])->assertExitCode(1);
});

it('says so when there is nothing to baseline', function () {
    $this->artisan('lifecycle:baseline')
        ->expectsOutputToContain('0 published entries')
        ->expectsOutputToContain('Nothing to do.')
        ->assertExitCode(0);
});

it('writes nothing on a dry run, and says exactly what it would have done', function () {
    publishedEntry('old', 'pages', '2019-03-01');
    publishedEntry('new', 'pages', '2026-08-01');

    $this->artisan('lifecycle:baseline', ['--dry-run' => true])
        ->expectsOutputToContain('2 published entries; 2 not yet baselined')
        ->expectsOutputToContain('2 first reviews spread across 12 months')
        ->expectsOutputToContain('Dry run: nothing was written')
        ->assertExitCode(0);

    expect(EntryState::query()->count())->toBe(0);
});

it('gives every published entry a first review date, oldest first, none overdue', function () {
    publishedEntry('oldest', 'pages', '2018-01-01');
    publishedEntry('middle', 'pages', '2022-06-01');
    publishedEntry('newest', 'pages', '2026-08-01');

    $this->artisan('lifecycle:baseline')
        ->expectsOutputToContain('Baselined 3 entries.')
        ->assertExitCode(0);

    $rows = EntryState::query()->get()->keyBy('entry_id');

    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        expect($row->baselined)->toBeTrue()
            ->and($row->baseline_review_at)->not->toBeNull()
            ->and($row->next_review_at)->toEqual($row->baseline_review_at)
            ->and($row->next_review_source)->toBe('baseline')
            ->and($row->next_review_at->format('Y-m-d'))->toBeGreaterThan('2026-09-11')
            ->and($row->next_review_at->format('Y-m-d'))->toBeLessThanOrEqual('2027-09-11')
            ->and($row->status)->toBeIn([Status::Fresh->value, Status::DueSoon->value])
            ->and($row->computed_at->format('Y-m-d H:i'))->toBe('2026-09-11 15:00');
    }

    // Oldest content surfaces first.
    expect($rows['oldest']->next_review_at->format('Y-m-d'))->toBe('2026-09-12')
        ->and($rows['oldest']->next_review_at < $rows['middle']->next_review_at)->toBeTrue()
        ->and($rows['middle']->next_review_at < $rows['newest']->next_review_at)->toBeTrue();
});

it('records the owner the computation resolved, flagged for what it is', function () {
    publishedEntry('guessed', 'pages', '2020-01-01', ['updated_by' => 'alice']);
    publishedEntry('chosen', 'pages', '2020-01-02', [LifecycleFields::CONTENT_OWNER => 'alice']);
    publishedEntry('nobody', 'pages', '2020-01-03');

    $this->artisan('lifecycle:baseline')->assertExitCode(0);

    $rows = EntryState::query()->get()->keyBy('entry_id');

    expect($rows['guessed']->owner_id)->toBe('alice')
        ->and($rows['guessed']->owner_source)->toBe('inferred')
        ->and($rows['chosen']->owner_source)->toBe('assigned')
        ->and($rows['nobody']->owner_id)->toBeNull();

    // And the guess stayed in the table: the entry itself is untouched.
    expect(Entry::find('guessed')->get(LifecycleFields::CONTENT_OWNER))->toBeNull();
});

it('spreads a shorter interval within its own window', function () {
    config(['lifecycle.intervals.collections' => ['news' => 'quarterly']]);

    for ($i = 0; $i < 6; $i++) {
        publishedEntry("n-{$i}", 'news', '2021-0'.($i + 1).'-01');
        publishedEntry("p-{$i}", 'pages', '2021-0'.($i + 1).'-01');
    }

    $this->artisan('lifecycle:baseline')
        ->expectsOutputToContain('spread across 90 days')
        ->assertExitCode(0);

    $news = EntryState::query()->where('collection', 'news')->get();
    $pages = EntryState::query()->where('collection', 'pages')->get();

    expect($news->max('next_review_at')->format('Y-m-d'))->toBeLessThanOrEqual('2026-12-10')
        ->and($pages->max('next_review_at')->format('Y-m-d'))->toBeGreaterThan('2027-06-01')
        ->and($news->pluck('interval_days')->unique()->all())->toBe([90]);
});

it('leaves an entry with its own review-by date on that date, but marks it baselined', function () {
    publishedEntry('deadline', 'pages', '2020-01-01', [LifecycleFields::REVIEW_BY => '2026-11-30']);

    $this->artisan('lifecycle:baseline')
        ->expectsOutputToContain('review-by date of their own')
        ->assertExitCode(0);

    $row = EntryState::query()->find('deadline');

    expect($row->baselined)->toBeTrue()
        ->and($row->baseline_review_at)->toBeNull()
        ->and($row->next_review_at->format('Y-m-d'))->toBe('2026-11-30')
        ->and($row->next_review_source)->toBe('hard_date');
});

it('gives an exempt entry a row and no date, and says so', function () {
    publishedEntry('archive', 'pages', '2020-01-01', [LifecycleFields::REVIEW_EXEMPT => true]);
    publishedEntry('live', 'pages', '2020-01-01');

    $this->artisan('lifecycle:baseline')
        ->expectsOutputToContain('Exempt from review, so no date to give')
        ->expectsOutputToContain('Baselined 2 entries.')
        ->assertExitCode(0);

    $row = EntryState::query()->find('archive');

    expect($row->status)->toBe(Status::Exempt->value)
        ->and($row->baselined)->toBeTrue()
        ->and($row->next_review_at)->toBeNull()
        ->and($row->baseline_review_at)->toBeNull()
        ->and(EntryState::query()->find('live')->next_review_at)->not->toBeNull();
});

it('leaves drafts alone', function () {
    // Lifecycle governs content that is already published. What is not
    // published yet is Workflows territory, and does not go stale.
    publishedEntry('live', 'pages', '2020-01-01');
    Entry::make()->id('draft')->collection('pages')->slug('draft')->published(false)->data(['title' => 'Draft'])->save();

    $this->artisan('lifecycle:baseline')->assertExitCode(0);

    expect(EntryState::query()->pluck('entry_id')->all())->toBe(['live']);
});

it('touches only what it has not seen when run again', function () {
    publishedEntry('first', 'pages', '2020-01-01');
    $this->artisan('lifecycle:baseline')->assertExitCode(0);
    $before = EntryState::query()->find('first')->next_review_at;

    publishedEntry('second', 'pages', '2021-01-01');

    $this->artisan('lifecycle:baseline')
        ->expectsOutputToContain('2 published entries; 1 not yet baselined')
        ->expectsOutputToContain('Already baselined or reviewed, left alone')
        ->expectsOutputToContain('Baselined 1 entry.')
        ->assertExitCode(0);

    expect(EntryState::query()->find('first')->next_review_at)->toEqual($before)
        ->and(EntryState::query()->find('second'))->not->toBeNull();
});

it('does not touch an entry a person has already reviewed', function () {
    publishedEntry('reviewed', 'pages', '2020-01-01');

    $reviewed = new LifecycleState(
        entryId: 'reviewed', site: 'default', collection: 'pages',
        ownerId: 'alice', ownerType: 'user', ownerSource: OwnerSource::Assigned,
        intervalDays: 365, intervalSource: IntervalSource::Global,
        lastReviewedAt: new DateTimeImmutable('2026-05-01'), lastReviewedBy: 'alice',
        nextReviewAt: new DateTimeImmutable('2027-05-01'), nextReviewSource: NextReviewSource::LastReview,
        status: Status::Fresh, baselined: false, computedAt: new DateTimeImmutable('2026-05-01'),
    );
    (new StateStore)->put($reviewed);

    $this->artisan('lifecycle:baseline')
        ->expectsOutputToContain('Nothing to do.')
        ->assertExitCode(0);

    expect(EntryState::query()->find('reviewed')->next_review_at->format('Y-m-d'))->toBe('2027-05-01')
        ->and(EntryState::query()->find('reviewed')->baselined)->toBeFalse();
});

it('honours the horizon', function () {
    for ($i = 0; $i < 12; $i++) {
        publishedEntry("e-{$i}", 'pages', '2020-01-'.sprintf('%02d', $i + 1));
    }

    $this->artisan('lifecycle:baseline', ['--horizon' => 3])
        ->expectsOutputToContain('spread across 3 months')
        ->assertExitCode(0);

    expect(EntryState::query()->max('next_review_at'))->toBeLessThanOrEqual('2026-12-11 00:00:00');
});

it('reports who would own what, and how sure that is', function () {
    User::make()->id('bob')->email('bob@example.edu')->save();
    config(['lifecycle.owners.fallback' => ['default' => 'bob']]);

    publishedEntry('chosen', 'pages', '2020-01-01', [LifecycleFields::CONTENT_OWNER => 'alice']);
    publishedEntry('guessed', 'pages', '2020-01-02', ['updated_by' => 'alice']);
    publishedEntry('defaulted', 'pages', '2020-01-03');

    $this->artisan('lifecycle:baseline', ['--dry-run' => true])
        ->expectsOutputToContain('Owners:')
        ->expectsOutputToContain('chosen by a person')
        ->expectsOutputToContain('inferred from the last author')
        ->expectsOutputToContain('the owner of last resort')
        ->assertExitCode(0);

    // The breakdown is of the states that would be written — so the same
    // numbers a real run writes.
    $this->artisan('lifecycle:baseline')->assertExitCode(0);

    expect(EntryState::query()->pluck('owner_source', 'entry_id')->all())->toBe([
        'chosen' => 'assigned',
        'guessed' => 'inferred',
        'defaulted' => 'fallback',
    ]);
});

it('warns when pages would have nobody and no owner of last resort is named', function () {
    publishedEntry('orphan', 'pages', '2020-01-01');

    // One phrase per line: Laravel's expectsOutputToContain satisfies only
    // the first matching expectation for each line written.
    $this->artisan('lifecycle:baseline', ['--dry-run' => true])
        ->expectsOutputToContain('1 entry has nobody to remind. Name an owner of last resort in Lifecycle\'s settings')
        ->assertExitCode(0);
});

it('warns when one person would be guessed for most of the site', function () {
    // The migration account. Twenty-five pages, every one last saved by it.
    for ($i = 0; $i < 25; $i++) {
        publishedEntry("p-{$i}", 'pages', '2022-11-'.sprintf('%02d', 1 + $i % 28), ['updated_by' => 'alice']);
    }

    $this->artisan('lifecycle:baseline', ['--dry-run' => true])
        ->expectsOutputToContain('25 of 25 entries (100%) would be guessed to one person, [alice]. If that is an import or admin account rather than someone who knows the content, add it to owners.never_infer')
        ->assertExitCode(0);
});

it('does not guess a person the site has ruled out', function () {
    config(['lifecycle.owners.never_infer' => ['alice']]);

    publishedEntry('imported', 'pages', '2022-11-14', ['updated_by' => 'alice']);

    $this->artisan('lifecycle:baseline')->assertExitCode(0);

    expect(EntryState::query()->find('imported')->owner_id)->toBeNull()
        ->and(EntryState::query()->find('imported')->owner_source)->toBeNull();
});

it('does not cry wolf on a handful of pages', function () {
    // Three pages by one person is not a migration account; it is a site
    // with three pages. The concentration warning needs a site to look at.
    for ($i = 0; $i < 3; $i++) {
        publishedEntry("p-{$i}", 'pages', '2022-11-0'.($i + 1), ['updated_by' => 'alice']);
    }

    $this->artisan('lifecycle:baseline', ['--dry-run' => true])
        ->doesntExpectOutputToContain('would be guessed to one person')
        ->assertExitCode(0);
});
