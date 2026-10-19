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
use Statamic\CP\Navigation\Nav as StatamicNav;
use Statamic\Facades\Collection;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Entry;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

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
