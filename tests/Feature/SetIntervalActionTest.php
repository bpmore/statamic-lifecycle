<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Actions\SetInterval;
use Bpmore\StatamicLifecycle\Assignment\IntervalSetter;
use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\State\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Action;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * "Set review interval" in the listing (spec §8): the same edit an editor
 * makes in the sidebar, done for a filtered selection at once.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 15:00:00');
    config(['statamic.editions.pro' => true, 'lifecycle.intervals.collections' => ['pages' => 'yearly']]);
    Collection::make('pages')->save();
    $this->editor = User::make()->id('editor')->email('editor@example.edu')->makeSuper()->save();

    foreach (['one', 'two', 'three'] as $id) {
        Entry::make()->id($id)->collection('pages')->slug($id)->published(true)->data([
            'title' => $id,
            'updated_by' => 'editor',
            'updated_at' => (new DateTimeImmutable('2026-10-01'))->getTimestamp(),
        ])->save();
    }
});

afterEach(fn () => Carbon::setTestNow());

function setIntervalThroughTheListing($test, array $selections, array $values)
{
    return $test->actingAs($test->editor)
        ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
            'action' => 'set_interval',
            'selections' => $selections,
            'values' => $values,
        ]);
}

it('is registered with Statamic and offered on entries, singly and in bulk', function () {
    $action = Action::get('set_interval');

    expect($action)->toBeInstanceOf(SetInterval::class)
        ->and(SetInterval::title())->toBe('Set review interval')
        ->and($action->visibleTo(Entry::find('one')))->toBeTrue()
        ->and($action->visibleTo(Collection::find('pages')))->toBeFalse()
        ->and($action->visibleToBulk(collect([Entry::find('one'), Entry::find('two')])))->toBeTrue();
});

it('offers the default, every interval an editor can pick, and the ones the site added', function () {
    config(['lifecycle.intervals.named' => ['fortnightly' => 14]]);
    app()->forgetInstance(Policy::class);

    $field = Action::get('set_interval')->fields()->get('interval');

    expect($field->type())->toBe('select')
        ->and(array_keys($field->get('options')))->toBe(['default', 'monthly', 'quarterly', 'every_6_months', 'yearly', 'every_2_years', 'every_3_years', 'fortnightly'])
        ->and($field->get('options')['default'])->toBe('The default for the collection')
        ->and($field->get('options')['fortnightly'])->toBe('Fortnightly')
        ->and($field->rules()['interval'])->toContain('required');
});

it('writes the interval into each entry and recomputes the next review from it', function () {
    setIntervalThroughTheListing($this, ['one', 'two'], ['interval' => 'quarterly'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Set the review interval to quarterly on 2 pages.');

    expect(file_get_contents(Entry::find('one')->path()))->toContain('review_interval: quarterly')
        ->and(Entry::find('three')->get(LifecycleFields::REVIEW_INTERVAL))->toBeNull()
        // Whoever ran it is not now the last author.
        ->and(Entry::find('one')->get('updated_by'))->toBe('editor');

    $row = EntryState::query()->find('one');

    expect($row->interval_days)->toBe(90)
        ->and($row->interval_source)->toBe('entry')
        ->and($row->next_review_at->format('Y-m-d'))->toBe('2026-12-30');
});

it('puts a page back on the default by taking its own interval away', function () {
    Entry::find('one')->set(LifecycleFields::REVIEW_INTERVAL, 'monthly')->save();

    setIntervalThroughTheListing($this, ['one', 'two'], ['interval' => 'default'])
        ->assertJsonPath('message', "Set the review interval to the collection's default on 1 page. 1 already had it.");

    expect(file_get_contents(Entry::find('one')->path()))->not->toContain('review_interval');

    $row = EntryState::query()->find('one');

    expect($row->interval_days)->toBe(365)
        ->and($row->interval_source)->toBe('collection');
});

it('says when some, or all, already had it', function () {
    setIntervalThroughTheListing($this, ['one'], ['interval' => 'quarterly']);

    setIntervalThroughTheListing($this, ['one', 'two'], ['interval' => 'quarterly'])
        ->assertJsonPath('message', 'Set the review interval to quarterly on 1 page. 1 already had it.');

    setIntervalThroughTheListing($this, ['one', 'two'], ['interval' => 'quarterly'])
        ->assertJsonPath('message', 'All 2 pages already have quarterly as their review interval.');

    setIntervalThroughTheListing($this, ['three'], ['interval' => 'default'])
        ->assertJsonPath('message', "That page already has the collection's default as its review interval.");
});

it('refuses an interval it does not offer, without writing anything', function () {
    setIntervalThroughTheListing($this, ['one'], ['interval' => 'whenever'])->assertStatus(422);
    setIntervalThroughTheListing($this, ['one'], [])->assertStatus(422);

    expect(Entry::find('one')->get(LifecycleFields::REVIEW_INTERVAL))->toBeNull();
});

it('writes nothing on a dry run', function () {
    $change = app(IntervalSetter::class)->set([Entry::find('one')], 'quarterly', dryRun: true);

    expect($change->changed)->toBe(['one'])
        ->and($change->interval)->toBe('quarterly')
        ->and($change->dryRun)->toBeTrue()
        ->and(Entry::find('one')->get(LifecycleFields::REVIEW_INTERVAL))->toBeNull();
});

it('lets a translation have the default rather than the original\'s cadence', function () {
    Site::setSites([
        'default' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
        'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => '/fr/'],
    ]);
    Collection::make('pages')->sites(['default', 'fr'])->save();

    Entry::make()->id('en')->collection('pages')->locale('default')->slug('about')->published(true)->data(['title' => 'About', 'review_interval' => 'quarterly'])->save();
    Entry::make()->id('fr')->collection('pages')->locale('fr')->origin('en')->slug('a-propos')->published(true)->data(['title' => 'À propos'])->save();

    expect(IntervalSetter::current(Entry::find('fr')))->toBe('quarterly');

    app(IntervalSetter::class)->set([Entry::find('fr')], null);

    // Its own say — null, kept in the file — and the original untouched.
    expect(IntervalSetter::current(Entry::find('fr')))->toBeNull()
        ->and(IntervalSetter::current(Entry::find('en')))->toBe('quarterly')
        ->and(EntryState::query()->find('fr')->interval_source)->toBe('collection');
});

it('is for whoever may edit the entries, and nobody else', function () {
    $reader = User::make()->id('reader')->email('reader@example.edu')->save();

    $this->actingAs($reader)
        ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
            'action' => 'set_interval',
            'selections' => ['one'],
            'values' => ['interval' => 'quarterly'],
        ])
        ->assertStatus(403);

    expect(Entry::find('one')->get(LifecycleFields::REVIEW_INTERVAL))->toBeNull();
});

it('warns that it writes the entry, and keeps the unsaved-changes warning', function () {
    $action = Action::get('set_interval');

    expect($action->confirmationText())->toContain('writes the interval into each entry')
        ->and($action->bypassesDirtyWarning())->toBeFalse();
});
