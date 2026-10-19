<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Reporting\EntryListing;
use Bpmore\StatamicLifecycle\Reporting\Overview;
use Bpmore\StatamicLifecycle\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Statamic\CP\Navigation\Nav as StatamicNav;
use Statamic\Facades\Collection;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-12 10:00:00');
    config(['statamic.editions.pro' => true]);
    Collection::make('pages')->title('Pages')->routes('/{slug}')->save();
    Collection::make('news')->title('News')->routes('/news/{slug}')->save();
    $this->alice = User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->makeSuper()->save();
    User::make()->id('bob')->email('bob@example.edu')->data(['name' => 'Bob Baird'])->save();

    $row = function (string $id, string $collection, string $status, ?string $owner, ?string $source, ?string $reviewed = null, string $site = 'default') {
        Entry::make()->id($id)->collection($collection)->slug($id)->published(true)->data(['title' => ucfirst($id)])->save();
        EntryState::query()->insert([
            'entry_id' => $id, 'site' => $site, 'collection' => $collection,
            'owner_id' => $owner, 'owner_type' => $owner === null ? null : 'user', 'owner_source' => $source,
            'interval_days' => 365, 'interval_source' => 'global',
            'last_reviewed_at' => $reviewed, 'last_reviewed_by' => $reviewed === null ? null : 'bob',
            'next_review_at' => match ($status) {
                'overdue' => '2026-09-01 00:00:00', 'expired' => '2026-07-01 00:00:00', 'due_soon' => '2026-09-20 00:00:00', 'exempt' => null, default => '2027-06-01 00:00:00'
            },
            'next_review_source' => $status === 'exempt' ? null : 'last_modified',
            'status' => $status, 'baselined' => false, 'computed_at' => '2026-09-12 00:00:00',
        ]);
    };

    $row('handbook', 'pages', 'overdue', 'alice', 'assigned');
    $row('policy', 'pages', 'expired', 'alice', 'inferred');
    $row('about', 'pages', 'fresh', 'bob', 'assigned', '2026-06-01 09:00:00');
    $row('story', 'news', 'due_soon', 'bob', 'fallback');
    $row('orphan', 'news', 'fresh', null, null);
    $row('legal', 'news', 'exempt', 'alice', 'assigned');
});

afterEach(fn () => Carbon::setTestNow());

describe('the numbers', function () {
    it('counts every state, and what needs attention beyond being due', function () {
        $overview = app(Overview::class);

        expect($overview->total())->toBe(6)
            ->and($overview->statuses())->toBe(['fresh' => 2, 'due_soon' => 1, 'overdue' => 1, 'expired' => 1, 'exempt' => 1])
            ->and($overview->attention())->toBe(['never_reviewed' => 4, 'no_owner' => 1, 'inferred_owner' => 1]);
    });

    it('breaks down by collection, site and owner, with the ownerless last and the most overdue owner first', function () {
        $overview = app(Overview::class);

        $collections = collect($overview->byCollection())->keyBy('key');
        expect($collections->keys()->all())->toBe(['news', 'pages'])
            ->and($collections['pages']['label'])->toBe('Pages')
            ->and($collections['pages'])->toMatchArray(['total' => 3, 'overdue' => 1, 'expired' => 1, 'fresh' => 1, 'never_reviewed' => 2])
            ->and($collections['news'])->toMatchArray(['total' => 3, 'due_soon' => 1, 'exempt' => 1, 'never_reviewed' => 2]);

        expect(collect($overview->bySite())->pluck('total', 'key')->all())->toBe(['default' => 6]);

        $owners = $overview->byOwner();
        expect(array_column($owners, 'label'))->toBe(['Alice Ahmed', 'Bob Baird', 'Nobody'])
            ->and($owners[0])->toMatchArray(['key' => 'alice', 'total' => 3, 'overdue' => 1, 'expired' => 1])
            ->and($owners[2])->toMatchArray(['key' => null, 'total' => 1]);
    });
});

describe('the list', function () {
    it('shows every page, most overdue first, with owner, state and last review in words', function () {
        $page = app(EntryListing::class)->page(EntryListing::filtersFrom([]));

        expect($page->total())->toBe(6)
            ->and(array_column($page->items(), 'id'))->toBe(['policy', 'handbook', 'story', 'about', 'orphan', 'legal']);

        $policy = $page->items()[0];
        expect($policy)->toMatchArray([
            'title' => 'Policy', 'uri' => '/policy', 'collection' => 'Pages',
            'owner_source_label' => 'a guess, from the last person to edit it',
            'status' => 'expired', 'status_label' => 'Expired', 'colour' => 'red',
            'next_review_at' => '2026-07-01', 'interval_label' => 'Yearly', 'interval_reason' => 'the site default',
            'last_reviewed_at' => null,
        ])
            ->and($policy['owner'])->toBe(['id' => 'alice', 'name' => 'Alice Ahmed', 'exists' => true])
            ->and($policy['edit_url'])->toContain('/cp/collections/pages/entries/policy');

        $about = collect($page->items())->firstWhere('id', 'about');
        expect($about['last_reviewed_at'])->toBe('2026-06-01')->and($about['last_reviewed_by'])->toBe('Bob Baird');
    });

    it('filters the ways the spec names, and by collection, site and owner', function () {
        $ids = fn (array $filters): array => array_column(app(EntryListing::class)->page(EntryListing::filtersFrom($filters))->items(), 'id');

        expect($ids(['status' => 'overdue']))->toBe(['handbook'])
            ->and($ids(['status' => 'due_soon']))->toBe(['story'])
            ->and($ids(['attention' => 'never_reviewed']))->toBe(['policy', 'handbook', 'story', 'orphan'])
            ->and($ids(['attention' => 'no_owner']))->toBe(['orphan'])
            ->and($ids(['attention' => 'inferred_owner']))->toBe(['policy'])
            ->and($ids(['collection' => 'news']))->toBe(['story', 'orphan', 'legal'])
            ->and($ids(['site' => 'default']))->toHaveCount(6)
            ->and($ids(['owner' => 'alice']))->toBe(['policy', 'handbook', 'legal'])
            ->and($ids(['owner' => 'alice', 'status' => 'expired']))->toBe(['policy'])
            // Nonsense is ignored, not an error.
            ->and($ids(['status' => 'sideways', 'attention' => 'whatever']))->toHaveCount(6);
    });

    it('names a page whose entry is gone by its ID, rather than dropping it', function () {
        Entry::find('orphan')->deleteQuietly();
        $row = collect(app(EntryListing::class)->page(EntryListing::filtersFrom(['attention' => 'no_owner']))->items())->first();

        expect($row['title'])->toBe('orphan')->and($row['exists'])->toBeFalse()->and($row['edit_url'])->toBeNull();
    });
});

describe('the screens', function () {
    it('serves the overview with its numbers and its ways in', function () {
        $this->actingAs($this->alice)
            ->get(cp_route('lifecycle.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('lifecycle/Dashboard')
                ->where('installed', true)
                ->where('total', 6)
                ->where('statuses.overdue', 1)
                ->where('attention.no_owner', 1)
                // Two late rows, neither baselined nor reviewed: the day-one notice.
                ->where('baseline.late_unbaselined', 2)
                ->where('baseline.baselined', 0)
                ->where('baseline.spread_from', null)
                ->has('collections', 2)
                ->has('owners', 3)
                ->where('me', 'alice')
                ->where('statusMeta.overdue.colour', 'red'));
    });

    it('drops the notice once the late pages are baselined, and says what the baseline did', function () {
        EntryState::query()->whereIn('status', ['overdue', 'expired'])->update(['baselined' => true, 'baseline_review_at' => '2026-11-03 00:00:00']);
        EntryState::query()->where('status', 'fresh')->update(['baselined' => true, 'baseline_review_at' => '2027-08-19 00:00:00']);

        $baseline = (new Overview)->baseline();

        expect($baseline['late_unbaselined'])->toBe(0)
            ->and($baseline['baselined'])->toBeGreaterThanOrEqual(3)
            ->and($baseline['spread_from'])->toBe('2026-11-03')
            ->and($baseline['spread_to'])->toBe('2027-08-19');
    });

    it('does not count a fresh page that was never baselined: a new page is not a backlog', function () {
        EntryState::query()->whereIn('status', ['overdue', 'expired'])->update(['baselined' => true]);

        expect((new Overview)->baseline()['late_unbaselined'])->toBe(0)
            ->and(EntryState::query()->where('baselined', false)->count())->toBeGreaterThan(0);
    });

    it('serves the list, filtered from the query string', function () {
        $this->actingAs($this->alice)
            ->get(cp_route('lifecycle.entries', ['status' => 'overdue']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('lifecycle/Entries')
                ->where('title', 'Content review')
                ->where('mine', false)
                ->where('filters.status', 'overdue')
                ->where('entries.total', 1)
                ->where('entries.data.0.id', 'handbook')
                ->has('options.collections', 2));
    });

    it('serves "My content" as the person looking, whatever the query says', function () {
        $this->actingAs($this->alice)
            ->get(cp_route('lifecycle.mine', ['owner' => 'bob']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('lifecycle/Entries')
                ->where('title', 'My content')
                ->where('mine', true)
                ->where('filters.owner', 'alice')
                ->where('entries.total', 3));
    });

    it('says so, rather than failing, when Lifecycle is not installed', function () {
        config([
            'lifecycle.connection' => 'lifecycle_absent',
            'database.connections.lifecycle_absent' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/absent-'.uniqid().'.sqlite', 'prefix' => ''],
        ]);

        $this->actingAs($this->alice)->get(cp_route('lifecycle.dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('installed', false)->where('total', 0));
        $this->actingAs($this->alice)->get(cp_route('lifecycle.mine'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('installed', false)->where('entries', null));
    });

    it('needs a signed-in control panel user', function () {
        $this->get(cp_route('lifecycle.dashboard'))->assertRedirect();
    });

    it('registers its nav item under Tools, with "My content" beneath it, against a real Nav', function () {
        // AddonTestCase mocks Nav::build() to return nothing, so an addon's
        // own nav registration is unreachable from its suite unless the mock
        // is stepped around — which is how the sibling once shipped a nav
        // call that took the whole control panel down.
        $this->actingAs($this->alice);
        $real = new StatamicNav;
        Nav::swap($real);

        $provider = new ServiceProvider(app());
        $boot = new ReflectionMethod($provider, 'bootNavigation');
        $boot->invoke($provider);

        $makeBaseItems = new ReflectionMethod($real, 'makeBaseItems');
        $ours = collect($makeBaseItems->invoke($real))->filter(fn ($item) => str_contains((string) $item->url(), '/lifecycle'));

        expect($ours)->toHaveCount(1)
            ->and((string) $ours->first()->name())->toBe('Lifecycle')
            ->and($ours->first()->section())->toBe('Tools');

        $children = collect($ours->first()->resolveChildren()->children());
        expect($children)->toHaveCount(1)
            ->and((string) $children->first()->name())->toBe('My content')
            ->and((string) $children->first()->url())->toContain('/lifecycle/mine');
    });
});
