<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Actions\AssignOwner;
use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Action;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    // The control panel refuses more than one user without Pro.
    config(['statamic.editions.pro' => true]);
    Collection::make('pages')->save();
    $this->editor = User::make()->id('editor')->email('editor@example.edu')->makeSuper()->save();
    User::make()->id('priya')->email('priya@example.edu')->data(['name' => 'Priya Nair'])->save();

    foreach (['one', 'two', 'three'] as $id) {
        Entry::make()->id($id)->collection('pages')->slug($id)->published(true)->data([
            'title' => $id,
            'updated_by' => 'editor',
        ])->save();
    }
});

afterEach(fn () => Carbon::setTestNow());

function assignThroughTheListing($test, array $selections, array $values)
{
    return $test->actingAs($test->editor)
        ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
            'action' => 'assign_owner',
            'selections' => $selections,
            'values' => $values,
        ]);
}

it('is registered with Statamic and offered on entries, singly and in bulk', function () {
    $action = Action::get('assign_owner');

    expect($action)->toBeInstanceOf(AssignOwner::class)
        ->and(AssignOwner::title())->toBe('Assign owner')
        ->and($action->visibleTo(Entry::find('one')))->toBeTrue()
        ->and($action->visibleTo(Collection::find('pages')))->toBeFalse()
        ->and($action->visibleToBulk(collect([Entry::find('one'), Entry::find('two')])))->toBeTrue();
});

it('asks for one person', function () {
    $field = Action::get('assign_owner')->fields()->get('owner');

    expect($field->type())->toBe('users')
        ->and($field->get('max_items'))->toBe(1)
        ->and($field->rules()['owner'])->toContain('required');
});

it('warns that it writes the entry, and does not skip the unsaved-changes warning', function () {
    // Unlike Mark reviewed, this changes the entry under an open form.
    $action = Action::get('assign_owner');

    expect($action->confirmationText())->toContain('writes the owner into each entry')
        ->and($action->bypassesDirtyWarning())->toBeFalse();
});

it('assigns the owner to a filtered selection, writes it into each entry, and recomputes', function () {
    assignThroughTheListing($this, ['one', 'two'], ['owner' => ['priya']])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Assigned Priya Nair to 2 pages.');

    expect(Entry::find('one')->get(LifecycleFields::CONTENT_OWNER))->toBe('priya')
        ->and(Entry::find('two')->get(LifecycleFields::CONTENT_OWNER))->toBe('priya')
        ->and(Entry::find('three')->get(LifecycleFields::CONTENT_OWNER))->toBeNull()
        ->and(file_get_contents(Entry::find('one')->path()))->toContain('content_owner: priya')
        ->and(EntryState::query()->find('one')->owner_source)->toBe('assigned')
        ->and(EntryState::query()->find('two')->owner_id)->toBe('priya')
        ->and(EntryState::query()->find('three'))->toBeNull();

    // Whoever ran it is not now the last author.
    expect(Entry::find('one')->get('updated_by'))->toBe('editor');
});

it('says when some, or all, already had that owner', function () {
    Entry::find('one')->set(LifecycleFields::CONTENT_OWNER, 'priya')->save();

    assignThroughTheListing($this, ['one', 'two'], ['owner' => ['priya']])
        ->assertJsonPath('message', 'Assigned Priya Nair to 1 page. 1 already had them.');

    assignThroughTheListing($this, ['one', 'two'], ['owner' => ['priya']])
        ->assertJsonPath('message', 'All 2 pages already have Priya Nair as their owner.');

    assignThroughTheListing($this, ['one'], ['owner' => ['priya']])
        ->assertJsonPath('message', 'That page already has Priya Nair as its owner.');
});

it('needs an owner', function () {
    assignThroughTheListing($this, ['one'], ['owner' => []])->assertStatus(422);
    assignThroughTheListing($this, ['one'], [])->assertStatus(422);

    expect(Entry::find('one')->get(LifecycleFields::CONTENT_OWNER))->toBeNull();
});

it('refuses a person who does not exist, without writing anything', function () {
    assignThroughTheListing($this, ['one'], ['owner' => ['ghost']])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'There is no user with the ID [ghost].');

    expect(Entry::find('one')->get(LifecycleFields::CONTENT_OWNER))->toBeNull();
});

it('is not for someone who cannot edit the entries', function () {
    $reader = User::make()->id('reader')->email('reader@example.edu')->save();

    $this->actingAs($reader)
        ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
            'action' => 'assign_owner',
            'selections' => ['one'],
            'values' => ['owner' => ['priya']],
        ])
        ->assertStatus(403);

    expect(Entry::find('one')->get(LifecycleFields::CONTENT_OWNER))->toBeNull();
});
