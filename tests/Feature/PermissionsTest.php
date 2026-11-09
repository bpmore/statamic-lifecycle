<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Auth\Permissions;
use Bpmore\StatamicLifecycle\Expiry\ActionLog;
use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Models\Review;
use Bpmore\StatamicLifecycle\Panel\SidebarPanel;
use Bpmore\StatamicLifecycle\ServiceProvider;
use Bpmore\StatamicLifecycle\Widgets\Lifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Statamic\CP\Navigation\Nav as StatamicNav;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Entry;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\YAML;

/**
 * The permissions (spec §8), and what each one opens. Roles are real
 * Statamic roles with real permissions, checked through the real gate.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-12 10:00:00');
    config(['statamic.editions.pro' => true]);   // more than one user
    Collection::make('pages')->save();
    Entry::make()->id('page')->collection('pages')->slug('page')->published(true)->data(['title' => 'Page'])->save();

    // A person with exactly these permissions, plus the control panel and
    // the collection, so the only thing that differs is ours.
    $this->userWith = function (array $permissions) {
        $handle = 'tester-'.substr(md5(implode('|', $permissions)), 0, 8);
        $role = Role::make($handle)->addPermission('access cp')->addPermission('view pages entries')->addPermission('edit pages entries');
        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }
        $role->save();
        $this->roles[] = $role;

        return User::make()->id('u-'.$handle)->email("$handle@example.edu")->data(['name' => ucfirst($handle)])->assignRole($role)->save();
    };
    $this->roles = [];
});

afterEach(function () {
    foreach ($this->roles as $role) {
        $role->delete();
    }
    Carbon::setTestNow();
});

/**
 * A field of the page as it is on disk. After a refused save the cached
 * entry object has already been handed the refused values (the array cache
 * returns the same instance); the file is what was, and was not, written.
 */
function stored(string $field): mixed
{
    return YAML::file(Entry::find('page')->path())->parse()[$field] ?? null;
}

function runAction(string $action, array $values = []): TestResponse
{
    return test()->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
        'action' => $action,
        'selections' => ['page'],
        'values' => $values,
    ]);
}

it('registers spec §8\'s five permissions, in their own group, flat', function () {
    $ours = Permission::boot()->all()->filter(fn ($p) => $p->group() === 'lifecycle');

    expect($ours->map->value()->values()->all())->toBe(['view lifecycle', 'attest reviews', 'assign owners', 'manage exemptions', 'configure expiry actions'])
        ->and($ours->every(fn ($p) => $p->children()->isEmpty()))->toBeTrue()
        ->and($ours->firstWhere(fn ($p) => $p->value() === 'configure expiry actions')->description())->toContain('config/lifecycle.php');
});

describe('view lifecycle', function () {
    it('opens the dashboard and the full list; without it, only one\'s own content', function () {
        $viewer = ($this->userWith)(['view lifecycle']);
        $editor = ($this->userWith)([]);

        $this->actingAs($viewer)->get(cp_route('lifecycle.dashboard'))->assertOk();
        $this->actingAs($viewer)->get(cp_route('lifecycle.entries'))->assertOk();
        $this->actingAs($viewer)->get(cp_route('lifecycle.mine'))->assertOk();

        $this->actingAs($editor)->get(cp_route('lifecycle.dashboard'))->assertForbidden();
        $this->actingAs($editor)->get(cp_route('lifecycle.entries'))->assertForbidden();
        $this->actingAs($editor)->get(cp_route('lifecycle.mine'))->assertOk();
    });

    it('decides whether the widget shows the site\'s numbers, and where its title goes', function () {
        $this->actingAs(($this->userWith)(['view lifecycle']));
        $props = (new Lifecycle)->component()->toArray()['props'];
        expect($props['site'])->not->toBeNull()->and($props['dashboardUrl'])->toContain('/lifecycle')->and($props['dashboardUrl'])->not->toContain('/mine');

        $this->actingAs(($this->userWith)([]));
        $props = (new Lifecycle)->component()->toArray()['props'];
        expect($props['site'])->toBeNull()
            ->and($props['mine'])->toBe(['overdue' => 0, 'due_soon' => 0])
            ->and($props['trend'])->toBe([])
            ->and($props['dashboardUrl'])->toContain('/lifecycle/mine')
            ->and($props['overdueUrl'])->toBeNull();
    });

    it('decides the nav: the dashboard with My content beneath it, or just My content', function () {
        $items = function ($user): array {
            $this->actingAs($user);
            $real = new StatamicNav;
            Nav::swap($real);
            $boot = new ReflectionMethod($provider = new ServiceProvider(app()), 'bootNavigation');
            $boot->invoke($provider);
            $makeBaseItems = new ReflectionMethod($real, 'makeBaseItems');
            $ours = collect($makeBaseItems->invoke($real))->filter(fn ($item) => preg_match('#/cp/lifecycle(/mine)?$#', (string) $item->url()) === 1)->values();

            return [(string) $ours->first()->url(), collect($ours->first()->resolveChildren()->children() ?? [])->map(fn ($c) => (string) $c->name())->all()];
        };

        [$url, $children] = $items(($this->userWith)(['view lifecycle']));
        expect($url)->toEndWith('/lifecycle')->and($children)->toBe(['My content']);

        [$url, $children] = $items(($this->userWith)([]));
        expect($url)->toEndWith('/lifecycle/mine')->and($children)->toBe([]);
    });
});

describe('attest reviews', function () {
    it('is what Mark reviewed asks for — not the right to edit the page', function () {
        $this->actingAs(($this->userWith)(['attest reviews']));
        runAction('mark_reviewed', ['outcome' => 'current'])->assertOk();
        expect(Review::query()->count())->toBe(1);

        $this->actingAs(($this->userWith)(['view lifecycle', 'assign owners', 'configure expiry actions']));
        runAction('mark_reviewed', ['outcome' => 'current'])->assertForbidden();
        expect(Review::query()->count())->toBe(1);
    });

    it('is mirrored by the panel\'s button', function () {
        $this->actingAs(($this->userWith)(['attest reviews']));
        expect(app(SidebarPanel::class)->for(Entry::find('page'))['can_attest'])->toBeTrue();

        $this->actingAs(($this->userWith)([]));
        expect(app(SidebarPanel::class)->for(Entry::find('page'))['can_attest'])->toBeFalse();
    });
});

describe('assign owners', function () {
    it('is what Assign owner asks for', function () {
        $alice = ($this->userWith)(['assign owners']);
        $this->actingAs($alice);
        runAction('assign_owner', ['owner' => [$alice->id()]])->assertOk();
        expect(Entry::find('page')->get('content_owner'))->toBe($alice->id());

        $this->actingAs(($this->userWith)(['attest reviews']));
        runAction('assign_owner', ['owner' => [$alice->id()]])->assertForbidden();
    });

    it('is what the sidebar field asks for too: read-only without it, and a changed owner refused on save', function () {
        $alice = ($this->userWith)(['assign owners']);
        $editor = ($this->userWith)(['attest reviews']);

        // The field is drawn read-only for someone without the permission,
        // the way Statamic draws the author field. Blink caches the blueprint
        // per entry for a request, so each look is a fresh request's.
        Blink::flush();
        $this->actingAs($editor);
        expect(Entry::find('page')->blueprint()->field('content_owner')->visibility())->toBe('read_only')
            ->and(Entry::find('page')->blueprint()->field('review_interval')->visibility())->toBe('visible');
        Blink::flush();

        $this->actingAs($alice);
        expect(Entry::find('page')->blueprint()->field('content_owner')->visibility())->toBe('visible');
        Blink::flush();

        // Read-only is what the form draws; the request can still carry a
        // value. A changed owner is refused, beside the field.
        $this->actingAs($editor)
            ->patchJson(cp_route('collections.entries.update', ['collection' => 'pages', 'entry' => 'page']), [
                'title' => 'Page', 'slug' => 'page', 'content_owner' => [$editor->id()], '_localized' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content_owner' => 'assign owners']);
        expect(stored('content_owner'))->toBeNull();

        // Leaving the owner as it was is editing, and editing needs no
        // permission of ours.
        $this->actingAs($alice);
        runAction('assign_owner', ['owner' => [$alice->id()]])->assertOk();
        $this->actingAs($editor)
            ->patchJson(cp_route('collections.entries.update', ['collection' => 'pages', 'entry' => 'page']), [
                'title' => 'Page, edited', 'slug' => 'page', 'content_owner' => [$alice->id()], '_localized' => [],
            ])
            ->assertOk();
        expect(stored('title'))->toBe('Page, edited')
            ->and(stored('content_owner'))->toBe($alice->id());

        // Clearing it is a change too.
        $this->actingAs($editor)
            ->patchJson(cp_route('collections.entries.update', ['collection' => 'pages', 'entry' => 'page']), [
                'title' => 'Page', 'slug' => 'page', 'content_owner' => [], '_localized' => [],
            ])
            ->assertStatus(422);
        expect(stored('content_owner'))->toBe($alice->id());

        // Someone with the permission changes it from the sidebar as well.
        $this->actingAs($alice)
            ->patchJson(cp_route('collections.entries.update', ['collection' => 'pages', 'entry' => 'page']), [
                'title' => 'Page', 'slug' => 'page', 'content_owner' => [$editor->id()], '_localized' => [],
            ])
            ->assertOk();
        expect(stored('content_owner'))->toBe($editor->id());

        // And a new entry cannot arrive with an owner chosen by someone who
        // may not choose one: there is nothing stored to compare with, so
        // any owner is a change.
        $this->actingAs($editor);
        $refused = null;

        try {
            Entry::make()->collection('pages')->slug('new-page')->data(['title' => 'New page', 'content_owner' => $editor->id()])->save();
        } catch (ValidationException $refused) {
        }

        expect($refused?->errors())->toHaveKey('content_owner')
            ->and(Entry::query()->where('slug', 'new-page')->count())->toBe(0);

        // With nobody signed in there is nobody to hold back: a script
        // writes what it writes.
        auth()->logout();
        Entry::find('page')->set('content_owner', $alice->id())->save();
        expect(stored('content_owner'))->toBe($alice->id());
    });
});

describe('manage exemptions', function () {
    it('is what Exempt and Clear exemption ask for', function () {
        $this->actingAs(($this->userWith)(['manage exemptions']));
        runAction('exempt')->assertOk();
        expect(Entry::find('page')->get('review_exempt'))->toBeTrue();
        runAction('clear_exemption')->assertOk();
        expect(Entry::find('page')->get('review_exempt'))->toBeNull();

        $this->actingAs(($this->userWith)(['attest reviews', 'assign owners', 'view lifecycle', 'configure expiry actions']));
        runAction('exempt')->assertForbidden();
        expect(Entry::find('page')->get('review_exempt'))->toBeNull();
    });

    it('is what the sidebar toggle asks for too: read-only without it, and a flipped toggle refused on save', function () {
        $manager = ($this->userWith)(['manage exemptions']);
        $editor = ($this->userWith)(['assign owners']);

        Blink::flush();
        $this->actingAs($editor);
        expect(Entry::find('page')->blueprint()->field('review_exempt')->visibility())->toBe('read_only');
        Blink::flush();

        $this->actingAs($manager);
        expect(Entry::find('page')->blueprint()->field('review_exempt')->visibility())->toBe('visible');
        Blink::flush();

        $update = fn (array $values) => $this->patchJson(
            cp_route('collections.entries.update', ['collection' => 'pages', 'entry' => 'page']),
            ['title' => 'Page', 'slug' => 'page', '_localized' => []] + $values,
        );

        $this->actingAs($editor);
        $update(['review_exempt' => true])->assertStatus(422)->assertJsonValidationErrors(['review_exempt' => 'manage exemptions']);
        expect(stored('review_exempt'))->toBeNull();

        // Not exempt is the absence of the line; sending false for a page
        // with no line is not a change.
        $update(['review_exempt' => false])->assertOk();

        $this->actingAs($manager);
        $update(['review_exempt' => true])->assertOk();
        expect(stored('review_exempt'))->toBeTrue();

        $this->actingAs($editor);
        $update(['review_exempt' => true])->assertOk();
        $update(['review_exempt' => false])->assertStatus(422);
        expect(stored('review_exempt'))->toBeTrue();
    });
});

describe('configure expiry actions', function () {
    it('is what Put it back asks for, and the panel\'s button mirrors it', function () {
        Entry::find('page')->unpublish();
        app(ActionLog::class)->record(Entry::find('page'), ActionType::Unpublish, ['published' => true], 'lifecycle:expire');

        $this->actingAs(($this->userWith)(['attest reviews', 'assign owners', 'view lifecycle']));
        expect(app(SidebarPanel::class)->for(Entry::find('page'))['can_put_back'])->toBeFalse();
        runAction('put_back')->assertForbidden();
        expect(Entry::find('page')->published())->toBeFalse();

        $this->actingAs(($this->userWith)(['configure expiry actions']));
        expect(app(SidebarPanel::class)->for(Entry::find('page'))['can_put_back'])->toBeTrue();
        runAction('put_back')->assertOk();
        expect(Entry::find('page')->published())->toBeTrue();
    });
});

it('lets a super user do all of it, as Statamic does', function () {
    $root = User::make()->id('root')->email('root@example.edu')->makeSuper()->save();

    expect(Permissions::mayView($root))->toBeTrue()
        ->and(Permissions::mayAttest($root, Entry::find('page')))->toBeTrue()
        ->and(Permissions::mayAssign($root, Entry::find('page')))->toBeTrue()
        ->and(Permissions::mayManageExemptions($root, Entry::find('page')))->toBeTrue()
        ->and(Permissions::mayPutBack($root, Entry::find('page')))->toBeTrue()
        ->and(Permissions::mayView(null))->toBeFalse();
});
