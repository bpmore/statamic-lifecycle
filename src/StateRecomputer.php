<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle;

use Bpmore\StatamicLifecycle\Entries\EntryFactsReader;
use Bpmore\StatamicLifecycle\Jobs\PruneStates;
use Bpmore\StatamicLifecycle\Jobs\RecomputeStates;
use Bpmore\StatamicLifecycle\Reporting\Snapshots;
use Bpmore\StatamicLifecycle\State\StateComputer;
use Bpmore\StatamicLifecycle\Storage\StateStore;
use DateTimeImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\LazyCollection;
use Statamic\Facades\Entry;

/**
 * Brings lifecycle_states up to date with the site.
 *
 * Enumerate, chunk, batch — the suite's job pattern. Runs daily; an entry's
 * state moves with the calendar (fresh becomes due soon becomes overdue)
 * and with edits (a new owner, a new interval, a new deadline), and nothing
 * else rewrites the row.
 *
 * Only published entries are computed. A row exists for an entry exactly
 * while it is published: what is not published does not go stale on the
 * live site, and what is gone has nothing to be stale. The prune pass, last
 * in every run, removes the rows that no longer have a live entry behind
 * them. The review history is never touched by any of this.
 */
final class StateRecomputer
{
    public function __construct(
        private readonly StateStore $store,
        private readonly StateComputer $computer,
        private readonly EntryFactsReader $reader,
        private readonly int $chunkSize = 250,
        private readonly ?string $queue = null,
    ) {}

    /**
     * The IDs of every published entry in scope, cheaply.
     *
     * @return LazyCollection<int, string>
     */
    public function entries(?string $site = null, ?string $collection = null): LazyCollection
    {
        $query = Entry::query();

        // Statamic insists the collection clause come before the status one.
        if ($collection !== null) {
            $query->where('collection', $collection);
        }
        if ($site !== null) {
            $query->where('site', $site);
        }

        return $query->whereStatus('published')->lazy(1000)->map(fn ($entry): string => (string) $entry->id());
    }

    /**
     * Queue the whole site, or one slice of it.
     *
     * The batch allows failures so a chunk that dies takes only itself down.
     */
    public function queue(?string $site = null, ?string $collection = null): QueuedRecompute
    {
        $entries = 0;

        $jobs = $this->entries($site, $collection)
            ->chunk($this->chunkSize)
            ->map(function (LazyCollection $chunk) use (&$entries): RecomputeStates {
                $ids = array_values($chunk->all());
                $entries += count($ids);

                return new RecomputeStates($ids);
            })
            ->all();

        // Last, the sweep for rows whose entry is gone or no longer live. It
        // enumerates for itself when it runs, so it does not matter whether
        // the workers got to it before or after the chunks.
        $jobs[] = new PruneStates($site, $collection);

        $batch = Bus::batch($jobs)
            ->name('Lifecycle recompute')
            ->allowFailures();

        if ($this->queue !== null) {
            $batch->onQueue($this->queue);
        }

        return new QueuedRecompute($batch->dispatch(), $entries, count($jobs) - 1);
    }

    /**
     * Rows in scope with no published entry behind them: the entry was
     * deleted (by hand, by git, by anything that skipped the event), or it
     * was unpublished and is no longer governed.
     *
     * @return list<string>
     */
    public function orphans(?string $site = null, ?string $collection = null): array
    {
        $live = array_fill_keys($this->entries($site, $collection)->all(), true);
        $orphans = [];

        foreach ($this->store->ids($site, $collection) as $chunk) {
            foreach ($chunk as $id) {
                if (! isset($live[$id])) {
                    $orphans[] = $id;
                }
            }
        }

        return $orphans;
    }

    /** @return int rows removed */
    public function prune(?string $site = null, ?string $collection = null): int
    {
        $removed = $this->store->remove($this->orphans($site, $collection));

        // The sweep is the last thing a run does, so the day's numbers are
        // taken here, once everything is up to date. A scoped run records
        // them too: the numbers are the whole site's whatever the scope.
        app(Snapshots::class)->record(new DateTimeImmutable);

        return $removed;
    }

    /**
     * Recompute here and now, without a queue. `$onChunk` is told how many
     * entries each chunk covered, so a command can draw progress.
     *
     * @param  (callable(int): void)|null  $onChunk
     * @return int entries recomputed
     */
    public function run(?string $site = null, ?string $collection = null, ?callable $onChunk = null): int
    {
        $done = 0;

        foreach ($this->entries($site, $collection)->chunk($this->chunkSize) as $chunk) {
            $written = $this->recompute(array_values($chunk->all()), new DateTimeImmutable);
            $done += $written;

            if ($onChunk !== null) {
                $onChunk($written);
            }
        }

        return $done;
    }

    /**
     * The unit of work, for a job or a chunk of a sync run.
     *
     * An entry deleted or unpublished since it was enumerated is skipped, not
     * an error: a run over a live site takes a while and people keep working.
     *
     * @param  list<string>  $entryIds
     * @return int rows written
     */
    public function recompute(array $entryIds, DateTimeImmutable $now): int
    {
        if ($entryIds === []) {
            return 0;
        }

        $facts = [];

        foreach (Entry::query()->whereIn('id', $entryIds)->get() as $entry) {
            if ($entry->status() !== 'published') {
                continue;
            }

            $entryFacts = $this->reader->read($entry);
            $facts[$entryFacts->entryId] = $entryFacts;
        }

        if ($facts === []) {
            return 0;
        }

        $prior = $this->store->priorFor(array_keys($facts));
        $states = [];

        foreach ($facts as $id => $entryFacts) {
            // The baseline date travels with the row. A recompute that
            // dropped it would collapse the spread on the first night.
            $states[] = [$this->computer->compute($entryFacts, $prior[$id], $now), $prior[$id]->baselineNextReviewAt];
        }

        return $this->store->putMany($states);
    }
}
