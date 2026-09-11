<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Actions\MarkReviewed;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Review;
use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Action;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    Collection::make('pages')->save();
    $this->alice = User::make()->id('alice')->email('alice@example.edu')->makeSuper()->save();

    foreach (['one', 'two'] as $id) {
        Entry::make()->id($id)->collection('pages')->slug($id)->published(true)->data(['title' => $id])->save();
    }
});

afterEach(fn () => Carbon::setTestNow());

it('is registered with Statamic and offered on entries', function () {
    $action = Action::get('mark_reviewed');

    expect($action)->toBeInstanceOf(MarkReviewed::class)
        ->and(MarkReviewed::title())->toBe('Mark reviewed')
        ->and($action->visibleTo(Entry::find('one')))->toBeTrue()
        ->and($action->visibleTo(Collection::find('pages')))->toBeFalse()
        ->and($action->visibleToBulk(collect([Entry::find('one'), Entry::find('two')])))->toBeTrue();
});

it('asks for an outcome, and offers a note', function () {
    $fields = Action::get('mark_reviewed')->fields();

    expect($fields->get('outcome')->type())->toBe('select')
        ->and($fields->get('outcome')->get('options'))->toBe(Outcome::options())
        ->and($fields->get('outcome')->rules()['outcome'])->toContain('required')
        ->and($fields->get('note')->type())->toBe('textarea')
        ->and($fields->get('note')->rules()['note'])->toContain('nullable');
});

it('runs through the control panel, one entry or many', function () {
    $this->actingAs($this->alice)
        ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
            'action' => 'mark_reviewed',
            'selections' => ['one', 'two'],
            'values' => ['outcome' => 'updated', 'note' => 'Refreshed both.'],
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Marked 2 pages as reviewed.');

    expect(Review::query()->count())->toBe(2)
        ->and(Review::query()->forEntry('one')->first()->reviewed_by)->toBe('alice')
        ->and(Review::query()->forEntry('one')->first()->outcome)->toBe(Outcome::Updated)
        ->and(Review::query()->forEntry('two')->first()->note)->toBe('Refreshed both.')
        ->and(EntryState::query()->find('one')->last_reviewed_by)->toBe('alice')
        ->and(EntryState::query()->find('two')->status)->toBe('fresh');
});

it('refuses an outcome it does not know', function () {
    $this->actingAs($this->alice)
        ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
            'action' => 'mark_reviewed',
            'selections' => ['one'],
            'values' => ['outcome' => 'looked fine'],
        ])
        ->assertStatus(422);

    expect(Review::query()->count())->toBe(0);
});

it('needs the outcome', function () {
    $this->actingAs($this->alice)
        ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
            'action' => 'mark_reviewed',
            'selections' => ['one'],
            'values' => ['note' => 'but no outcome'],
        ])
        ->assertStatus(422);
});

it('is not for someone who cannot edit the entry', function () {
    $reader = User::make()->id('reader')->email('reader@example.edu')->save();

    $this->actingAs($reader)
        ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
            'action' => 'mark_reviewed',
            'selections' => ['one'],
            'values' => ['outcome' => 'current'],
        ])
        ->assertStatus(403);

    expect(Review::query()->count())->toBe(0);
});

it('does not warn about unsaved edits, because it does not touch them', function () {
    expect(Action::get('mark_reviewed')->bypassesDirtyWarning())->toBeTrue();
});
