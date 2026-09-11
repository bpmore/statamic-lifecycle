<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Storage;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where this addon keeps its tables, and how they get there.
 *
 * One connection name, read from config, that everything asks this class for
 * rather than reading config itself. The default is a SQLite file the addon
 * owns outright under storage/, because most Statamic sites are flat-file and
 * have no database at all. A site that runs one points `lifecycle.connection`
 * at it and gets the same tables there.
 *
 * The migrations are deliberately NOT registered with `loadMigrationsFrom`.
 * Doing so would make `php please migrate` run them against the app's default
 * connection while `lifecycle:install` runs them against this one, and the two
 * would then keep separate records of what has run. One path, one record.
 *
 * Same shape as A11y Docs' DocumentDatabase, on purpose: a site that owns both
 * should find the same install story in each.
 */
final class LifecycleDatabase
{
    public const DEFAULT_CONNECTION = 'lifecycle_sqlite';

    public function __construct(private readonly Application $app) {}

    public static function connectionName(): string
    {
        return (string) (config('lifecycle.connection') ?: self::DEFAULT_CONNECTION);
    }

    /**
     * Whether the tables live in the addon's own file rather than a database
     * the site administers. Only then is creating them without being asked
     * safe: nobody else's schema is being touched.
     */
    public function ownsConnection(): bool
    {
        return self::connectionName() === self::DEFAULT_CONNECTION;
    }

    /**
     * Define the addon's own SQLite connection, unless the site has defined one
     * under that name itself. Called at boot, after the config is merged.
     */
    public function defineDefaultConnection(): void
    {
        $key = 'database.connections.'.self::DEFAULT_CONNECTION;

        if (config($key) !== null) {
            return;
        }

        config([$key => [
            'driver' => 'sqlite',
            'database' => $this->app->storagePath('lifecycle/lifecycle.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    public function isInstalled(): bool
    {
        try {
            return Schema::connection(self::connectionName())->hasTable('lifecycle_states');
        } catch (\Throwable) {
            // A SQLite file that does not exist yet throws rather than saying
            // "no tables", and for this question those are the same answer.
            return false;
        }
    }

    /**
     * Migration files this addon ships that have not run on its connection.
     *
     * Asked before deciding an existing install needs nothing. A version that
     * adds a column ships a migration, and a command that returned early on
     * "the tables exist" would leave that column missing on every site that
     * installed an earlier version.
     *
     * @return list<string> migration names, in the order they would run
     */
    public function pendingMigrations(): array
    {
        $files = array_map('basename', glob($this->migrationPath().'/*.php') ?: []);

        try {
            $ran = Schema::connection(self::connectionName())->hasTable('migrations')
                ? DB::connection(self::connectionName())->table('migrations')->pluck('migration')->all()
                : [];
        } catch (\Throwable) {
            return array_map(fn (string $file): string => substr($file, 0, -4), $files);
        }

        return array_values(array_diff(
            array_map(fn (string $file): string => substr($file, 0, -4), $files),
            $ran,
        ));
    }

    /**
     * Create the tables on the connection they belong to, creating the SQLite
     * file first when it is ours and not there yet.
     *
     * @return list<string> what was done, one line each, for a command to print
     */
    public function install(): array
    {
        $done = [];
        $connection = self::connectionName();

        if ($this->createSqliteFileIfMissing($connection)) {
            $done[] = "Created the database file for [{$connection}].";
        }

        Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => $this->migrationPath(),
            '--realpath' => true,
            '--force' => true,
        ]);

        $done[] = "Lifecycle tables are in place on [{$connection}].";

        return $done;
    }

    public function migrationPath(): string
    {
        return (string) realpath(__DIR__.'/../../database/migrations');
    }

    private function createSqliteFileIfMissing(string $connection): bool
    {
        $config = (array) config("database.connections.{$connection}", []);

        if (($config['driver'] ?? null) !== 'sqlite') {
            return false;
        }

        $path = (string) ($config['database'] ?? '');

        if ($path === '' || $path === ':memory:' || file_exists($path)) {
            return false;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);

            // A directory this addon created is this addon's to keep out of the
            // site's repository. Without this, `git status` on a site that has
            // installed lists storage/lifecycle/ as untracked, and the next
            // `git add -A` commits the review history. Written only when the
            // directory did not exist: one that was already there belongs to
            // the site, and its ignore rules are the site's business.
            file_put_contents(dirname($path).'/.gitignore', "*\n!.gitignore\n");
        }

        touch($path);

        return true;
    }
}
