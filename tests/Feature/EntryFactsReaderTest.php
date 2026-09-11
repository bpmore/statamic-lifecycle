<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Entries\EntryFactsReader;
use Bpmore\StatamicLifecycle\State\OwnerSource;
use Bpmore\StatamicLifecycle\State\Policy;
use Bpmore\StatamicLifecycle\State\PriorState;
use Bpmore\StatamicLifecycle\State\StateComputer;
use Bpmore\StatamicLifecycle\State\Status;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

beforeEach(function () {
    Collection::make('pages')->save();
    User::make()->id('alice')->email('alice@example.edu')->save();
});

it('reads the fields the editor set, and who touched it last', function () {
    $entry = Entry::make()->id('e-1')->collection('pages')->slug('about')->data([
        'title' => 'About',
        LifecycleFields::CONTENT_OWNER => 'alice',
        LifecycleFields::REVIEW_INTERVAL => 'quarterly',
        LifecycleFields::REVIEW_BY => '2027-03-01',
        'updated_by' => 'alice',
        'updated_at' => (new DateTimeImmutable('2026-02-10 09:00'))->getTimestamp(),
    ]);
    $entry->save();

    $facts = (new EntryFactsReader)->read($entry);

    expect($facts->entryId)->toBe('e-1')
        ->and($facts->site)->toBe('default')
        ->and($facts->collection)->toBe('pages')
        ->and($facts->blueprint)->toBe('page')
        ->and($facts->ownerId)->toBe('alice')
        ->and($facts->reviewInterval)->toBe('quarterly')
        ->and($facts->reviewBy)->toEqual(new DateTimeImmutable('2027-03-01'))
        ->and($facts->lastAuthorId)->toBe('alice')
        ->and($facts->lastModifiedAt->format('Y-m-d H:i'))->toBe('2026-02-10 09:00')
        ->and($facts->exempt)->toBeFalse();
});

it('reads an entry with none of the fields set', function () {
    $entry = Entry::make()->id('e-2')->collection('pages')->slug('bare')->data(['title' => 'Bare']);
    $entry->save();

    $facts = (new EntryFactsReader)->read($entry);

    expect($facts->ownerId)->toBeNull()
        ->and($facts->reviewInterval)->toBeNull()
        ->and($facts->reviewBy)->toBeNull()
        ->and($facts->lastAuthorId)->toBeNull()
        // No updated_at in the data: falls back to the file's own time.
        ->and($facts->lastModifiedAt)->toBeInstanceOf(DateTimeImmutable::class);
});

it('does not count a last author who no longer exists', function () {
    // People leave. The dashboard inferring an owner nobody can email is
    // worse than admitting there is nobody.
    $entry = Entry::make()->id('e-3')->collection('pages')->slug('old')->data([
        'title' => 'Old',
        'updated_by' => 'someone-who-left',
    ]);
    $entry->save();

    expect((new EntryFactsReader)->read($entry)->lastAuthorId)->toBeNull();
});

it('takes one owner from a field a site declared without max_items', function () {
    $entry = Entry::make()->id('e-4')->collection('pages')->slug('two')->data([
        'title' => 'Two',
        LifecycleFields::CONTENT_OWNER => ['alice', 'bob'],
    ]);
    $entry->save();

    expect((new EntryFactsReader)->read($entry)->ownerId)->toBe('alice');
});

it('reads no deadline from a value that is not a date', function () {
    $entry = Entry::make()->id('e-5')->collection('pages')->slug('odd')->data([
        'title' => 'Odd',
        LifecycleFields::REVIEW_BY => 'soon',
    ]);
    $entry->save();

    expect((new EntryFactsReader)->read($entry)->reviewBy)->toBeNull();
});

it('lets a localisation inherit its origin\'s owner until it sets its own', function () {
    Site::setSites([
        'default' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
        'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => '/fr/'],
    ]);
    Collection::make('pages')->sites(['default', 'fr'])->save();

    $origin = Entry::make()->id('en-1')->collection('pages')->locale('default')->slug('about')->data([
        'title' => 'About',
        LifecycleFields::CONTENT_OWNER => 'alice',
        LifecycleFields::REVIEW_INTERVAL => 'quarterly',
    ]);
    $origin->save();

    // Synced: nothing of its own for these fields.
    $synced = Entry::make()->id('fr-1')->collection('pages')->locale('fr')->origin('en-1')->slug('a-propos')->data(['title' => 'À propos']);
    $synced->save();

    $syncedFacts = (new EntryFactsReader)->read($synced);

    expect($syncedFacts->site)->toBe('fr')
        ->and($syncedFacts->ownerId)->toBe('alice')
        ->and($syncedFacts->reviewInterval)->toBe('quarterly');

    // Localised: its own owner, its own cadence (spec §11).
    $synced->set(LifecycleFields::CONTENT_OWNER, 'bob')->set(LifecycleFields::REVIEW_INTERVAL, 'monthly')->save();

    $ownFacts = (new EntryFactsReader)->read($synced);

    expect($ownFacts->ownerId)->toBe('bob')
        ->and($ownFacts->reviewInterval)->toBe('monthly');
});

it('resolves the computer from the container with the site\'s policy', function () {
    config([
        'lifecycle.intervals.default' => 'quarterly',
        'lifecycle.intervals.collections' => ['pages' => 'monthly'],
        'lifecycle.owners.fallback' => ['default' => 'webmaster'],
        'lifecycle.due_soon_days' => 7,
    ]);

    $policy = app(Policy::class);
    $computer = app(StateComputer::class);

    expect($policy->defaultIntervalDays)->toBe(90)
        ->and($policy->collectionIntervals)->toBe(['pages' => 30])
        ->and($policy->fallbackOwners)->toBe(['default' => 'webmaster'])
        ->and($policy->dueSoonDays)->toBe(7)
        // One policy for everyone.
        ->and(app(Policy::class))->toBe($policy)
        ->and(app(StateComputer::class))->toBe($computer);
});

it('computes a real entry end to end', function () {
    $entry = Entry::make()->id('e-6')->collection('pages')->slug('handbook')->data([
        'title' => 'Handbook',
        LifecycleFields::REVIEW_INTERVAL => 'yearly',
        'updated_by' => 'alice',
        'updated_at' => (new DateTimeImmutable('2025-09-01'))->getTimestamp(),
    ]);
    $entry->save();

    $state = app(StateComputer::class)->compute(
        (new EntryFactsReader)->read($entry),
        PriorState::none(),
        new DateTimeImmutable('2026-09-11'),
    );

    // Never reviewed, never baselined, last touched a year and ten days
    // ago by alice: hers, due 2026-09-01, ten days overdue.
    expect($state->ownerId)->toBe('alice')
        ->and($state->ownerSource)->toBe(OwnerSource::Inferred)
        ->and($state->intervalDays)->toBe(365)
        ->and($state->nextReviewAt->format('Y-m-d'))->toBe('2026-09-01')
        ->and($state->status)->toBe(Status::Overdue);
});
