<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Fieldtypes\LifecycleStatus;
use Bpmore\StatamicLifecycle\Panel\SidebarPanel;
use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Bpmore\StatamicLifecycle\Reviews\RecordReview;
use Bpmore\StatamicLifecycle\State\IntervalSource;
use Bpmore\StatamicLifecycle\State\NextReviewSource;
use Bpmore\StatamicLifecycle\State\OwnerSource;
use Bpmore\StatamicLifecycle\State\Status;
use Bpmore\StatamicLifecycle\StateRecomputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    Collection::make('pages')->save();
    $this->alice = User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->makeSuper()->save();

    $this->entry = Entry::make()->id('page')->collection('pages')->slug('page')->published(true)->data([
        'title' => 'Page',
        'updated_at' => (new DateTimeImmutable('2026-03-01 10:00'))->getTimestamp(),
        'updated_by' => 'alice',
        LifecycleFields::REVIEW_INTERVAL => 'quarterly',
    ]);
    $this->entry->save();
});

afterEach(fn () => Carbon::setTestNow());

describe('the payload', function () {
    it('says where the page is, why, who owns it, and who last confirmed it', function () {
        app(StateRecomputer::class)->recompute(['page'], new DateTimeImmutable('2026-09-11'));
        app(RecordReview::class)->record($this->entry, 'alice', Outcome::Updated, 'Refreshed.', new DateTimeImmutable('2026-08-01 09:00'));

        $this->actingAs($this->alice);
        $panel = app(SidebarPanel::class)->for($this->entry);

        expect($panel['installed'])->toBeTrue()
            ->and($panel['entry'])->toBe(['id' => 'page', 'published' => true])
            ->and($panel['can_attest'])->toBeTrue()
            ->and($panel['outcomes'])->toBe(Outcome::options())
            ->and($panel['urls']['attest'])->toContain('/collections/pages/entries/actions')
            ->and($panel['urls']['refresh'])->toContain('/lifecycle/entries/page/status');

        $state = $panel['state'];

        // Reviewed 1 August, quarterly: due 30 October, 49 days off on
        // 11 September, so fresh.
        expect($state['status'])->toBe('fresh')
            ->and($state['status_label'])->toBe('Fresh')
            ->and($state['colour'])->toBe('green')
            ->and($state['next_review_at'])->toStartWith('2026-10-30')
            ->and($state['next_review_source'])->toBe('last_review')
            ->and($state['next_review_reason'])->toBe('the last review plus the interval')
            ->and($state['interval_days'])->toBe(90)
            ->and($state['interval_label'])->toBe('Quarterly')
            ->and($state['interval_reason'])->toBe('set on this page')
            ->and($state['owner'])->toBe(['id' => 'alice', 'name' => 'Alice Ahmed', 'exists' => true, 'source' => 'inferred', 'source_label' => 'a guess, from the last person to edit it'])
            ->and($state['last_reviewed_at'])->toStartWith('2026-08-01T09:00')
            ->and($state['last_reviewed_by'])->toBe(['id' => 'alice', 'name' => 'Alice Ahmed', 'exists' => true])
            ->and($state['baselined'])->toBeFalse();

        expect($panel['timeline']['count'])->toBe(1)
            ->and($panel['timeline']['latest']['outcome_label'])->toBe('I updated it')
            ->and($panel['timeline']['latest']['note'])->toBe('Refreshed.');
    });

    it('has no state for a page the recompute has not reached, and says so', function () {
        $panel = app(SidebarPanel::class)->for($this->entry);

        expect($panel['state'])->toBeNull()
            ->and($panel['entry']['published'])->toBeTrue()
            ->and($panel['timeline']['count'])->toBe(0);
    });

    it('has no state for a draft', function () {
        $draft = Entry::make()->id('draft')->collection('pages')->slug('draft')->published(false)->data(['title' => 'Draft']);
        $draft->save();

        $panel = app(SidebarPanel::class)->for($draft);

        expect($panel['state'])->toBeNull()
            ->and($panel['entry']['published'])->toBeFalse();
    });

    it('has nothing to say about a page that has not been saved yet', function () {
        $panel = app(SidebarPanel::class)->for(Entry::make()->collection('pages'));

        expect($panel['entry'])->toBeNull()
            ->and($panel['state'])->toBeNull()
            ->and($panel['timeline'])->toBeNull()
            ->and($panel['can_attest'])->toBeFalse()
            ->and($panel['urls'])->toBeNull();
    });

    it('says when Lifecycle is not installed rather than failing', function () {
        config([
            'lifecycle.connection' => 'lifecycle_absent',
            'database.connections.lifecycle_absent' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/absent-'.uniqid().'.sqlite', 'prefix' => ''],
        ]);

        $panel = app(SidebarPanel::class)->for($this->entry);

        expect($panel['installed'])->toBeFalse()
            ->and($panel['state'])->toBeNull()
            ->and($panel['timeline'])->toBeNull();
    });

    it('names a person who has left by their stored ID, never blank', function () {
        Entry::find('page')->set('updated_by', 'someone-who-left')->save();
        app(StateRecomputer::class)->recompute(['page'], new DateTimeImmutable('2026-09-11'));
        app(RecordReview::class)->record($this->entry, 'someone-who-left', Outcome::Current);

        $state = app(SidebarPanel::class)->for($this->entry)['state'];

        // Not inferred: a last author who is gone is not an owner.
        expect($state['owner'])->toBeNull()
            ->and($state['last_reviewed_by'])->toBe(['id' => 'someone-who-left', 'name' => 'someone-who-left', 'exists' => false]);
    });

    it('labels every status, every reason, and every interval', function () {
        foreach (Status::cases() as $status) {
            expect(SidebarPanel::statusLabel($status))->not->toBe('')
                ->and(SidebarPanel::statusColour($status))->toBeIn(['green', 'amber', 'red', 'default']);
        }
        foreach (NextReviewSource::cases() as $source) {
            expect(SidebarPanel::nextReviewReason($source))->not->toBe('');
        }
        foreach (IntervalSource::cases() as $source) {
            expect(SidebarPanel::intervalReason($source))->not->toBe('');
        }
        foreach (OwnerSource::cases() as $source) {
            expect(SidebarPanel::ownerSourceLabel($source))->not->toBe('');
        }

        expect(SidebarPanel::intervalLabel(90))->toBe('Quarterly')
            ->and(SidebarPanel::intervalLabel(365))->toBe('Yearly')
            ->and(SidebarPanel::intervalLabel(45))->toBe('Every 45 days')
            ->and(SidebarPanel::intervalLabel(1))->toBe('Every 1 day');
    });
});

describe('the fieldtype', function () {
    it('sits in the sidebar with computed visibility, so a save never writes it', function () {
        $blueprint = $this->entry->blueprint();
        $field = $blueprint->field(LifecycleStatus::HANDLE);

        expect($blueprint->tabs()->get('sidebar')->fields()->all()->keys()->all())->toContain(LifecycleStatus::HANDLE)
            ->and($field->type())->toBe('lifecycle_status')
            ->and($field->visibility())->toBe('computed')
            ->and($field->isListable())->toBeFalse();

        // What a save would write: the panel is not in it.
        $values = $blueprint->fields()->addValues(['title' => 'Page', LifecycleStatus::HANDLE => ['anything' => 'at all']])->process()->values();

        expect($values->has(LifecycleStatus::HANDLE))->toBeFalse();
    });

    it('carries the panel as its value on the publish form', function () {
        app(StateRecomputer::class)->recompute(['page'], new DateTimeImmutable('2026-09-11'));

        $this->actingAs($this->alice)
            ->get(cp_route('collections.entries.edit', ['collection' => 'pages', 'entry' => 'page']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('values.'.LifecycleStatus::HANDLE.'.entry.id', 'page')
                ->where('values.'.LifecycleStatus::HANDLE.'.state.status', 'expired')
                ->where('values.'.LifecycleStatus::HANDLE.'.can_attest', true)
                ->has('values.'.LifecycleStatus::HANDLE.'.timeline'));
    });

    it('does not reach the file when the entry is saved through the control panel', function () {
        $path = $this->entry->path();

        $this->actingAs($this->alice)
            ->patchJson(cp_route('collections.entries.update', ['collection' => 'pages', 'entry' => 'page']), [
                'title' => 'Page, edited',
                'slug' => 'page',
                LifecycleStatus::HANDLE => ['smuggled' => true],
                '_localized' => [],
            ])
            ->assertOk();

        $contents = (string) file_get_contents($path);

        expect($contents)->toContain('title: \'Page, edited\'')
            ->and($contents)->not->toContain('lifecycle_status')
            ->and($contents)->not->toContain('smuggled');
    });

    it('processes to nothing, whatever it is handed', function () {
        $fieldtype = new LifecycleStatus;

        expect($fieldtype->process(['anything' => 'at all']))->toBeNull()
            ->and($fieldtype->augment(['anything']))->toBeNull()
            ->and($fieldtype->preProcessIndex(['anything']))->toBeNull();
    });

    it('uses an icon that exists in the control panel', function () {
        // A missing icon name renders as nothing at all — a blank square, no
        // error anywhere.
        $icons = dirname((new ReflectionClass(Statamic\Statamic::class))->getFileName(), 2).'/resources/svg/icons';
        $icon = (new ReflectionProperty(LifecycleStatus::class, 'icon'))->getDefaultValue();

        expect(is_file("{$icons}/{$icon}.svg"))->toBeTrue("'{$icon}' is not in Statamic's icon set");
    });
});

describe('the refresh route', function () {
    it('returns the panel, fresh', function () {
        app(StateRecomputer::class)->recompute(['page'], new DateTimeImmutable('2026-09-11'));

        $this->actingAs($this->alice)
            ->getJson(cp_route('lifecycle.entries.status', ['entry' => 'page']))
            ->assertOk()
            ->assertJsonPath('entry.id', 'page')
            ->assertJsonPath('state.status', 'expired')
            ->assertJsonPath('timeline.count', 0);
    });

    it('is 404 for an entry that does not exist', function () {
        $this->actingAs($this->alice)
            ->getJson(cp_route('lifecycle.entries.status', ['entry' => 'nope']))
            ->assertNotFound();
    });

    it('needs permission to view the entry', function () {
        $stranger = User::make()->id('stranger')->email('stranger@example.edu')->save();

        $this->actingAs($stranger)
            ->getJson(cp_route('lifecycle.entries.status', ['entry' => 'page']))
            ->assertForbidden();
    });

    it('needs a signed-in control panel user at all', function () {
        $this->getJson(cp_route('lifecycle.entries.status', ['entry' => 'page']))
            ->assertStatus(401);
    });
});

it('ships the built panel the control panel will load', function () {
    $manifest = json_decode((string) file_get_contents(repoPath('resources/dist/build/manifest.json')), true);
    $bundle = (string) file_get_contents(repoPath('resources/dist/build/'.$manifest['resources/js/cp.js']['file']));

    expect($bundle)->toContain('lifecycle_status-fieldtype')
        ->and($bundle)->toContain('mark_reviewed');
});
