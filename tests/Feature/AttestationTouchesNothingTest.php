<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\Review;
use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Bpmore\StatamicLifecycle\Reviews\RecordReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntryCreated;
use Statamic\Events\EntryCreating;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

/**
 * Spec §3, the single most important architectural decision in the product:
 * marking a page reviewed must not write to the entry. If it did, every
 * attestation would create a revision, could re-trigger other addons'
 * publish checks, and would fill the git history with noise that is not
 * content.
 *
 * So this is proven, with revisions turned on — the case where a write
 * would be most visible and most expensive — and through the real control
 * panel path, not just the service.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');

    config(['statamic.editions.pro' => true, 'statamic.revisions.enabled' => true]);
    Collection::make('pages')->revisionsEnabled(true)->save();

    $this->alice = User::make()->id('alice')->email('alice@example.edu')->makeSuper()->save();

    $this->entry = Entry::make()->id('page')->collection('pages')->slug('page')->published(true)->data([
        'title' => 'Page',
        'updated_at' => (new DateTimeImmutable('2026-03-01 10:00'))->getTimestamp(),
        'updated_by' => 'alice',
    ]);
    $this->entry->save();

    // One revision from a real publish, so there is a count to hold steady
    // and a latest revision for the record to point at.
    $this->entry->makeRevision()->user($this->alice)->message('Published')->save();

    expect($this->entry->revisionsEnabled())->toBeTrue()
        ->and($this->entry->revisions()->count())->toBe(1);
});

afterEach(fn () => Carbon::setTestNow());

function markReviewedThroughTheControlPanel($test, string $note = 'Checked.'): void
{
    $test->actingAs($test->alice)
        ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
            'action' => 'mark_reviewed',
            'selections' => ['page'],
            'values' => ['outcome' => 'current', 'note' => $note],
        ])
        ->assertOk();
}

it('creates no revision and no working copy, with revisions on', function () {
    // What this proves: the action does not go through the control panel's
    // publish flow, which is what makes revisions. A bare Entry::save() does
    // not make one — so this test alone would not notice a bare save. The
    // three tests after it are what catch that.
    $latestBefore = $this->entry->latestRevision()->id();

    markReviewedThroughTheControlPanel($this);

    $entry = Entry::find('page');

    expect($entry->revisions()->count())->toBe(1)
        ->and($entry->latestRevision()->id())->toBe($latestBefore)
        ->and($entry->hasWorkingCopy())->toBeFalse();

    // Not on disk either: the revisions store holds exactly the one file.
    $files = glob(rtrim(Stache::store('revisions')->directory(), '/').'/collections/pages/default/page/*.yaml') ?: [];
    expect($files)->toHaveCount(1);

    // And the record points at the revision that was reviewed.
    expect(Review::query()->forEntry('page')->first()->entry_revision_ref)->toBe('revision:'.$latestBefore);
});

it('never asks the repository to save the entry', function () {
    // The most direct proof there is: the only way an entry reaches disk,
    // the Stache, or a revision is Entry::save(), and it is never called.
    Entry::partialMock()->shouldNotReceive('save');

    markReviewedThroughTheControlPanel($this);
    app(RecordReview::class)->record(Entry::find('page'), 'alice', Outcome::Updated, 'Again, directly.');

    expect(Review::query()->count())->toBe(2);
});

it('fires none of the entry events that other addons and git automation listen to', function () {
    // A11y Gate checks on EntrySaving; Statamic's git automation commits
    // on EntrySaved. Neither may hear an attestation.
    Event::fake([EntryCreating::class, EntryCreated::class, EntrySaving::class, EntrySaved::class]);

    markReviewedThroughTheControlPanel($this);
    app(RecordReview::class)->record(Entry::find('page'), 'alice', Outcome::Current);

    Event::assertNotDispatched(EntryCreating::class);
    Event::assertNotDispatched(EntryCreated::class);
    Event::assertNotDispatched(EntrySaving::class);
    Event::assertNotDispatched(EntrySaved::class);

    // Control: the fake is watching. A real save is heard.
    Entry::find('page')->set('title', 'Edited')->save();
    Event::assertDispatched(EntrySaved::class);
});

it('leaves the file byte-identical, its mtime alone, and last-modified untouched', function () {
    $path = $this->entry->path();
    $bytes = file_get_contents($path);
    touch($path, filemtime($path) - 120);
    clearstatcache();
    $mtime = filemtime($path);

    markReviewedThroughTheControlPanel($this, 'A note that must not land in the file.');

    clearstatcache();
    $entry = Entry::find('page');

    expect(file_get_contents($path))->toBe($bytes)
        ->and(filemtime($path))->toBe($mtime)
        ->and($entry->lastModified()->format('Y-m-d H:i'))->toBe('2026-03-01 10:00')
        ->and($entry->get('updated_by'))->toBe('alice')
        ->and($entry->data()->all())->toBe($this->entry->data()->all());
});

it('is the same through the service as through the control panel', function () {
    // The action is a thin wrapper; the guarantee lives in RecordReview.
    // Whatever else calls it later — the sidebar panel, a bulk action, a
    // command — gets the same behaviour.
    $bytes = file_get_contents($this->entry->path());

    app(RecordReview::class)->record($this->entry, 'alice', Outcome::RewriteScheduled, 'Directly.');

    expect(file_get_contents($this->entry->path()))->toBe($bytes)
        ->and(Entry::find('page')->revisions()->count())->toBe(1)
        ->and(Entry::find('page')->hasWorkingCopy())->toBeFalse();
});
