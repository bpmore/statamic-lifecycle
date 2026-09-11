<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\Review;
use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Bpmore\StatamicLifecycle\Reviews\RecordReview;
use Bpmore\StatamicLifecycle\Reviews\ReviewHistory;
use Bpmore\StatamicLifecycle\Reviews\ReviewRecord;
use Bpmore\StatamicLifecycle\Reviews\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    Collection::make('pages')->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
    User::make()->id('bob')->email('bob@example.edu')->save();   // no name: falls back to email

    foreach (['page', 'other'] as $id) {
        Entry::make()->id($id)->collection('pages')->slug($id)->published(true)->data(['title' => $id])->save();
    }
});

afterEach(fn () => Carbon::setTestNow());

it('is the per-entry answer to who confirmed this, and when', function () {
    $recorder = app(RecordReview::class);
    $entry = Entry::find('page');

    $recorder->record($entry, 'alice', Outcome::Current, null, new DateTimeImmutable('2026-01-10 09:00'));
    $recorder->record($entry, 'bob', Outcome::Updated, 'Fixed the opening hours.', new DateTimeImmutable('2026-05-02 14:30'));
    $recorder->record($entry, 'someone-who-left', Outcome::RewriteScheduled, null, new DateTimeImmutable('2026-08-20 11:00'));

    $timeline = app(ReviewHistory::class)->for('page');

    expect($timeline)->toBeInstanceOf(Timeline::class)
        ->and($timeline->entryId)->toBe('page')
        ->and($timeline->count())->toBe(3)
        ->and($timeline->isEmpty())->toBeFalse();

    // Newest first.
    expect(array_map(fn (ReviewRecord $r): string => $r->reviewedAt->format('Y-m-d'), $timeline->reviews))
        ->toBe(['2026-08-20', '2026-05-02', '2026-01-10']);

    [$latest, $middle, $first] = $timeline->reviews;

    expect($timeline->latest())->toBe($latest)
        ->and($latest->reviewerName)->toBe('someone-who-left')
        ->and($latest->reviewerExists)->toBeFalse()
        ->and($latest->outcome)->toBe(Outcome::RewriteScheduled)
        ->and($middle->reviewerName)->toBe('bob@example.edu')
        ->and($middle->reviewerExists)->toBeTrue()
        ->and($middle->note)->toBe('Fixed the opening hours.')
        ->and($middle->outcome)->toBe(Outcome::Updated)
        ->and($first->reviewerName)->toBe('Alice Ahmed')
        ->and($first->reviewedBy)->toBe('alice')
        ->and($first->note)->toBeNull()
        ->and($first->recordedAt)->toEqual(new DateTimeImmutable('2026-09-11 15:00'));
});

it('is empty for a page nobody has confirmed', function () {
    $timeline = app(ReviewHistory::class)->for('page');

    expect($timeline->isEmpty())->toBeTrue()
        ->and($timeline->count())->toBe(0)
        ->and($timeline->latest())->toBeNull()
        ->and($timeline->reviewedSince(new DateTimeImmutable('2020-01-01')))->toBeFalse()
        ->and($timeline)->toEqual(Timeline::empty('page'));
});

it('answers whether it has been confirmed since a date', function () {
    app(RecordReview::class)->record(Entry::find('page'), 'alice', Outcome::Current, null, new DateTimeImmutable('2026-05-02 14:30'));

    $timeline = app(ReviewHistory::class)->for('page');

    expect($timeline->reviewedSince(new DateTimeImmutable('2026-05-02 14:30')))->toBeTrue()
        ->and($timeline->reviewedSince(new DateTimeImmutable('2026-05-01')))->toBeTrue()
        ->and($timeline->reviewedSince(new DateTimeImmutable('2026-05-03')))->toBeFalse();
});

it('reads many entries in one query, looking each reviewer up once', function () {
    $recorder = app(RecordReview::class);
    $recorder->record(Entry::find('page'), 'alice', Outcome::Current, null, new DateTimeImmutable('2026-01-10'));
    $recorder->record(Entry::find('page'), 'alice', Outcome::Current, null, new DateTimeImmutable('2026-06-10'));
    $recorder->record(Entry::find('other'), 'bob', Outcome::Retired, null, new DateTimeImmutable('2026-03-01'));

    DB::connection()->enableQueryLog();
    $timelines = app(ReviewHistory::class)->forMany(['page', 'other', 'never', 'page']);

    expect(DB::connection()->getQueryLog())->toHaveCount(1)
        ->and(array_keys($timelines))->toBe(['page', 'other', 'never'])
        ->and($timelines['page']->count())->toBe(2)
        ->and($timelines['other']->latest()->outcome)->toBe(Outcome::Retired)
        ->and($timelines['never']->isEmpty())->toBeTrue();
});

it('reads nothing for nothing', function () {
    expect(app(ReviewHistory::class)->forMany([]))->toBe([]);
});

it('describes a review the same way everywhere it is shown', function () {
    app(RecordReview::class)->record(Entry::find('page'), 'alice', Outcome::Updated, 'Note.', new DateTimeImmutable('2026-05-02 14:30'));

    $array = app(ReviewHistory::class)->for('page')->toArray();

    expect($array['entry_id'])->toBe('page')
        ->and($array['count'])->toBe(1)
        ->and($array['latest'])->toBe($array['reviews'][0])
        ->and($array['reviews'][0])->toMatchArray([
            'reviewed_by' => 'alice',
            'reviewer_name' => 'Alice Ahmed',
            'reviewer_exists' => true,
            'reviewed_at' => '2026-05-02T14:30:00+00:00',
            'outcome' => 'updated',
            'outcome_label' => 'I updated it',
            'note' => 'Note.',
            'content_changed' => false,
            'recorded_at' => '2026-09-11T15:00:00+00:00',
        ])
        ->and($array['reviews'][0]['revision_ref'])->toStartWith('modified:');
});

it('keeps the model append-only in what it offers', function () {
    // No updated_at, no soft deletes, no fillable surprises: a Review is
    // created and read. The schema test already holds the table to that;
    // this holds the model to it.
    $review = new Review;

    expect($review->timestamps)->toBeFalse()
        ->and(method_exists($review, 'trashed'))->toBeFalse()
        ->and($review->getCasts())->toMatchArray(['outcome' => Outcome::class, 'content_changed' => 'boolean']);
});
