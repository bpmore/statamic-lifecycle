<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle;

use Illuminate\Console\Scheduling\Schedule;
use Statamic\Contracts\Addons\SettingsRepository as SettingsRepositoryContract;
use Statamic\Events\AddonSettingsSaved;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\EntryDeleted;
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
    ];

    protected $actions = [
        Actions\MarkReviewed::class,
        Actions\AssignOwner::class,
    ];

    protected $fieldtypes = [
        Fieldtypes\LifecycleStatus::class,
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

        $this->app->singleton(StateRecomputer::class, fn ($app): StateRecomputer => new StateRecomputer(
            $app->make(Storage\StateStore::class),
            $app->make(State\StateComputer::class),
            $app->make(Entries\EntryFactsReader::class),
            max(1, (int) $app['config']->get('lifecycle.recompute.chunk_size', 250)),
            $app['config']->get('lifecycle.recompute.queue') ?: null,
        ));
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
    }
}
