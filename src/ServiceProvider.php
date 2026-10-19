<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Statamic\Contracts\Addons\SettingsRepository as SettingsRepositoryContract;
use Statamic\Events\AddonSettingsSaved;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\EntryCreated;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaving;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\User;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $listen = [
        EntryBlueprintFound::class => [
            Listeners\AddLifecycleFieldsToBlueprint::class,
        ],
        EntryDeleted::class => [
            Listeners\RemoveStateOnEntryDeleted::class,
        ],
        // The gate, and the creation record it relies on.
        EntrySaving::class => [
            Listeners\RefuseUnlessOwnedAndDated::class,
        ],
        EntryCreated::class => [
            Listeners\RecordEntryCreated::class,
        ],
    ];

    protected $actions = [
        Actions\MarkReviewed::class,
        Actions\AssignOwner::class,
        Actions\SetInterval::class,
        Actions\Exempt::class,
        Actions\ClearExemption::class,
        Actions\PutBack::class,
    ];

    protected $fieldtypes = [
        Fieldtypes\LifecycleStatus::class,
    ];

    protected $widgets = [
        Widgets\Lifecycle::class,
    ];

    protected $tags = [
        Tags\Lifecycle::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $vite = [
        'input' => ['resources/js/cp.js'],
        'publicDirectory' => 'resources/dist',
    ];

    // The product is "Lifecycle" everywhere a person sees it — views, config,
    // commands — never the repository name.
    protected $viewNamespace = 'lifecycle';

    protected $commands = [
        Commands\InstallLifecycle::class,
        Commands\BaselineLifecycle::class,
        Commands\RecomputeLifecycle::class,
        Commands\AssignOwner::class,
        Commands\NotifyLifecycle::class,
        Commands\ExpireLifecycle::class,
        Commands\ReportLifecycle::class,
    ];

    public function bootAddon(): void
    {
        // The addon's own SQLite connection, unless the site named one of its
        // own. Defined here rather than in register() because it reads the
        // merged config.
        $this->app->make(Storage\LifecycleDatabase::class)->defineDefaultConnection();

        // Addons → Lifecycle → Settings. Built when asked for, so it can list
        // the sites the site has.
        $this->registerSettingsBlueprint(fn (): array => Settings\SettingsBlueprint::contents());

        // Each person's own say in how often they hear, on their preferences
        // screen.
        Notifications\ReminderPreferences::register();

        $this->bootPermissions();
        $this->bootNavigation();

        // Bound here, after Statamic's own binding: Statamic saves settings by
        // slug and reads them by package name, and ours differ. See the class.
        $this->app->bind(SettingsRepositoryContract::class, Settings\SettingsRepository::class);

        // The policy is built once per process from config and settings. When
        // the settings change, the next thing to ask gets a fresh one — which
        // matters under a long-running worker, not just in a test.
        $this->app['events']->listen(AddonSettingsSaved::class, function (AddonSettingsSaved $event): void {
            if ($event->settings->addon()->id() === Owners\FallbackOwners::PACKAGE) {
                $this->app->forgetInstance(State\Policy::class);
                $this->app->forgetInstance(State\StateComputer::class);
                $this->app->forgetInstance(StateRecomputer::class);
            }
        });

        // Deliberately not loadMigrationsFrom: that would run these against the
        // app's default connection under `php please migrate` while
        // `lifecycle:install` runs them against the configured one, and the two
        // would keep separate records of what has run. One path, one record.
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'lifecycle-migrations');
    }

    public function register(): void
    {
        parent::register();

        // Site Weather's tile, if it is installed. A tag is a string: tagging
        // the class name instantiates nothing, so without Site Weather the
        // class is never loaded and the interface it implements is never
        // looked for.
        $this->app->tag(Weather\FreshnessContributor::class, 'site-weather.contributors');

        // The site's rules, read once from config. Everything that computes
        // state gets the same policy, so two screens cannot disagree about
        // what "due soon" means.
        // The owner of last resort comes from config and the settings screen,
        // resolved to people who still exist, before the policy — which is
        // pure and knows nothing of users — is built from it.
        $this->app->singleton(State\Policy::class, function ($app): State\Policy {
            $config = (array) $app['config']->get('lifecycle', []);
            $config['owners'] = array_merge((array) ($config['owners'] ?? []), [
                'fallback' => $app->make(Owners\FallbackOwners::class)->resolved(),
            ]);

            return State\Policy::fromConfig($config);
        });

        $this->app->singleton(State\StateComputer::class, fn ($app): State\StateComputer => new State\StateComputer(
            $app->make(State\Policy::class),
        ));

        $this->app->singleton(Notifications\Schedule::class, fn ($app): Notifications\Schedule => Notifications\Schedule::fromConfig(
            (array) $app['config']->get('lifecycle.notifications', []),
            (array) $app['config']->get('lifecycle.escalation', []),
        ));

        // Not a singleton: cheap to build, and read fresh so a config change
        // is seen by the next run in the same process.
        $this->app->bind(Notifications\QuietWindow::class, fn ($app): Notifications\QuietWindow => Notifications\QuietWindow::fromConfig(
            (array) $app['config']->get('lifecycle.notifications', []),
        ));

        $this->app->bind(Reporting\Pdf\PdfRenderer::class, fn ($app): Reporting\Pdf\PdfRenderer => new Reporting\Pdf\ChromePdfRenderer(
            $app['config']->get('lifecycle.report.chrome'),
            (int) $app['config']->get('lifecycle.report.timeout', 120),
        ));

        $this->app->bind(Gate\GateSettings::class, fn ($app): Gate\GateSettings => new Gate\GateSettings(
            (array) $app['config']->get('lifecycle.gate.collections', []),
        ));

        $this->app->bind(Expiry\CircuitBreaker::class, fn ($app): Expiry\CircuitBreaker => Expiry\CircuitBreaker::fromConfig(
            (array) $app['config']->get('lifecycle.expiry.circuit_breaker', []),
        ));

        $this->app->bind(Expiry\DailyCap::class, fn ($app): Expiry\DailyCap => Expiry\DailyCap::fromConfig(
            (array) $app['config']->get('lifecycle.expiry', []),
        ));

        $this->app->bind(Expiry\NoticePeriod::class, fn ($app): Expiry\NoticePeriod => Expiry\NoticePeriod::fromConfig(
            (array) $app['config']->get('lifecycle.expiry', []),
        ));

        $this->app->bind(Expiry\NeverTouch::class, fn ($app): Expiry\NeverTouch => Expiry\NeverTouch::fromConfig(
            (array) $app['config']->get('lifecycle.expiry.never_touch', []),
        ));

        $this->app->bind(Expiry\NavigationCheck::class, fn ($app): Expiry\NavigationCheck => Expiry\NavigationCheck::fromConfig(
            (array) $app['config']->get('lifecycle.expiry', []),
        ));

        $this->app->bind(Expiry\EnabledActions::class, fn ($app): Expiry\EnabledActions => Expiry\EnabledActions::fromConfig(
            (array) $app['config']->get('lifecycle.expiry', []),
        ));

        $this->app->bind(Expiry\ActionApplier::class, fn ($app): Expiry\ActionApplier => Expiry\ActionApplier::fromConfig(
            (array) $app['config']->get('lifecycle.expiry', []),
        ));

        $this->app->singleton(StateRecomputer::class, fn ($app): StateRecomputer => new StateRecomputer(
            $app->make(Storage\StateStore::class),
            $app->make(State\StateComputer::class),
            $app->make(Entries\EntryFactsReader::class),
            max(1, (int) $app['config']->get('lifecycle.recompute.chunk_size', 250)),
            $app['config']->get('lifecycle.recompute.queue') ?: null,
        ));
    }

    /**
     * One item under Tools, with "My content" beneath it — the screen an
     * owner actually lives in.
     */
    private function bootNavigation(): void
    {
        Nav::extend(function ($nav): void {
            // A route cache built before this addon was installed does not
            // contain its CP routes, and Nav::extend runs on every control
            // panel request including login. Without this guard a stale cache
            // takes the whole control panel down with "Route not defined".
            if (! Route::has('statamic.cp.lifecycle.dashboard')) {
                return;
            }

            // Someone who may see the whole site gets the dashboard with
            // "My content" under it; everyone else gets their own content,
            // which needs no permission, under the same name.
            if (Auth\Permissions::mayView(User::current())) {
                $nav->tools(__('Lifecycle'))
                    ->route('lifecycle.dashboard')
                    ->icon('history')
                    ->children(fn (): array => [
                        Nav::item(__('My content'))->route('lifecycle.mine'),
                    ]);

                return;
            }

            $nav->tools(__('Lifecycle'))->route('lifecycle.mine')->icon('history');
        });
    }

    /**
     * Spec §8's five permissions. Flat, not nested: attesting one's own
     * pages must not require seeing the whole site.
     */
    private function bootPermissions(): void
    {
        Permission::extend(function (): void {
            Permission::group('lifecycle', __('Lifecycle'), function (): void {
                Permission::register(Auth\Permissions::VIEW)
                    ->label(__('View lifecycle'))
                    ->description(__('See the review state of every page: the dashboard, the full list, and the site-wide numbers on the widget. Everyone signed in can see their own content.'));

                Permission::register(Auth\Permissions::ATTEST_REVIEWS)
                    ->label(__('Attest reviews'))
                    ->description(__('Mark a page reviewed, putting their own name and the date on the record.'));

                Permission::register(Auth\Permissions::ASSIGN_OWNERS)
                    ->label(__('Assign owners'))
                    ->description(__('Say who owns a page, singly or in bulk, on pages they may edit.'));

                Permission::register(Auth\Permissions::MANAGE_EXEMPTIONS)
                    ->label(__('Manage exemptions'))
                    ->description(__('Take a page out of the review cycle, or put it back, on pages they may edit. An exempt page has no review date, gets no reminders, and is never expired.'));

                Permission::register(Auth\Permissions::CONFIGURE_EXPIRY)
                    ->label(__('Configure expiry actions'))
                    ->description(__('Put back what an automated expiry action changed. Turning actions on per collection is done in config/lifecycle.php by whoever deploys the site; keep this to administrators.'));
            });
        });
    }

    /**
     * Spec §9: recompute daily. Skipped, quietly, until the site has run
     * lifecycle:install — a scheduler log full of "not installed" every
     * night is noise about an addon that has not been set up yet.
     */
    protected function schedule(Schedule $schedule): void
    {
        $installed = fn (): bool => $this->app->make(Storage\LifecycleDatabase::class)->isInstalled();

        $schedule->command('lifecycle:recompute')->daily()->when($installed);

        // After the recompute, at an hour people read email.
        $schedule->command('lifecycle:notify')
            ->dailyAt((string) $this->app['config']->get('lifecycle.notifications.send_at', '07:00'))
            ->when($installed);

        // Daily, and inert until a collection opts in (spec §9).
        $schedule->command('lifecycle:expire')
            ->dailyAt((string) $this->app['config']->get('lifecycle.expiry.run_at', '06:30'))
            ->when($installed);
    }
}
