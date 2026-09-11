<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reviews;

use DateTimeImmutable;

/**
 * One attestation as a screen or a report wants it: the person resolved to
 * a name, the outcome to its label, the dates as dates.
 *
 * A reviewer who has since left the site is still the reviewer. Their ID is
 * kept, their name is what is known, and `reviewerExists` says which.
 */
final readonly class ReviewRecord
{
    public function __construct(
        public int $id,
        public string $entryId,
        public string $reviewedBy,
        public string $reviewerName,
        public bool $reviewerExists,
        public DateTimeImmutable $reviewedAt,
        public Outcome $outcome,
        public ?string $note,
        public bool $contentChanged,
        public ?string $revisionRef,
        public DateTimeImmutable $recordedAt,
    ) {}

    /** @return array<string, mixed> JSON-friendly, for the control panel and templates */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'entry_id' => $this->entryId,
            'reviewed_by' => $this->reviewedBy,
            'reviewer_name' => $this->reviewerName,
            'reviewer_exists' => $this->reviewerExists,
            'reviewed_at' => $this->reviewedAt->format(DATE_ATOM),
            'outcome' => $this->outcome->value,
            'outcome_label' => $this->outcome->label(),
            'note' => $this->note,
            'content_changed' => $this->contentChanged,
            'revision_ref' => $this->revisionRef,
            'recorded_at' => $this->recordedAt->format(DATE_ATOM),
        ];
    }
}
