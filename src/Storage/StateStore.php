<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Storage;

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\State\LifecycleState;
use Bpmore\StatamicLifecycle\State\PriorState;
use DateTimeImmutable;

/**
 * Reads and writes lifecycle_states, in the computation's own terms.
 *
 * The computation never sees a row and the row never sees the computation:
 * this is the only class that knows a LifecycleState is stored as one, and
 * that a PriorState is read from one.
 */
final class StateStore
{
    /** How many rows go to the database in one statement. */
    public const CHUNK = 500;

    public function prior(string $entryId): PriorState
    {
        return $this->priorFor([$entryId])[$entryId];
    }

    /**
     * Prior state for many entries in one query. Every requested ID is in the
     * result; an entry with no row gets PriorState::none().
     *
     * @param  iterable<string>  $entryIds
     * @return array<string, PriorState>
     */
    public function priorFor(iterable $entryIds): array
    {
        $ids = array_values(array_unique(is_array($entryIds) ? $entryIds : iterator_to_array($entryIds, false)));
        $prior = array_fill_keys($ids, PriorState::none());

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (EntryState::query()->whereIn('entry_id', $chunk)->get() as $row) {
                $prior[$row->entry_id] = new PriorState(
                    lastReviewedAt: $row->last_reviewed_at,
                    lastReviewedBy: $row->last_reviewed_by,
                    baselined: $row->baselined,
                    baselineNextReviewAt: $row->baseline_review_at,
                );
            }
        }

        return $prior;
    }

    /** @return array<string, true> entry IDs, of those given, that have a row */
    public function exists(iterable $entryIds): array
    {
        $ids = array_values(array_unique(is_array($entryIds) ? $entryIds : iterator_to_array($entryIds, false)));
        $found = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (EntryState::query()->whereIn('entry_id', $chunk)->pluck('entry_id') as $id) {
                $found[$id] = true;
            }
        }

        return $found;
    }

    /**
     * The entry ID of every row in scope, a chunk at a time, so 40,000 IDs
     * can be walked without 40,000 model instances.
     *
     * @return iterable<list<string>>
     */
    public function ids(?string $site = null, ?string $collection = null): iterable
    {
        $query = EntryState::query()->orderBy('entry_id');

        if ($site !== null) {
            $query->where('site', $site);
        }
        if ($collection !== null) {
            $query->where('collection', $collection);
        }

        $last = null;

        while (true) {
            $chunk = (clone $query)
                ->when($last !== null, fn ($q) => $q->where('entry_id', '>', $last))
                ->limit(self::CHUNK)
                ->pluck('entry_id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            if ($chunk === []) {
                return;
            }

            yield $chunk;

            $last = end($chunk);
        }
    }

    /**
     * Delete rows, in chunks. The review history in lifecycle_reviews is not
     * touched: a page that is gone was still reviewed by somebody on a date,
     * and that record outlives the page.
     *
     * @param  iterable<string>  $entryIds
     * @return int rows removed
     */
    public function remove(iterable $entryIds): int
    {
        $removed = 0;
        $ids = is_array($entryIds) ? $entryIds : iterator_to_array($entryIds, false);

        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            $removed += EntryState::query()->whereIn('entry_id', $chunk)->delete();
        }

        return $removed;
    }

    public function put(LifecycleState $state, ?DateTimeImmutable $baselineReviewAt = null): void
    {
        $this->putMany([[$state, $baselineReviewAt]]);
    }

    /**
     * Insert or replace, in chunks. 40,000 rows is 80 statements, not 40,000.
     *
     * @param  iterable<array{0: LifecycleState, 1: ?DateTimeImmutable}|LifecycleState>  $states  each a state, or
     *                                                                                            [state, baseline date]
     * @return int rows written
     */
    public function putMany(iterable $states): int
    {
        $written = 0;
        $rows = [];

        foreach ($states as $item) {
            [$state, $baselineReviewAt] = $item instanceof LifecycleState ? [$item, null] : $item;
            $rows[] = $this->row($state, $baselineReviewAt);

            if (count($rows) === self::CHUNK) {
                $written += $this->upsert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $written += $this->upsert($rows);
        }

        return $written;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function upsert(array $rows): int
    {
        EntryState::query()->upsert(
            $rows,
            ['entry_id'],
            array_values(array_diff(array_keys($rows[0]), ['entry_id'])),
        );

        return count($rows);
    }

    /** @return array<string, mixed> */
    private function row(LifecycleState $state, ?DateTimeImmutable $baselineReviewAt): array
    {
        return [
            'entry_id' => $state->entryId,
            'site' => $state->site,
            'collection' => $state->collection,
            'owner_id' => $state->ownerId,
            'owner_type' => $state->ownerType,
            'owner_source' => $state->ownerSource?->value,
            'interval_days' => $state->intervalDays,
            'interval_source' => $state->intervalSource?->value,
            'last_reviewed_at' => $state->lastReviewedAt?->format('Y-m-d H:i:s'),
            'last_reviewed_by' => $state->lastReviewedBy,
            'next_review_at' => $state->nextReviewAt?->format('Y-m-d H:i:s'),
            'next_review_source' => $state->nextReviewSource?->value,
            'status' => $state->status->value,
            'baselined' => $state->baselined,
            'baseline_review_at' => $baselineReviewAt?->format('Y-m-d H:i:s'),
            'computed_at' => $state->computedAt->format('Y-m-d H:i:s'),
        ];
    }
}
