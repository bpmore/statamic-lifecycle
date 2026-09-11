<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle;

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
}
