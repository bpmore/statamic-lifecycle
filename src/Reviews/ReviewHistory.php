<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reviews;

use Bpmore\StatamicLifecycle\Models\Review;
use Statamic\Facades\User;

/**
 * Reads lifecycle_reviews into timelines, resolving who to names.
 *
 * The one place a Review row becomes a ReviewRecord, so the sidebar panel,
 * the dashboard, the Antlers tags and the report all describe an
 * attestation the same way.
 */
final class ReviewHistory
{
    public function for(string $entryId): Timeline
    {
        return $this->forMany([$entryId])[$entryId];
    }

    /**
     * Timelines for many entries in one query, with each reviewer looked up
     * once. Every requested ID is in the result; an entry nobody has reviewed
     * gets an empty timeline.
     *
     * @param  iterable<string>  $entryIds
     * @return array<string, Timeline>
     */
    public function forMany(iterable $entryIds): array
    {
        $ids = array_values(array_unique(is_array($entryIds) ? $entryIds : iterator_to_array($entryIds, false)));

        if ($ids === []) {
            return [];
        }

        $rows = Review::query()->whereIn('entry_id', $ids)->newestFirst()->get();

        // Existence and display name are two questions: a user with no name
        // fields still exists, and is shown by email.
        $users = [];
        foreach ($rows->pluck('reviewed_by')->unique() as $userId) {
            $user = User::find($userId);
            $users[$userId] = $user === null ? null : ($user->name() ?: (string) $user->email());
        }

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->entry_id][] = new ReviewRecord(
                id: (int) $row->id,
                entryId: $row->entry_id,
                reviewedBy: $row->reviewed_by,
                // The stored ID, when the person is gone: a record that named
                // nobody would be a weaker record.
                reviewerName: $users[$row->reviewed_by] ?? $row->reviewed_by,
                reviewerExists: $users[$row->reviewed_by] !== null,
                reviewedAt: $row->reviewed_at,
                outcome: $row->outcome,
                note: $row->note,
                contentChanged: $row->content_changed,
                revisionRef: $row->entry_revision_ref,
                recordedAt: $row->created_at,
                source: $row->source,
            );
        }

        $timelines = [];
        foreach ($ids as $id) {
            $timelines[$id] = new Timeline($id, $grouped[$id] ?? []);
        }

        return $timelines;
    }
}
