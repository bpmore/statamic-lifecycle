<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle;

use Illuminate\Console\Scheduling\Schedule;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $listen = [
        EntryBlueprintFound::class => [
            Listeners\AddLifecycleFieldsToBlueprint::class,
        ],
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
    ];

    public function bootAddon(): void
    {
        // The addon's own SQLite connection, unless the site named one of its
        // own. Defined here rather than in register() because it reads the
        // merged config.
        $this->app->make(Storage\LifecycleDatabase::class)->defineDefaultConnection();

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
        $this->app->singleton(State\Policy::class, fn ($app): State\Policy => State\Policy::fromConfig(
            (array) $app['config']->get('lifecycle', []),
        ));

        $this->app->singleton(State\StateComputer::class, fn ($app): State\StateComputer => new State\StateComputer(
            $app->make(State\Policy::class),
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
        $schedule->command('lifecycle:recompute')
            ->daily()
            ->when(fn (): bool => $this->app->make(Storage\LifecycleDatabase::class)->isInstalled());
    }
}
