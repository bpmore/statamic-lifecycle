<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Commands;

use Bpmore\StatamicLifecycle\StateRecomputer;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

/**
 * Brings every entry's review state up to date. Scheduled daily; run by
 * hand after changing intervals, owners or thresholds in config.
 */
class RecomputeLifecycle extends Command
{
    use RunsInPlease;

    protected $signature = 'lifecycle:recompute
        {--site= : Only this site}
        {--collection= : Only this collection}
        {--sync : Run here and now instead of queueing}';

    protected $description = 'Bring every published entry\'s review state up to date';

    public function handle(LifecycleDatabase $database, StateRecomputer $recomputer): int
    {
        if (! $database->isInstalled()) {
            $this->components->error('Lifecycle is not installed on ['.LifecycleDatabase::connectionName().']. Run `php please lifecycle:install` first.');

            return self::FAILURE;
        }

        $site = $this->option('site') ?: null;
        $collection = $this->option('collection') ?: null;

        if ($collection !== null && Collection::find($collection) === null) {
            $this->components->error("There is no collection called [{$collection}].");

            return self::FAILURE;
        }

        if ($site !== null && ! Site::all()->has($site)) {
            $this->components->error("There is no site called [{$site}].");

            return self::FAILURE;
        }

        if (! $this->option('sync')) {
            $queued = $recomputer->queue($site, $collection);

            if ($queued->entries === 0) {
                $this->components->warn('No published entries to recompute.');

                return self::SUCCESS;
            }

            $this->components->info(sprintf(
                'Queued %s %s in %s %s, plus one to remove rows for entries that are gone.',
                number_format($queued->entries),
                $queued->entries === 1 ? 'entry' : 'entries',
                number_format($queued->jobs),
                $queued->jobs === 1 ? 'job' : 'jobs',
            ));
            $this->line('  Watch it with <comment>php please queue:work</comment>, or run with <comment>--sync</comment> to do it here.');

            return self::SUCCESS;
        }

        $total = $recomputer->entries($site, $collection)->count();
        $done = 0;

        if ($total === 0) {
            $this->components->warn('No published entries to recompute.');
        } else {
            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $done = $recomputer->run($site, $collection, fn (int $written) => $bar->advance($written));

            $bar->finish();
            $this->newLine(2);
            $this->components->info(sprintf('Recomputed %s %s.', number_format($done), $done === 1 ? 'entry' : 'entries'));
        }

        // Even with nothing live, there may be rows left from what used to be.
        $pruned = $recomputer->prune($site, $collection);

        if ($pruned > 0) {
            $this->components->info(sprintf(
                'Removed %s %s for entries that are gone or no longer published.',
                number_format($pruned),
                $pruned === 1 ? 'row' : 'rows',
            ));
        }

        return self::SUCCESS;
    }
}
