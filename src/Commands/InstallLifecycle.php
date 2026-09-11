<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Commands;

use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * Creates the tables, wherever they have been configured to live.
 *
 * Separate from `migrate` on purpose. Most Statamic sites are flat-file and
 * have never run a migration; asking somebody to configure a database before
 * they can see whether an addon is any use is a reason not to find out.
 */
class InstallLifecycle extends Command
{
    use RunsInPlease;

    protected $name = 'lifecycle:install';

    protected $description = 'Create the tables Lifecycle keeps its review state and history in';

    public function handle(LifecycleDatabase $database): int
    {
        $connection = LifecycleDatabase::connectionName();

        if ($database->isInstalled()) {
            $pending = $database->pendingMigrations();

            if ($pending === []) {
                // Nothing of ours to run — but the queue's batch table is not
                // ours, and an install from before it was needed may lack it.
                foreach ($database->ensureBatchTable() as $line) {
                    $this->components->info($line);
                }

                $this->components->info("Already installed on [{$connection}].");

                return self::SUCCESS;
            }

            // An upgrade, not an install. Running the outstanding migrations is
            // what this command is for, and nothing else here needs to happen.
            foreach ($database->install() as $line) {
                $this->components->info($line);
            }

            $this->components->info(
                'Applied '.count($pending).' new '.(count($pending) === 1 ? 'migration' : 'migrations').'.'
            );

            return self::SUCCESS;
        }

        // Creating tables in a database the site administers is its
        // administrator's decision, not this command's.
        if (! $database->ownsConnection() && ! $this->option('force') && ! $this->confirm(
            "This will create tables on [{$connection}], which this site administers. Continue?",
            true,
        )) {
            $this->components->warn('Nothing was changed.');

            return self::FAILURE;
        }

        foreach ($database->install() as $line) {
            $this->components->info($line);
        }

        return self::SUCCESS;
    }

    /** @return array<int, array<int, mixed>> */
    protected function getOptions(): array
    {
        return [
            ['force', null, null, 'Do not ask before creating tables on a connection the site administers'],
        ];
    }
}
