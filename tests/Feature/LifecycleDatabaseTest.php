<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * A fresh SQLite connection on a file that does not exist yet, so a test can
 * watch install() create it. The directory is fresh too, so the .gitignore
 * behaviour is observable.
 *
 * @return array{name: string, file: string, dir: string}
 */
function scratchConnection(string $name): array
{
    $dir = sys_get_temp_dir().'/lifecycle-test-'.uniqid();
    $file = $dir.'/lifecycle.sqlite';

    config([
        'lifecycle.connection' => $name,
        "database.connections.{$name}" => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
        ],
    ]);

    return ['name' => $name, 'file' => $file, 'dir' => $dir];
}

function removeScratch(array $scratch): void
{
    app('files')->deleteDirectory($scratch['dir']);
}

it('defaults to a SQLite file the addon owns, so a flat-file site needs no database', function () {
    // Most Statamic sites are flat-file. Requiring a database before somebody
    // can see whether the addon is any use is a reason not to find out.
    config(['lifecycle.connection' => null]);

    expect(LifecycleDatabase::connectionName())->toBe('lifecycle_sqlite');

    app(LifecycleDatabase::class)->defineDefaultConnection();

    $connection = config('database.connections.lifecycle_sqlite');

    expect($connection['driver'])->toBe('sqlite')
        ->and($connection['database'])->toEndWith('lifecycle/lifecycle.sqlite')
        ->and(app(LifecycleDatabase::class)->ownsConnection())->toBeTrue();
});

it('uses a connection the site names instead', function () {
    config(['lifecycle.connection' => 'mysql']);

    expect(LifecycleDatabase::connectionName())->toBe('mysql')
        ->and(app(LifecycleDatabase::class)->ownsConnection())->toBeFalse();
});

it('does not overwrite a connection the site defined under the same name', function () {
    config(['database.connections.lifecycle_sqlite' => ['driver' => 'mysql', 'database' => 'theirs']]);

    app(LifecycleDatabase::class)->defineDefaultConnection();

    expect(config('database.connections.lifecycle_sqlite.driver'))->toBe('mysql');
});

it('answers "not installed" rather than throwing when the file is not there yet', function () {
    // Schema::hasTable on a SQLite path that does not exist throws. For this
    // question that is the same answer as "no tables".
    $scratch = scratchConnection('lifecycle_missing');

    expect(app(LifecycleDatabase::class)->isInstalled())->toBeFalse()
        // And every migration is pending, for the same reason.
        ->and(app(LifecycleDatabase::class)->pendingMigrations())
        ->toHaveCount(count(glob(app(LifecycleDatabase::class)->migrationPath().'/*.php')));
});

it('creates the file, its directory and the tables on install', function () {
    $scratch = scratchConnection('lifecycle_fresh');
    $database = app(LifecycleDatabase::class);

    expect(is_dir($scratch['dir']))->toBeFalse();

    $done = $database->install();

    expect(is_file($scratch['file']))->toBeTrue()
        ->and($database->isInstalled())->toBeTrue()
        ->and($database->pendingMigrations())->toBe([])
        ->and(Schema::connection('lifecycle_fresh')->hasTable('lifecycle_reviews'))->toBeTrue()
        ->and(Schema::connection('lifecycle_fresh')->hasTable('lifecycle_notifications'))->toBeTrue()
        ->and(Schema::connection('lifecycle_fresh')->hasTable('lifecycle_actions'))->toBeTrue()
        // The record of what ran lives with the tables, not on the site's
        // default connection — one path, one record.
        ->and(Schema::connection('lifecycle_fresh')->hasTable('migrations'))->toBeTrue()
        ->and($done)->toContain('Created the database file for [lifecycle_fresh].')
        ->and($done)->toContain('Lifecycle tables are in place on [lifecycle_fresh].');

    // A directory the addon created stays out of the site's repository:
    // the review history is not something `git add -A` should pick up.
    expect(file_get_contents($scratch['dir'].'/.gitignore'))->toBe("*\n!.gitignore\n");

    removeScratch($scratch);
});

it('leaves an existing directory\'s ignore rules alone', function () {
    // A directory that was already there belongs to the site.
    $scratch = scratchConnection('lifecycle_existing_dir');
    mkdir($scratch['dir'], 0755, true);

    app(LifecycleDatabase::class)->install();

    expect(is_file($scratch['file']))->toBeTrue()
        ->and(is_file($scratch['dir'].'/.gitignore'))->toBeFalse();

    removeScratch($scratch);
});

it('applies a migration added after the tables were first created', function () {
    // The sibling addon's install command returned early on "the tables
    // exist", so a column added in a later release never reached a site that
    // installed an earlier one. This addon ships with the fix in place: the
    // question is "is anything pending", not "is anything there".
    $scratch = scratchConnection('lifecycle_upgrade');
    mkdir($scratch['dir'], 0755, true);
    touch($scratch['file']);

    // The state an older install would be in: every migration but the newest.
    $all = glob(app(LifecycleDatabase::class)->migrationPath().'/*.php');
    $newest = array_pop($all);

    foreach ($all as $migration) {
        Artisan::call('migrate', [
            '--database' => 'lifecycle_upgrade',
            '--path' => $migration,
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    $database = app(LifecycleDatabase::class);

    expect($database->isInstalled())->toBeTrue()
        ->and($database->pendingMigrations())->toBe([basename($newest, '.php')]);

    $database->install();

    expect($database->pendingMigrations())->toBe([])
        // The newest migration, as of this writing, adds these.
        ->and(Schema::connection('lifecycle_upgrade')->hasColumn('lifecycle_states', 'baseline_review_at'))->toBeTrue();

    removeScratch($scratch);
});
