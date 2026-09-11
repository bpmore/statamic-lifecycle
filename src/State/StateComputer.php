<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

use DateTimeImmutable;

/**
 * Given what is known about an entry, decide where it is in its review cycle.
 *
 * Pure: facts and prior state in, a state out, the clock passed in. Nothing
 * here reads config, touches a database or asks Statamic anything, which is
 * why it can be tested exhaustively in milliseconds and why the rest of the
 * addon can trust it.
 *
 * The three resolutions, in the order the spec gives them:
 *
 *   interval   entry field → blueprint → collection → global default
 *   owner      assigned (the field) → inferred (last author) → fallback (site)
 *   next date  unmet hard deadline → last review + interval → baseline date
 *              → last modification + interval
 */
final readonly class StateComputer
{
    public function __construct(private Policy $policy) {}

    public function compute(EntryFacts $facts, PriorState $prior, DateTimeImmutable $now): LifecycleState
    {
        [$ownerId, $ownerSource] = $this->resolveOwner($facts);

        if ($facts->exempt) {
            // Outside the cycle: no interval, no date, no status but this one.
            // The owner is still resolved, because somebody still owns it.
            return $this->state($facts, $prior, $now, $ownerId, $ownerSource, null, null, null, null, Status::Exempt);
        }

        [$days, $intervalSource] = $this->resolveInterval($facts);
        [$next, $nextSource] = $this->resolveNextReview($facts, $prior, $days);

        return $this->state($facts, $prior, $now, $ownerId, $ownerSource, $days, $intervalSource, $next, $nextSource, $this->status($next, $now));
    }

    /** @return array{?string, ?OwnerSource} */
    private function resolveOwner(EntryFacts $facts): array
    {
        if ($facts->ownerId !== null && $facts->ownerId !== '') {
            return [$facts->ownerId, OwnerSource::Assigned];
        }

        // The last author is a guess, and some guesses are known to be wrong:
        // whoever ran the import "last touched" every page on the site.
        if ($this->policy->mayInfer($facts->lastAuthorId)) {
            return [$facts->lastAuthorId, OwnerSource::Inferred];
        }

        if (isset($this->policy->fallbackOwners[$facts->site])) {
            return [$this->policy->fallbackOwners[$facts->site], OwnerSource::Fallback];
        }

        return [null, null];
    }

    /** @return array{int, IntervalSource} */
    private function resolveInterval(EntryFacts $facts): array
    {
        if (($days = $this->policy->intervalDays($facts->reviewInterval)) !== null) {
            return [$days, IntervalSource::Entry];
        }

        // The specific key first, so 'news/article' can differ from every
        // other collection's 'article'.
        $blueprint = $this->policy->blueprintIntervals["{$facts->collection}/{$facts->blueprint}"]
            ?? $this->policy->blueprintIntervals[$facts->blueprint]
            ?? null;

        if ($blueprint !== null) {
            return [$blueprint, IntervalSource::Blueprint];
        }

        if (isset($this->policy->collectionIntervals[$facts->collection])) {
            return [$this->policy->collectionIntervals[$facts->collection], IntervalSource::Collection];
        }

        return [$this->policy->defaultIntervalDays, IntervalSource::Global];
    }

    /** @return array{DateTimeImmutable, NextReviewSource} */
    private function resolveNextReview(EntryFacts $facts, PriorState $prior, int $days): array
    {
        // A deadline somebody typed wins over anything computed (spec §3) —
        // until a review is recorded on or after it, at which point it has
        // been met and the cadence takes over again.
        if ($facts->reviewBy !== null && ($prior->lastReviewedAt === null || $prior->lastReviewedAt < $facts->reviewBy)) {
            return [$facts->reviewBy, NextReviewSource::HardDate];
        }

        if ($prior->lastReviewedAt !== null) {
            return [$prior->lastReviewedAt->modify("+{$days} days"), NextReviewSource::LastReview];
        }

        if ($prior->baselineNextReviewAt !== null) {
            return [$prior->baselineNextReviewAt, NextReviewSource::Baseline];
        }

        // Never reviewed, never baselined: a page created after install, or a
        // site that has not baselined yet. The last modification is the last
        // time a person is known to have looked. On an un-baselined site this
        // makes old content overdue at once, which is honest — and is exactly
        // what lifecycle:baseline exists to spread out.
        return [$facts->lastModifiedAt->modify("+{$days} days"), NextReviewSource::LastModified];
    }

    private function status(DateTimeImmutable $next, DateTimeImmutable $now): Status
    {
        // Compared as days. A review is due on a day, not at a time.
        $today = $now->setTime(0, 0);
        $due = $next->setTime(0, 0);

        if ($today <= $due) {
            return $due <= $today->modify("+{$this->policy->dueSoonDays} days")
                ? Status::DueSoon
                : Status::Fresh;
        }

        $overdueDays = (int) $due->diff($today)->days;

        return $overdueDays >= $this->policy->expiredAfterDays
            ? Status::Expired
            : Status::Overdue;
    }

    private function state(
        EntryFacts $facts,
        PriorState $prior,
        DateTimeImmutable $now,
        ?string $ownerId,
        ?OwnerSource $ownerSource,
        ?int $days,
        ?IntervalSource $intervalSource,
        ?DateTimeImmutable $next,
        ?NextReviewSource $nextSource,
        Status $status,
    ): LifecycleState {
        return new LifecycleState(
            entryId: $facts->entryId,
            site: $facts->site,
            collection: $facts->collection,
            ownerId: $ownerId,
            // Users only in v1; the column exists so groups can follow.
            ownerType: $ownerId === null ? null : 'user',
            ownerSource: $ownerSource,
            intervalDays: $days,
            intervalSource: $intervalSource,
            lastReviewedAt: $prior->lastReviewedAt,
            lastReviewedBy: $prior->lastReviewedBy,
            nextReviewAt: $next,
            nextReviewSource: $nextSource,
            status: $status,
            baselined: $prior->baselined,
            computedAt: $now,
        );
    }
}
