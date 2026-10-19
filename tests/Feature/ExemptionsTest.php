<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Actions\ClearExemption;
use Bpmore\StatamicLifecycle\Actions\Exempt;
use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Exemptions\ExemptionWriter;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Notifications\Reminders;
use Bpmore\StatamicLifecycle\Reporting\Overview;
use Bpmore\StatamicLifecycle\StateRecomputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Action;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Exemptions (spec §3's `exempt` status, §8's bulk actions): a page taken
 * out of the review cycle. The decision lives in the entry, like the owner,
 * and the two actions write it the way Assign owner writes the owner.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 15:00:00');
    config(['statamic.editions.pro' => true]);
    Collection::make('pages')->save();
    $this->editor = User::make()->id('editor')->email('editor@example.edu')->makeSuper()->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();

    // Three pages, all long overdue, owned by Alice.
    foreach (['one', 'two', 'three'] as $id) {
        Entry::make()->id($id)->collection('pages')->slug($id)->published(true)->data([
            'title' => $id,
            'content_owner' => 'alice',
            'updated_by' => 'editor',
            'updated_at' => (new DateTimeImmutable('2024-01-01'))->getTimestamp(),
        ])->save();
    }
    app(StateRecomputer::class)->recompute(['one', 'two', 'three'], Carbon::now()->toImmutable());
});

afterEach(fn () => Carbon::setTestNow());

function runExemptionAction($test, string $action, array $selections)
{
    return $test->actingAs($test->editor)
        ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
            'action' => $action,
            'selections' => $selections,
            'values' => [],
        ]);
}

it('is a toggle on every entry blueprint, next to the other three', function () {
    $field = Entry::make()->collection('pages')->blueprint()->field(LifecycleFields::REVIEW_EXEMPT);

    expect($field->type())->toBe('toggle')
        ->and($field->get('localizable'))->toBeTrue()
        ->and($field->get('listable'))->toBe('hidden')
        ->and($field->display())->toBe('Exempt from review');
});

it('reads as exempt however the value was stored, and not at all when nothing was', function () {
    expect(LifecycleFields::isExemptValue(true))->toBeTrue()
        ->and(LifecycleFields::isExemptValue('true'))->toBeTrue()
        ->and(LifecycleFields::isExemptValue(1))->toBeTrue()
        ->and(LifecycleFields::isExemptValue(false))->toBeFalse()
        ->and(LifecycleFields::isExemptValue('false'))->toBeFalse()
        ->and(LifecycleFields::isExemptValue(null))->toBeFalse()
        ->and(LifecycleFields::isExemptValue(''))->toBeFalse();
});

it('offers Exempt on pages that are not exempt and Clear exemption on pages that are', function () {
    $exempt = Action::get('exempt');
    $clear = Action::get('clear_exemption');

    expect($exempt)->toBeInstanceOf(Exempt::class)
        ->and($clear)->toBeInstanceOf(ClearExemption::class)
        ->and($exempt->visibleTo(Entry::find('one')))->toBeTrue()
        ->and($clear->visibleTo(Entry::find('one')))->toBeFalse()
        ->and($exempt->visibleTo(Collection::find('pages')))->toBeFalse();

    Entry::find('one')->set(LifecycleFields::REVIEW_EXEMPT, true)->save();

    expect($exempt->visibleTo(Entry::find('one')))->toBeFalse()
        ->and($clear->visibleTo(Entry::find('one')))->toBeTrue();

    // In bulk, offered when any of the selection applies: a mixed selection
    // should not have to be filtered first.
    $mixed = collect([Entry::find('one'), Entry::find('two')]);

    expect($exempt->visibleToBulk($mixed))->toBeTrue()
        ->and($clear->visibleToBulk($mixed))->toBeTrue()
        ->and($clear->visibleToBulk(collect([Entry::find('two'), Entry::find('three')])))->toBeFalse();
});

it('takes the pages out of the cycle: the field in the entry, the row exempt with no date, nothing owed', function () {
    expect(EntryState::query()->find('one')->status)->toBe('expired');

    runExemptionAction($this, 'exempt', ['one', 'two'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Exempted 2 pages from review.');

    expect(file_get_contents(Entry::find('one')->path()))->toContain('review_exempt: true')
        ->and(Entry::find('three')->get(LifecycleFields::REVIEW_EXEMPT))->toBeNull()
        // Whoever ran it is not now the last author.
        ->and(Entry::find('one')->get('updated_by'))->toBe('editor');

    $row = EntryState::query()->find('one');

    expect($row->status)->toBe('exempt')
        ->and($row->next_review_at)->toBeNull()
        ->and($row->interval_days)->toBeNull()
        // Still owned: somebody still answers for the page.
        ->and($row->owner_id)->toBe('alice')
        ->and(EntryState::query()->find('three')->status)->toBe('expired');

    // No reminder for an exempt page, however overdue it was.
    $owed = array_map(fn ($r) => $r->entryId, app(Reminders::class)->owed(new DateTimeImmutable('2026-10-19')));
    expect($owed)->toBe(['three']);

    // And the dashboard counts it as exempt, not as never reviewed.
    expect((new Overview)->statuses()['exempt'])->toBe(2)
        ->and((new Overview)->attention()['never_reviewed'])->toBe(1);
});

it('puts them back: the line gone from the entry, the date worked out again', function () {
    runExemptionAction($this, 'exempt', ['one']);
    expect(EntryState::query()->find('one')->status)->toBe('exempt');

    runExemptionAction($this, 'clear_exemption', ['one'])
        ->assertOk()
        ->assertJsonPath('message', 'Cleared the exemption on 1 page; it is back in the review cycle.');

    expect(file_get_contents(Entry::find('one')->path()))->not->toContain('review_exempt')
        ->and(Entry::find('one')->get('updated_by'))->toBe('editor');

    // Last modified January 2024, yearly: overdue, honestly.
    $row = EntryState::query()->find('one');

    expect($row->status)->toBe('expired')
        ->and($row->next_review_at->format('Y-m-d'))->toBe('2024-12-31');
});

it('says when some, or all, were already as asked', function () {
    runExemptionAction($this, 'exempt', ['one']);

    runExemptionAction($this, 'exempt', ['one', 'two'])
        ->assertJsonPath('message', 'Exempted 1 page from review. 1 already was.');

    runExemptionAction($this, 'exempt', ['one', 'two'])
        ->assertJsonPath('message', 'All 2 pages are already exempt from review.');

    runExemptionAction($this, 'clear_exemption', ['one', 'three'])
        ->assertJsonPath('message', 'Cleared the exemption on 1 page; it is back in the review cycle. 1 was not exempt.');

    runExemptionAction($this, 'clear_exemption', ['three'])
        ->assertJsonPath('message', 'That page was not exempt.');
});

it('writes nothing on a dry run, and reports what it would do', function () {
    $change = app(ExemptionWriter::class)->set([Entry::find('one'), Entry::find('two')], true, dryRun: true);

    expect($change->changed)->toBe(['one', 'two'])
        ->and($change->unchanged)->toBe([])
        ->and($change->dryRun)->toBeTrue()
        ->and(Entry::find('one')->get(LifecycleFields::REVIEW_EXEMPT))->toBeNull()
        ->and(EntryState::query()->find('one')->status)->toBe('expired');
});

it('lets a translation inherit the exemption, and clears it on the translation alone by saying so', function () {
    Site::setSites([
        'default' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
        'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => '/fr/'],
    ]);
    Collection::make('pages')->sites(['default', 'fr'])->save();

    Entry::make()->id('en')->collection('pages')->locale('default')->slug('about')->published(true)->data(['title' => 'About'])->save();
    Entry::make()->id('fr')->collection('pages')->locale('fr')->origin('en')->slug('a-propos')->published(true)->data(['title' => 'À propos'])->save();

    app(ExemptionWriter::class)->set([Entry::find('en')], true);

    expect(ExemptionWriter::isExempt(Entry::find('fr')))->toBeTrue()
        ->and(Entry::find('fr')->get(LifecycleFields::REVIEW_EXEMPT))->toBeNull();

    // Clearing on the translation cannot remove a line it does not have; it
    // has to say "not this one" and leave the original exempt.
    app(ExemptionWriter::class)->set([Entry::find('fr')], false);

    expect(Entry::find('fr')->get(LifecycleFields::REVIEW_EXEMPT))->toBeFalse()
        ->and(ExemptionWriter::isExempt(Entry::find('fr')))->toBeFalse()
        ->and(ExemptionWriter::isExempt(Entry::find('en')))->toBeTrue();
});

it('is not for someone who cannot edit the entries', function () {
    $reader = User::make()->id('reader')->email('reader@example.edu')->save();

    $this->actingAs($reader)
        ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
            'action' => 'exempt',
            'selections' => ['one'],
            'values' => [],
        ])
        ->assertStatus(403);

    expect(Entry::find('one')->get(LifecycleFields::REVIEW_EXEMPT))->toBeNull();
});

it('warns that it writes the entry, and keeps the unsaved-changes warning', function () {
    foreach (['exempt', 'clear_exemption'] as $handle) {
        $action = Action::get($handle);

        expect($action->confirmationText())->toContain('writes the entry')
            ->and($action->bypassesDirtyWarning())->toBeFalse();
    }
});
