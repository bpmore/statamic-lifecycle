<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Review;
use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Bpmore\StatamicLifecycle\Reviews\RecordReview;
use Bpmore\StatamicLifecycle\StateRecomputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    Collection::make('pages')->save();
    User::make()->id('alice')->email('alice@example.edu')->save();
});

afterEach(fn () => Carbon::setTestNow());

function reviewable(string $id = 'page', array $data = [], bool $published = true): Statamic\Contracts\Entries\Entry
{
    $entry = Entry::make()->id($id)->collection('pages')->slug($id)->published($published)->data(array_merge([
        'title' => $id,
        'updated_at' => (new DateTimeImmutable('2026-03-01 10:00'))->getTimestamp(),
    ], $data));
    $entry->save();

    return $entry;
}

it('records who, when, what they decided, and what they said', function () {
    $entry = reviewable();

    $review = app(RecordReview::class)->record($entry, 'alice', Outcome::Current, '  Checked the phone numbers against the directory.  ');

    expect($review->entry_id)->toBe('page')
        ->and($review->reviewed_by)->toBe('alice')
        ->and($review->reviewed_at)->toEqual(new DateTimeImmutable('2026-09-11 15:00'))
        ->and($review->outcome)->toBe(Outcome::Current)
        ->and($review->note)->toBe('Checked the phone numbers against the directory.')
        ->and($review->content_changed)->toBeFalse()
        ->and($review->entry_revision_ref)->toBe('modified:'.(new DateTimeImmutable('2026-03-01 10:00'))->getTimestamp())
        ->and($review->created_at)->toEqual(new DateTimeImmutable('2026-09-11 15:00'));

    expect(Review::query()->count())->toBe(1);
});

it('keeps an empty note as no note', function () {
    $review = app(RecordReview::class)->record(reviewable(), 'alice', Outcome::Current, '   ');

    expect($review->note)->toBeNull();
});

it('brings the state row up to date from the review', function () {
    $entry = reviewable('page', [LifecycleFields::REVIEW_INTERVAL => 'quarterly']);
    app(StateRecomputer::class)->recompute(['page'], new DateTimeImmutable('2026-09-11'));

    // Last modified in March, quarterly: overdue since June.
    expect(EntryState::query()->find('page')->status)->toBe('expired');

    app(RecordReview::class)->record($entry, 'alice', Outcome::Current);

    $row = EntryState::query()->find('page');

    expect($row->last_reviewed_at)->toEqual(new DateTimeImmutable('2026-09-11 15:00'))
        ->and($row->last_reviewed_by)->toBe('alice')
        ->and($row->next_review_at)->toEqual(new DateTimeImmutable('2026-12-10 15:00'))
        ->and($row->next_review_source)->toBe('last_review')
        ->and($row->status)->toBe('fresh')
        ->and($row->computed_at)->toEqual(new DateTimeImmutable('2026-09-11 15:00'));
});

it('keeps the baseline flag and date on the row, now superseded by the review', function () {
    $entry = reviewable('page');
    $this->artisan('lifecycle:baseline')->assertExitCode(0);
    $baseline = EntryState::query()->find('page')->baseline_review_at;

    app(RecordReview::class)->record($entry, 'alice', Outcome::Current);

    $row = EntryState::query()->find('page');

    expect($row->baselined)->toBeTrue()
        ->and($row->baseline_review_at)->toEqual($baseline)
        ->and($row->next_review_source)->toBe('last_review');
});

it('spends a hard deadline once reviewed on or after it', function () {
    $entry = reviewable('page', [LifecycleFields::REVIEW_BY => '2026-09-01']);
    app(StateRecomputer::class)->recompute(['page'], new DateTimeImmutable('2026-09-11'));
    expect(EntryState::query()->find('page')->next_review_source)->toBe('hard_date');

    app(RecordReview::class)->record($entry, 'alice', Outcome::Updated);

    expect(EntryState::query()->find('page')->next_review_source)->toBe('last_review')
        ->and(EntryState::query()->find('page')->next_review_at->format('Y-m-d'))->toBe('2027-09-11');
});

it('can record a review that happened earlier, and says when it was recorded', function () {
    $entry = reviewable();

    $review = app(RecordReview::class)->record($entry, 'alice', Outcome::Current, null, new DateTimeImmutable('2026-08-20 09:00'));

    expect($review->reviewed_at)->toEqual(new DateTimeImmutable('2026-08-20 09:00'))
        ->and($review->created_at)->toEqual(new DateTimeImmutable('2026-09-11 15:00'))
        ->and(EntryState::query()->find('page')->last_reviewed_at)->toEqual(new DateTimeImmutable('2026-08-20 09:00'))
        ->and(EntryState::query()->find('page')->next_review_at->format('Y-m-d'))->toBe('2027-08-20');
});

it('notes whether the content changed between one review and the next', function () {
    $entry = reviewable();
    $recorder = app(RecordReview::class);

    // First review: nothing to compare against.
    expect($recorder->record($entry, 'alice', Outcome::Current)->content_changed)->toBeFalse();

    // Second, untouched since: no.
    Carbon::setTestNow('2026-10-01 10:00:00');
    expect($recorder->record($entry, 'alice', Outcome::Current)->content_changed)->toBeFalse();

    // Edited in between, then reviewed again: yes.
    Carbon::setTestNow('2026-10-15 10:00:00');
    $entry->set('updated_at', (new DateTimeImmutable('2026-10-10 12:00'))->getTimestamp())->save();
    Carbon::setTestNow('2026-11-01 10:00:00');
    expect($recorder->record($entry, 'alice', Outcome::Current)->content_changed)->toBeTrue();
});

it('is append-only: every review is another row, in order', function () {
    $entry = reviewable();
    $recorder = app(RecordReview::class);

    $recorder->record($entry, 'alice', Outcome::Current, null, new DateTimeImmutable('2026-01-01'));
    $recorder->record($entry, 'bob', Outcome::Updated, 'Fixed the dates.', new DateTimeImmutable('2026-05-01'));
    $recorder->record($entry, 'alice', Outcome::RewriteScheduled, null, new DateTimeImmutable('2026-09-01'));

    $timeline = Review::query()->forEntry('page')->newestFirst()->get();

    expect($timeline)->toHaveCount(3)
        ->and($timeline->pluck('reviewed_by')->all())->toBe(['alice', 'bob', 'alice'])
        ->and($timeline->first()->outcome)->toBe(Outcome::RewriteScheduled)
        ->and(EntryState::query()->find('page')->last_reviewed_by)->toBe('alice')
        ->and(EntryState::query()->find('page')->last_reviewed_at->format('Y-m-d'))->toBe('2026-09-01');
});

it('records a review of a draft, which has a history but no state', function () {
    // Lifecycle governs published content; a draft has nothing to be stale.
    // But somebody reviewed it, and that is still true.
    $entry = reviewable('draft', published: false);

    $review = app(RecordReview::class)->record($entry, 'alice', Outcome::Current);

    expect($review->exists)->toBeTrue()
        ->and(EntryState::query()->find('draft'))->toBeNull();
});

it('does not write to the entry', function () {
    // Spec §3, the single most important architectural decision: an
    // attestation is not a content change. Not a field, not a timestamp.
    $entry = reviewable();
    $path = $entry->path();
    $before = file_get_contents($path);
    $mtime = filemtime($path);
    touch($path, $mtime - 60);   // so a rewrite would show even within the same second
    clearstatcache();
    $mtime = filemtime($path);

    app(RecordReview::class)->record($entry, 'alice', Outcome::Current, 'A note that must not land in the file.');

    clearstatcache();
    expect(file_get_contents($path))->toBe($before)
        ->and(filemtime($path))->toBe($mtime)
        ->and(Entry::find('page')->data()->all())->not->toHaveKey('last_reviewed_at')
        ->and(Entry::find('page')->get('updated_at'))->toBe((new DateTimeImmutable('2026-03-01 10:00'))->getTimestamp());
});

it('offers the four outcomes in the reviewer\'s words', function () {
    expect(Outcome::options())->toBe([
        'current' => 'Still current',
        'updated' => 'I updated it',
        'rewrite_scheduled' => 'Needs a rewrite, and one is planned',
        'retired' => 'Retire it',
    ]);
});
