<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reviews;

use DateTimeImmutable;

/**
 * Every attestation for one entry, newest first: the per-page answer to
 * "when did a human last confirm this was accurate, and who were they?"
 */
final readonly class Timeline
{
    /** @param  list<ReviewRecord>  $reviews  newest first */
    public function __construct(
        public string $entryId,
        public array $reviews,
    ) {}

    public static function empty(string $entryId): self
    {
        return new self($entryId, []);
    }

    public function latest(): ?ReviewRecord
    {
        return $this->reviews[0] ?? null;
    }

    public function count(): int
    {
        return count($this->reviews);
    }

    public function isEmpty(): bool
    {
        return $this->reviews === [];
    }

    /** Has anyone confirmed it on or after this moment? */
    public function reviewedSince(DateTimeImmutable $since): bool
    {
        $latest = $this->latest();

        return $latest !== null && $latest->reviewedAt >= $since;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entry_id' => $this->entryId,
            'count' => $this->count(),
            'latest' => $this->latest()?->toArray(),
            'reviews' => array_map(fn (ReviewRecord $review): array => $review->toArray(), $this->reviews),
        ];
    }
}
