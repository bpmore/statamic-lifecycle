<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Assignment\OwnerAssigner;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Review;
use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Bpmore\StatamicLifecycle\Reviews\RecordReview;
use Bpmore\StatamicLifecycle\Reviews\ReviewHistory;
use Bpmore\StatamicLifecycle\Reviews\ReviewSource;
use Bpmore\StatamicLifecycle\StateRecomputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Spec §11: does editing count as reviewing? Not by default. A site may
 * say it does, and then the record says which reviews were edits.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 15:00:00');
    config(['statamic.editions.pro' => true]);
    Collection::make('pages')->save();
    $this->alice = User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
    $this->bob = User::make()->id('bob')->email('bob@example.edu')->data(['name' => 'Bob Byrne'])->save();

    Entry::make()->id('page')->collection('pages')->slug('page')->published(true)->data([
        'title' => 'Page',
        'updated_at' => (new DateTimeImmutable('2026-01-01'))->getTimestamp(),
    ])->save();
    app(StateRecomputer::class)->recompute(['page'], Carbon::now()->toImmutable());
    Review::query()->delete();
});

afterEach(fn () => Carbon::setTestNow());

function editAs($user, string $id = 'page', string $title = 'Page, edited'): void
{
    test()->actingAs($user);
    Entry::find($id)->set('title', $title)->save();
}

it('is off by default: a save records nothing', function () {
    expect(config('lifecycle.reviews.editing_counts'))->toBeFalse();

    editAs($this->alice);

    expect(Review::query()->count())->toBe(0)
        ->and(EntryState::query()->find('page')->last_reviewed_at)->toBeNull();
});

describe('when the site says editing counts', function () {
    beforeEach(fn () => config(['lifecycle.reviews.editing_counts' => true]));

    it('records a save of a published page as a review by that person, marked as an edit', function () {
        editAs($this->alice);

        $review = Review::query()->sole();

        expect($review->entry_id)->toBe('page')
            ->and($review->reviewed_by)->toBe('alice')
            ->and($review->outcome)->toBe(Outcome::Updated)
            ->and($review->source)->toBe(ReviewSource::Edit)
            ->and($review->reviewed_at)->toEqual(new DateTimeImmutable('2026-10-19 15:00'))
            ->and($review->note)->toBeNull();

        // And the state follows, as for any review.
        $row = EntryState::query()->find('page');

        expect($row->last_reviewed_at)->toEqual(new DateTimeImmutable('2026-10-19 15:00'))
            ->and($row->last_reviewed_by)->toBe('alice')
            ->and($row->next_review_at->format('Y-m-d'))->toBe('2027-10-19');

        // The timeline says so, in words the panel shows.
        $record = app(ReviewHistory::class)->for('page')->latest();

        expect($record->source)->toBe(ReviewSource::Edit)
            ->and($record->toArray()['source_label'])->toBe('from an edit');
    });

    it('keeps an attestation an attestation', function () {
        app(RecordReview::class)->record(Entry::find('page'), 'alice', Outcome::Current);

        $review = Review::query()->sole();

        expect($review->source)->toBe(ReviewSource::Attestation)
            ->and(app(ReviewHistory::class)->for('page')->latest()->toArray()['source'])->toBe('attestation');
    });

    it('records one review a day per person, however many saves', function () {
        editAs($this->alice, title: 'One');
        editAs($this->alice, title: 'Two');
        editAs($this->alice, title: 'Three');

        expect(Review::query()->count())->toBe(1);

        // Another person the same day is another review.
        editAs($this->bob, title: 'Four');
        expect(Review::query()->count())->toBe(2);

        // The same person the next day is too.
        Carbon::setTestNow('2026-10-20 09:00:00');
        editAs($this->alice, title: 'Five');
        expect(Review::query()->count())->toBe(3);

        // But an explicit attestation today does not stop an edit counting:
        // only an earlier *edit* by the same person does.
        Carbon::setTestNow('2026-10-21 09:00:00');
        app(RecordReview::class)->record(Entry::find('page'), 'alice', Outcome::Current);
        editAs($this->alice, title: 'Six');
        expect(Review::query()->count())->toBe(5);
    });

    it('does not count a save that changed nothing', function () {
        $this->actingAs($this->alice);
        Entry::find('page')->save();

        expect(Review::query()->count())->toBe(0);
    });

    it('does not count a draft, or a save with nobody behind it', function () {
        Entry::make()->id('draft')->collection('pages')->slug('draft')->published(false)->data(['title' => 'Draft'])->save();
        $this->actingAs($this->alice);
        Entry::find('draft')->set('title', 'Draft, edited')->save();

        // No signed-in user: an import, a command.
        auth()->logout();
        Entry::find('page')->set('title', 'Imported')->save();

        expect(Review::query()->count())->toBe(0);
    });

    it('does not count the addon\'s own writes', function () {
        $this->actingAs($this->alice);
        app(OwnerAssigner::class)->assign([Entry::find('page')], 'bob');

        expect(Entry::find('page')->get('content_owner'))->toBe('bob')
            ->and(Review::query()->count())->toBe(0);
    });

    it('never stops a save when its tables are not there', function () {
        Schema::drop('lifecycle_reviews');

        editAs($this->alice);

        expect(Entry::find('page')->get('title'))->toBe('Page, edited');
    });
});
