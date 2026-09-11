<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Commands;

use Bpmore\StatamicLifecycle\Entries\EntryFactsReader;
use Bpmore\StatamicLifecycle\State\BaselineCandidate;
use Bpmore\StatamicLifecycle\State\BaselinePlanner;
use Bpmore\StatamicLifecycle\State\EntryFacts;
use Bpmore\StatamicLifecycle\State\PriorState;
use Bpmore\StatamicLifecycle\State\StateComputer;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Bpmore\StatamicLifecycle\Storage\StateStore;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Entry;

/**
 * Day one on a 40,000-page site.
 *
 * Every published entry that has never been baselined or reviewed gets a
 * first review date, spread across the horizon so that the backlog is a
 * schedule rather than a wall of overdue. Oldest content first. Run once at
 * install; running it again touches only entries it has not seen.
 *
 * --dry-run prints exactly what would happen and writes nothing. It is the
 * documented first step.
 */
class BaselineLifecycle extends Command
{
    use RunsInPlease;

    protected $signature = 'lifecycle:baseline
        {--horizon=12 : Months to spread first reviews across}
        {--dry-run : Show what would happen and change nothing}';

    protected $description = 'Give every published entry a first review date, spread across a horizon so nothing is overdue on day one';

    /** Entries read from the Stache per batch of prior-state lookups. */
    private const BATCH = 500;

    public function handle(
        LifecycleDatabase $database,
        StateStore $store,
        StateComputer $computer,
        EntryFactsReader $reader,
        BaselinePlanner $planner,
    ): int {
        if (! $database->isInstalled()) {
            $this->components->error('Lifecycle is not installed on ['.LifecycleDatabase::connectionName().']. Run `php please lifecycle:install` first.');

            return self::FAILURE;
        }

        $horizon = (int) $this->option('horizon');

        if ($horizon < 1) {
            $this->components->error('The horizon is a number of months, at least 1.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now()->toImmutable();
        $today = $now->setTime(0, 0);
        // Calendar months, so twelve is a year whatever year it is.
        $horizonDays = (int) $today->diff($today->modify("+{$horizon} months"))->days;

        // ---- Gather -------------------------------------------------------

        /** @var array<string, EntryFacts> */
        $facts = [];
        /** @var list<BaselineCandidate> */
        $candidates = [];
        /** @var list<string> entries with a hard deadline: baselined, but not spread */
        $hardDated = [];
        $published = 0;
        $skipped = 0;

        $batch = [];
        $flush = function () use (&$batch, &$facts, &$candidates, &$hardDated, &$skipped, $store, $computer, $now): void {
            $prior = $store->priorFor(array_keys($batch));

            foreach ($batch as $id => $entryFacts) {
                if ($prior[$id]->baselined || $prior[$id]->lastReviewedAt !== null) {
                    $skipped++;

                    continue;
                }

                $facts[$id] = $entryFacts;

                if ($entryFacts->reviewBy !== null) {
                    $hardDated[] = $id;

                    continue;
                }

                // The interval the computation would use, so the planner
                // can bound the window by it.
                $interval = $computer->compute($entryFacts, PriorState::none(), $now)->intervalDays;

                if ($interval === null) {
                    $hardDated[] = $id;   // exempt: a row, but no date to spread

                    continue;
                }

                $candidates[] = new BaselineCandidate($id, $entryFacts->lastModifiedAt, $interval);
            }

            $batch = [];
        };

        foreach (Entry::query()->whereStatus('published')->lazy(self::BATCH) as $entry) {
            $published++;
            $entryFacts = $reader->read($entry);
            $batch[$entryFacts->entryId] = $entryFacts;

            if (count($batch) >= self::BATCH) {
                $flush();
            }
        }

        $flush();

        // ---- Plan ---------------------------------------------------------

        $plan = $planner->plan($candidates, $horizonDays, $today);

        $this->components->info(sprintf(
            '%s published %s; %s not yet baselined.',
            number_format($published),
            $published === 1 ? 'entry' : 'entries',
            number_format(count($facts)),
        ));

        if ($skipped > 0) {
            $this->components->twoColumnDetail('Already baselined or reviewed, left alone', number_format($skipped));
        }

        if ($hardDated !== []) {
            $this->components->twoColumnDetail('Have a review-by date of their own, kept', number_format(count($hardDated)));
        }

        if ($facts === []) {
            $this->components->info('Nothing to do.');

            return self::SUCCESS;
        }

        $this->printSpread($plan, $candidates, $facts, $horizon, $horizonDays);

        // ---- Apply --------------------------------------------------------

        if ($dryRun) {
            $this->newLine();
            $this->components->warn('Dry run: nothing was written. Run again without --dry-run to apply.');

            return self::SUCCESS;
        }

        $written = $store->putMany((function () use ($facts, $plan, $computer, $now) {
            foreach ($facts as $id => $entryFacts) {
                $date = $plan[$id] ?? null;
                $prior = new PriorState(baselined: true, baselineNextReviewAt: $date);

                yield [$computer->compute($entryFacts, $prior, $now), $date];
            }
        })());

        $this->newLine();
        $this->components->info(sprintf('Baselined %s %s.', number_format($written), $written === 1 ? 'entry' : 'entries'));

        return self::SUCCESS;
    }

    /**
     * The evidence that the spread is a spread: how many first reviews land
     * in each month, and how each collection was laid out.
     *
     * @param  array<string, DateTimeImmutable>  $plan
     * @param  list<BaselineCandidate>  $candidates
     * @param  array<string, EntryFacts>  $facts
     */
    private function printSpread(array $plan, array $candidates, array $facts, int $horizon, int $horizonDays): void
    {
        if ($plan === []) {
            return;
        }

        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>%s first %s spread across %d %s (%d days), oldest content first:</>',
            number_format(count($plan)),
            count($plan) === 1 ? 'review' : 'reviews',
            $horizon,
            $horizon === 1 ? 'month' : 'months',
            $horizonDays,
        ));
        $this->newLine();

        $byMonth = [];
        foreach ($plan as $date) {
            $key = $date->format('Y-m');
            $byMonth[$key] = ($byMonth[$key] ?? 0) + 1;
        }
        ksort($byMonth);
        $max = max($byMonth);

        foreach ($byMonth as $month => $count) {
            $bar = str_repeat('█', max(1, (int) round($count / $max * 40)));
            $this->line(sprintf('  %s  %s %s', (new DateTimeImmutable($month.'-01'))->format('M Y'), $bar, number_format($count)));
        }

        // Per collection: how many, and the window they were spread across
        // when their interval is shorter than the horizon.
        $byCollection = [];
        foreach ($candidates as $candidate) {
            $collection = $facts[$candidate->entryId]->collection;
            $window = min($horizonDays, $candidate->intervalDays);
            $byCollection[$collection]['count'] = ($byCollection[$collection]['count'] ?? 0) + 1;
            $byCollection[$collection]['windows'][$window] = true;
        }
        ksort($byCollection);

        $this->newLine();
        foreach ($byCollection as $collection => $info) {
            $windows = array_keys($info['windows']);
            sort($windows);
            $note = $windows === [$horizonDays]
                ? ''
                : '  spread across '.implode(' / ', array_map(fn (int $w): string => $w.' days', $windows));

            $this->components->twoColumnDetail("  {$collection}", number_format($info['count']).$note);
        }
    }
}
