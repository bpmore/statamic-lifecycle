<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reviews;

use Bpmore\StatamicLifecycle\Entries\EntryFactsReader;
use Bpmore\StatamicLifecycle\Models\Review;
use Bpmore\StatamicLifecycle\State\PriorState;
use Bpmore\StatamicLifecycle\State\StateComputer;
use Bpmore\StatamicLifecycle\Storage\StateStore;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry;

/**
 * The attestation write path: the thing people pay for, and the single most
 * important architectural rule in the product.
 *
 * Marking a page reviewed writes a row to lifecycle_reviews and brings the
 * page's row in lifecycle_states up to date. It does not write to the entry.
 * Not a field, not a timestamp, not a revision. An attestation is not a
 * content change; if it were saved as one, every review would create a
 * revision, could re-trigger other addons' checks, and would fill the git
 * history with noise that is not content. Table only. Always.
 */
final class RecordReview
{
    public function __construct(
        private readonly StateStore $store,
        private readonly StateComputer $computer,
        private readonly EntryFactsReader $reader,
    ) {}

    /**
     * @param  ?DateTimeImmutable  $at  when the review happened; now unless it is being recorded after the fact
     */
    public function record(Entry $entry, string $reviewerId, Outcome $outcome, ?string $note = null, ?DateTimeImmutable $at = null): Review
    {
        $now = Carbon::now()->toImmutable();
        $at ??= $now;
        $entryId = (string) $entry->id();

        // Was the content touched between the previous review and this one?
        // A fact about the interval, not a claim about this review — the
        // reviewer's own edits are what `updated` is for.
        $previous = Review::query()->forEntry($entryId)->newestFirst()->first();
        $contentChanged = $previous !== null
            && $entry->lastModified()->toImmutable() > $previous->reviewed_at;

        $review = Review::query()->create([
            'entry_id' => $entryId,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => $at,
            'outcome' => $outcome,
            'note' => $this->tidy($note),
            'content_changed' => $contentChanged,
            'entry_revision_ref' => $this->revisionRef($entry),
            'created_at' => $now,
        ]);

        // A state row exists for an entry while it is published. A draft
        // can be reviewed — the record is the record — but has no state.
        if ($entry->status() === 'published') {
            $prior = $this->store->prior($entryId);

            $state = $this->computer->compute(
                $this->reader->read($entry),
                new PriorState(
                    lastReviewedAt: $at,
                    lastReviewedBy: $reviewerId,
                    baselined: $prior->baselined,
                    baselineNextReviewAt: $prior->baselineNextReviewAt,
                ),
                $now,
            );

            $this->store->put($state, $prior->baselineNextReviewAt);
        }

        return $review;
    }

    /**
     * Which version of the entry was reviewed, so the record can be read
     * against the right one later: the latest revision where revisions are
     * on, the last-modified time where they are not.
     */
    private function revisionRef(Entry $entry): ?string
    {
        if ($entry->revisionsEnabled()) {
            $revision = $entry->latestRevision();

            return $revision === null ? null : 'revision:'.$revision->id();
        }

        return 'modified:'.$entry->lastModified()->getTimestamp();
    }

    private function tidy(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }
}
