<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Fieldtypes\LifecycleStatus;
use Statamic\Auth\User as StatamicUser;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

beforeEach(function () {
    Collection::make('pages')->save();
});

it('puts the three fields on an entry blueprint without anybody editing it', function () {
    // The collection has no blueprint of its own, so this is Statamic's
    // fallback — the case for most collections on most sites, and the case
    // the addon has to get right without a setup step.
    $blueprint = Entry::make()->collection('pages')->blueprint();

    expect($blueprint->hasField(LifecycleFields::CONTENT_OWNER))->toBeTrue()
        ->and($blueprint->hasField(LifecycleFields::REVIEW_INTERVAL))->toBeTrue()
        ->and($blueprint->hasField(LifecycleFields::REVIEW_BY))->toBeTrue();

    // In the sidebar, after Statamic's own slug and date, the way Statamic
    // adds its own fields — not a new tab somebody has to go looking for.
    $sidebar = $blueprint->tabs()->get('sidebar')->fields()->all()->keys()->all();

    expect($sidebar)->toContain('slug')
        ->and(array_slice($sidebar, -4))->toBe([
            LifecycleFields::CONTENT_OWNER,
            LifecycleFields::REVIEW_INTERVAL,
            LifecycleFields::REVIEW_BY,
            // And under them, the panel that says what they add up to.
            LifecycleStatus::HANDLE,
        ]);
});

it('gives each field the type the value needs', function () {
    $blueprint = Entry::make()->collection('pages')->blueprint();

    $owner = $blueprint->field(LifecycleFields::CONTENT_OWNER);
    $interval = $blueprint->field(LifecycleFields::REVIEW_INTERVAL);
    $reviewBy = $blueprint->field(LifecycleFields::REVIEW_BY);

    // One person. Groups are in the schema (owner_type) for later; the v1
    // field offers users, as spec §11 recommends.
    expect($owner->type())->toBe('users')
        ->and($owner->get('max_items'))->toBe(1);

    // Named intervals, clearable so "empty" can mean "inherit the default".
    expect($interval->type())->toBe('select')
        ->and($interval->get('clearable'))->toBeTrue()
        ->and($interval->get('options'))->toBe(LifecycleFields::intervalOptions());

    // A date, not a datetime: "review before the March board meeting" is a
    // day, and asking for a time would be asking for a lie.
    expect($reviewBy->type())->toBe('date')
        ->and($reviewBy->get('time_enabled'))->toBeFalse()
        ->and($reviewBy->get('mode'))->toBe('single');
});

it('keeps owner and cadence per site, and stays out of every listing until asked', function () {
    $blueprint = Entry::make()->collection('pages')->blueprint();

    foreach (array_keys(LifecycleFields::definitions()) as $handle) {
        $field = $blueprint->field($handle);

        // A translation can be stale while the original is current (spec §11).
        expect($field->get('localizable'))->toBeTrue("$handle should be localizable");

        // Available as a column and a filter — the bulk assignment in Phase 4
        // needs a filtered selection — but hidden until somebody turns it on.
        expect($field->get('listable'))->toBe('hidden', "$handle should be a hidden listing column");
    }
});

it('speaks plainly to the person filling it in', function () {
    foreach (LifecycleFields::definitions() as $handle => $config) {
        expect($config['display'] ?? '')->not->toBe('', "$handle has no label")
            ->and($config['instructions'] ?? '')->not->toBe('', "$handle has no instructions");

        // No jargon from the schema in the editor's face.
        expect($config['display'])->not->toContain('_');
    }
});

it('leaves a field the site declared where the site put it, and lets its config win', function () {
    // A site that wants the owner on the main tab, labelled its own way,
    // declares the handle there. The addon fills in only what was not said.
    $custom = Blueprint::make('article')->setNamespace('collections.pages')->setContents([
        'tabs' => [
            'main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => LifecycleFields::CONTENT_OWNER, 'field' => ['display' => 'Who owns this', 'max_items' => 1]],
            ]]]],
        ],
    ]);

    Blueprint::partialMock();
    Blueprint::shouldReceive('in')->with('collections/pages')->andReturn(collect(['article' => $custom]));

    $blueprint = Entry::make()->collection('pages')->blueprint();

    expect($blueprint->handle())->toBe('article');

    $main = $blueprint->tabs()->get('main')->fields()->all()->keys()->all();
    $sidebar = $blueprint->tabs()->get('sidebar')->fields()->all()->keys()->all();

    expect($main)->toBe(['title', LifecycleFields::CONTENT_OWNER])
        ->and($sidebar)->not->toContain(LifecycleFields::CONTENT_OWNER)
        ->and($sidebar)->toContain(LifecycleFields::REVIEW_INTERVAL)
        ->and($sidebar)->toContain(LifecycleFields::REVIEW_BY);

    $owner = $blueprint->field(LifecycleFields::CONTENT_OWNER);

    expect($owner->display())->toBe('Who owns this')    // theirs
        ->and($owner->type())->toBe('users')             // ours, they left it unsaid
        ->and($owner->get('listable'))->toBe('hidden');  // ours
});

it('stores what the editor chose in the entry itself', function () {
    // Spec §3: authored intent lives in the blueprint, in content, in git.
    // Not in the addon's tables, which hold only what was computed from it.
    $owner = User::make()->id('owner-1')->email('owner@example.edu')->save();

    $entry = Entry::make()->collection('pages')->slug('about')->data(['title' => 'About']);
    $entry
        ->set(LifecycleFields::CONTENT_OWNER, 'owner-1')
        ->set(LifecycleFields::REVIEW_INTERVAL, 'quarterly')
        ->set(LifecycleFields::REVIEW_BY, '2027-03-01')
        ->save();

    // In the file, where a reviewer reading a diff would see it.
    expect($entry->fileContents())
        ->toContain('content_owner: owner-1')
        ->toContain('review_interval: quarterly')
        ->toContain("review_by: '2027-03-01'");

    // And, read back through the blueprint, the owner is a person and the
    // interval is a word with a label — not an ID and a code.
    expect($entry->augmentedValue(LifecycleFields::CONTENT_OWNER)->value())->toBeInstanceOf(StatamicUser::class)
        ->and($entry->augmentedValue(LifecycleFields::CONTENT_OWNER)->value()->id())->toBe('owner-1')
        ->and($entry->augmentedValue(LifecycleFields::REVIEW_INTERVAL)->value()->label())->toBe('Quarterly');
});

it('maps every interval an editor can pick to a number of days, and nothing else', function () {
    foreach (LifecycleFields::intervalOptions() as $name => $label) {
        expect(LifecycleFields::intervalDays($name))->toBeInt()->toBeGreaterThan(0)
            ->and($label)->not->toBe('');
    }

    // Longer names mean longer intervals, or the picker is lying.
    $days = array_map(fn (array $i): int => $i['days'], LifecycleFields::INTERVALS);
    expect(array_values($days))->toBe(array_values(collect($days)->sort()->all()));

    // A name this addon does not know is a question for the computation, not
    // an exception here.
    expect(LifecycleFields::intervalDays('fortnightly'))->toBeNull();
});
