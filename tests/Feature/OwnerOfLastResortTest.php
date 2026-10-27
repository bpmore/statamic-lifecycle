<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Owners\FallbackOwners;
use Bpmore\StatamicLifecycle\Settings\SettingsBlueprint;
use Bpmore\StatamicLifecycle\State\Policy;
use Bpmore\StatamicLifecycle\StateRecomputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Statamic\Addons\FileSettingsRepository;
use Statamic\Contracts\Addons\SettingsRepository;
use Statamic\Facades\Addon;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    // The control panel refuses more than one user without Pro.
    config(['statamic.editions.pro' => true]);
    $this->settingsFile = resource_path('addons/lifecycle.yaml');
    File::delete($this->settingsFile);

    $this->admin = User::make()->id('admin')->email('admin@example.edu')->makeSuper()->save();
    User::make()->id('webmaster')->email('web@example.edu')->data(['name' => 'The Web Office'])->save();
    Collection::make('pages')->save();
});

afterEach(function () {
    File::delete($this->settingsFile);
    Carbon::setTestNow();
});

function twoSites(): void
{
    config(['statamic.system.multisite' => true, 'statamic.editions.pro' => true]);
    Site::setSites([
        'default' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
        'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => '/fr/'],
    ]);
}

describe('the settings screen', function () {
    it('offers one person per site', function () {
        twoSites();
        $fields = collect(SettingsBlueprint::contents()['tabs']['owners']['sections'][0]['fields']);

        expect($fields->pluck('handle')->all())->toBe(['owner_of_last_resort_default', 'owner_of_last_resort_fr'])
            ->and($fields->first()['field']['type'])->toBe('users')
            ->and($fields->first()['field']['max_items'])->toBe(1)
            ->and($fields->first()['field']['display'])->toBe('Owner of last resort — English')
            ->and($fields->last()['field']['display'])->toBe('Owner of last resort — French');

        expect(SettingsBlueprint::siteForHandle('owner_of_last_resort_fr'))->toBe('fr')
            ->and(SettingsBlueprint::siteForHandle('owner_of_last_resort_mars'))->toBeNull()
            ->and(SettingsBlueprint::siteForHandle('something_else'))->toBeNull();
    });

    it('drops the site from the label when there is only one', function () {
        $fields = SettingsBlueprint::contents()['tabs']['owners']['sections'][0]['fields'];

        expect($fields)->toHaveCount(1)
            ->and($fields[0]['field']['display'])->toBe('Owner of last resort');
    });

    it('is registered with Statamic, and reachable in the control panel', function () {
        $addon = Addon::get(FallbackOwners::PACKAGE);

        expect($addon->hasSettingsBlueprint())->toBeTrue()
            ->and($addon->settingsUrl())->toContain('/addons/lifecycle/settings')
            ->and($addon->settingsBlueprint()->hasField('owner_of_last_resort_default'))->toBeTrue();

        $this->actingAs($this->admin)
            ->get(cp_route('addons.settings.edit', 'lifecycle'))
            ->assertOk();
    });

    it('saves the choice into the site\'s repository, and the policy sees it at once', function () {
        expect(app(Policy::class)->fallbackOwners)->toBe([]);

        $this->actingAs($this->admin)
            ->patchJson(cp_route('addons.settings.update', 'lifecycle'), [
                'owner_of_last_resort_default' => ['webmaster'],
            ])
            ->assertOk()
            ->assertJsonPath('saved', true);

        expect(File::exists($this->settingsFile))->toBeTrue()
            ->and(File::get($this->settingsFile))->toContain('owner_of_last_resort_default: webmaster');

        // resources/addons/lifecycle.yaml is in the repository: the decision
        // is versioned like the config it sits beside.
        expect(app(FallbackOwners::class)->configured())->toBe(['default' => 'webmaster'])
            ->and(app(Policy::class)->fallbackOwners)->toBe(['default' => 'webmaster']);
    });
});

describe('who it resolves to', function () {
    it('reads a map from config, or one person for every site', function () {
        twoSites();

        config(['lifecycle.owners.fallback' => ['default' => 'webmaster', 'mars' => 'ignored']]);
        expect(app(FallbackOwners::class)->configured())->toBe(['default' => 'webmaster']);

        config(['lifecycle.owners.fallback' => 'webmaster']);
        expect(app(FallbackOwners::class)->configured())->toBe(['default' => 'webmaster', 'fr' => 'webmaster']);
    });

    it('lets the settings screen win over config, per site', function () {
        twoSites();
        User::make()->id('fr-desk')->email('fr@example.edu')->save();
        config(['lifecycle.owners.fallback' => 'webmaster']);

        Addon::get(FallbackOwners::PACKAGE)->settings()->set('owner_of_last_resort_fr', 'fr-desk')->save();

        expect(app(FallbackOwners::class)->configured())->toBe(['default' => 'webmaster', 'fr' => 'fr-desk']);
    });

    it('does not use a person who has left, and says who', function () {
        config(['lifecycle.owners.fallback' => ['default' => 'someone-who-left']]);

        expect(app(FallbackOwners::class)->configured())->toBe(['default' => 'someone-who-left'])
            ->and(app(FallbackOwners::class)->resolved())->toBe([])
            ->and(app(FallbackOwners::class)->missing())->toBe(['default' => 'someone-who-left'])
            ->and(app(Policy::class)->fallbackOwners)->toBe([]);
    });
});

describe('what it does', function () {
    it('makes an otherwise ownerless page theirs', function () {
        Entry::make()->id('orphan')->collection('pages')->slug('orphan')->published(true)->data(['title' => 'Orphan'])->save();
        Addon::get(FallbackOwners::PACKAGE)->settings()->set('owner_of_last_resort_default', 'webmaster')->save();

        app(StateRecomputer::class)->recompute(['orphan'], new DateTimeImmutable('2026-09-11'));

        $row = EntryState::query()->find('orphan');

        expect($row->owner_id)->toBe('webmaster')
            ->and($row->owner_source)->toBe('fallback');
    });

    it('is told by the baseline when the person named has left', function () {
        Entry::make()->id('orphan')->collection('pages')->slug('orphan')->published(true)->data(['title' => 'Orphan'])->save();
        config(['lifecycle.owners.fallback' => ['default' => 'someone-who-left']]);

        $this->artisan('lifecycle:baseline', ['--dry-run' => true])
            ->expectsOutputToContain('The owner of last resort for the [default] site, [someone-who-left], is no longer a user.')
            ->assertExitCode(0);
    });
});

it('reads back what it saved, whatever the package is called', function () {
    // Before 6.34, Statamic saved by slug and read by package suffix, and
    // ours differ. 6.34 reads by slug too, which is why it is the floor.
    $settings = Addon::get(FallbackOwners::PACKAGE)->settings();
    $settings->set('owner_of_last_resort_default', 'webmaster')->save();

    expect($settings->path())->toEndWith('addons/lifecycle.yaml')
        ->and(Addon::get(FallbackOwners::PACKAGE)->settings()->get('owner_of_last_resort_default'))->toBe('webmaster');
});

it('leaves where addon settings are kept to Statamic', function () {
    // An override here once replaced the repository for every addon, so a
    // site storing addon settings in a database (the Eloquent driver) lost
    // them all to files the moment Lifecycle was installed.
    expect(get_class(app(SettingsRepository::class)))
        ->toBe(FileSettingsRepository::class);
});
