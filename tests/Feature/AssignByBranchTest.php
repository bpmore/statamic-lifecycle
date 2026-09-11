<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Assignment\OwnerAssigner;
use Bpmore\StatamicLifecycle\Assignment\Selectors\Branch;
use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    User::make()->id('cardio-lead')->email('cardio@example.edu')->data(['name' => 'Dr Chen'])->save();
    User::make()->id('editor')->email('editor@example.edu')->save();

    // A site with a tree: home, cardiology (team, clinics (north)), oncology,
    // and a flat collection alongside.
    $pages = Collection::make('pages')->routes('{parent_uri}/{slug}')->structureContents(['root' => true]);
    $pages->save();
    Collection::make('news')->routes('/news/{slug}')->save();

    foreach (['home', 'cardiology', 'team', 'clinics', 'north', 'oncology'] as $slug) {
        Entry::make()->id($slug)->collection('pages')->slug($slug)->published(true)->data([
            'title' => ucfirst($slug),
            'updated_by' => 'editor',
            'updated_at' => (new DateTimeImmutable('2026-01-01'))->getTimestamp(),
        ])->save();
    }
    Entry::make()->id('story')->collection('news')->slug('story')->published(true)->data(['title' => 'Story'])->save();

    $pages->structure()->in('default')->tree([
        ['entry' => 'home'],
        ['entry' => 'cardiology', 'children' => [
            ['entry' => 'team'],
            ['entry' => 'clinics', 'children' => [['entry' => 'north']]],
        ]],
        ['entry' => 'oncology'],
    ])->save();
});

afterEach(fn () => Carbon::setTestNow());

describe('the branch selector', function () {
    it('is the page at the URI and everything beneath it', function () {
        $selection = (new Branch)->select('/cardiology');

        expect($selection->entries->map->id()->all())->toBe(['cardiology', 'team', 'clinics', 'north'])
            ->and($selection->description)->toBe('everything under /cardiology in the default site')
            ->and($selection->count())->toBe(4);
    });

    it('is just the page when it has nothing beneath it', function () {
        expect((new Branch)->select('oncology')->entries->map->id()->all())->toBe(['oncology']);
    });

    it('refuses a URI with no page', function () {
        expect(fn () => (new Branch)->select('/nowhere'))
            ->toThrow(InvalidArgumentException::class, 'There is no page at [/nowhere]');
    });

    it('refuses an entry in a collection with no structure, and says what to do instead', function () {
        expect(fn () => (new Branch)->select('/news/story'))
            ->toThrow(InvalidArgumentException::class, 'Assign by collection instead');
    });
});

describe('the assigner', function () {
    it('writes the owner into each entry, saving only what changes, and recomputes their rows', function () {
        Entry::find('team')->set(LifecycleFields::CONTENT_OWNER, 'cardio-lead')->save();
        Event::fake([EntrySaved::class]);

        $result = app(OwnerAssigner::class)->assign((new Branch)->select('/cardiology')->entries, 'cardio-lead');

        expect($result->assigned)->toBe(['cardiology', 'clinics', 'north'])
            ->and($result->unchanged)->toBe(['team'])
            ->and($result->dryRun)->toBeFalse();

        // Three saves, not four: team already had this owner.
        Event::assertDispatchedTimes(EntrySaved::class, 3);

        foreach (['cardiology', 'team', 'clinics', 'north'] as $id) {
            expect(Entry::find($id)->get(LifecycleFields::CONTENT_OWNER))->toBe('cardio-lead');
        }

        // The three that changed were recomputed and now say "chosen"; the
        // one that did not change was not touched, rows included.
        foreach (['cardiology', 'clinics', 'north'] as $id) {
            expect(EntryState::query()->find($id)->owner_source)->toBe('assigned');
        }
        expect(EntryState::query()->find('team'))->toBeNull();

        // It is in the file, where an editor's decision belongs.
        expect(file_get_contents(Entry::find('north')->path()))->toContain('content_owner: cardio-lead');

        // And nothing outside the branch was touched.
        expect(Entry::find('oncology')->get(LifecycleFields::CONTENT_OWNER))->toBeNull()
            ->and(Entry::find('home')->get(LifecycleFields::CONTENT_OWNER))->toBeNull();
    });

    it('does not make whoever ran it the last author', function () {
        // If it did, the assigner would become the inferred owner of every
        // page it did not assign — and this page's actual editor would vanish.
        app(OwnerAssigner::class)->assign((new Branch)->select('/cardiology')->entries, 'cardio-lead');

        expect(Entry::find('north')->get('updated_by'))->toBe('editor')
            ->and(Entry::find('north')->get('updated_at'))->toBe((new DateTimeImmutable('2026-01-01'))->getTimestamp());
    });

    it('writes nothing on a dry run, but says what it would do', function () {
        Event::fake([EntrySaved::class]);

        $result = app(OwnerAssigner::class)->assign((new Branch)->select('/cardiology')->entries, 'cardio-lead', dryRun: true);

        expect($result->assigned)->toHaveCount(4)
            ->and($result->dryRun)->toBeTrue();
        Event::assertNotDispatched(EntrySaved::class);
        expect(Entry::find('cardiology')->get(LifecycleFields::CONTENT_OWNER))->toBeNull()
            ->and(EntryState::query()->count())->toBe(0);
    });

    it('leaves a localisation synced to an origin that already has the owner', function () {
        Site::setSites([
            'default' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
            'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => '/fr/'],
        ]);
        Collection::make('news')->routes('/news/{slug}')->sites(['default', 'fr'])->save();

        Entry::find('story')->set(LifecycleFields::CONTENT_OWNER, 'cardio-lead')->save();
        Entry::make()->id('story-fr')->collection('news')->locale('fr')->origin('story')->slug('histoire')->published(true)->data(['title' => 'Histoire'])->save();

        $result = app(OwnerAssigner::class)->assign(collect([Entry::find('story-fr')]), 'cardio-lead');

        // Inherited already: not written, so still synced.
        expect($result->unchanged)->toBe(['story-fr'])
            ->and(Entry::find('story-fr')->get(LifecycleFields::CONTENT_OWNER))->toBeNull()
            ->and(Entry::find('story-fr')->value(LifecycleFields::CONTENT_OWNER))->toBe('cardio-lead');

        // A different owner for the translation is its own decision, and is written.
        app(OwnerAssigner::class)->assign(collect([Entry::find('story-fr')]), 'editor');

        expect(Entry::find('story-fr')->get(LifecycleFields::CONTENT_OWNER))->toBe('editor')
            ->and(Entry::find('story')->get(LifecycleFields::CONTENT_OWNER))->toBe('cardio-lead');
    });
});

describe('the command', function () {
    it('needs an owner who exists, and a selection', function () {
        $this->artisan('lifecycle:assign', ['--branch' => '/cardiology'])
            ->expectsOutputToContain('Say who: --owner=<user ID or email>.')
            ->assertExitCode(1);

        $this->artisan('lifecycle:assign', ['--branch' => '/cardiology', '--owner' => 'nobody@example.edu'])
            ->expectsOutputToContain('There is no user with the ID or email [nobody@example.edu].')
            ->assertExitCode(1);

        $this->artisan('lifecycle:assign', ['--owner' => 'cardio-lead'])
            ->expectsOutputToContain('Say which entries: --branch=/some/page, --collection=, --blueprint=, --author=, or --term=taxonomy::slug.')
            ->assertExitCode(1);

        $this->artisan('lifecycle:assign', ['--owner' => 'cardio-lead', '--branch' => '/nowhere'])
            ->expectsOutputToContain('There is no page at [/nowhere] in the [default] site.')
            ->assertExitCode(1);

        $this->artisan('lifecycle:assign', ['--owner' => 'cardio-lead', '--branch' => '/cardiology', '--site' => 'mars'])
            ->expectsOutputToContain('There is no site called [mars].')
            ->assertExitCode(1);
    });

    it('says what it would do on a dry run, and writes nothing', function () {
        $this->artisan('lifecycle:assign', ['--owner' => 'cardio@example.edu', '--branch' => '/cardiology', '--dry-run' => true])
            ->expectsOutputToContain('Everything under /cardiology in the default site: 4 entries; 4 would get Dr Chen as owner, 0 already have them.')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertExitCode(0);

        expect(Entry::find('cardiology')->get(LifecycleFields::CONTENT_OWNER))->toBeNull();
    });

    it('asks before writing, and does not write on no', function () {
        $this->artisan('lifecycle:assign', ['--owner' => 'cardio-lead', '--branch' => '/cardiology'])
            ->expectsConfirmation('Write content_owner on 4 entries? Each is saved like an edit.', 'no')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertExitCode(1);

        expect(Entry::find('cardiology')->get(LifecycleFields::CONTENT_OWNER))->toBeNull();
    });

    it('assigns the branch on yes, or on --force, by ID or by email', function () {
        $this->artisan('lifecycle:assign', ['--owner' => 'cardio-lead', '--branch' => '/cardiology'])
            ->expectsConfirmation('Write content_owner on 4 entries? Each is saved like an edit.', 'yes')
            ->expectsOutputToContain('Assigned Dr Chen to 4 entries, and recomputed their review state.')
            ->assertExitCode(0);

        foreach (['cardiology', 'team', 'clinics', 'north'] as $id) {
            expect(Entry::find($id)->get(LifecycleFields::CONTENT_OWNER))->toBe('cardio-lead')
                ->and(EntryState::query()->find($id)->owner_source)->toBe('assigned');
        }

        // Again by email, forced: nothing left to change.
        $this->artisan('lifecycle:assign', ['--owner' => 'cardio@example.edu', '--branch' => '/cardiology', '--force' => true])
            ->expectsOutputToContain('4 entries; 0 would get Dr Chen as owner, 4 already have them.')
            ->expectsOutputToContain('Nothing to change.')
            ->assertExitCode(0);
    });
});
