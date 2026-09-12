<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use Bpmore\StatamicLifecycle\Models\AutomatedAction;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Notifications\Reminder;
use Bpmore\StatamicLifecycle\Notifications\ReminderType;
use Bpmore\StatamicLifecycle\State\Status;
use DateTimeImmutable;
use Statamic\Facades\Entry;

/**
 * Works out what a run of expiry actions would do, by putting every
 * candidate through every rail, in an order that matters:
 *
 *   1. never-touch list and navigation check — pages that are simply not
 *      candidates, so they do not count towards anything;
 *   2. the circuit breaker — before anyone is warned, so 400 owners are not
 *      told about an unpublish the breaker would then refuse;
 *   3. the notice period — what may happen now, what waits, who to tell;
 *   4. the daily cap — on what may happen now.
 *
 * A candidate is an expired entry, in a collection that has opted into an
 * action, that has not had that action this cycle — applied or put back:
 * a change a person undid is not made again until a review starts a new
 * cycle. A page with nobody to notify can never pass the notice period,
 * and is reported rather than quietly skipped.
 */
final class ExpiryPlanner
{
    public function __construct(
        private readonly EnabledActions $enabled,
        private readonly NeverTouch $neverTouch,
        private readonly NavigationCheck $navigation,
        private readonly NavigationReferences $references,
        private readonly CircuitBreaker $breaker,
        private readonly NoticePeriod $noticePeriod,
        private readonly NoticesSent $noticesSent,
        private readonly DailyCap $cap,
        private readonly ActionsToday $actionsToday,
    ) {}

    /**
     * @param  ?string  $collection  only this collection
     * @param  list<string>  $allowInNavigation  entry IDs allowed past the navigation check for this run
     */
    public function plan(DateTimeImmutable $now, ?string $collection = null, array $allowInNavigation = []): ExpiryPlan
    {
        $collections = array_keys($this->enabled->byCollection);

        if ($collection !== null) {
            $collections = in_array($collection, $collections, true) ? [$collection] : [];
        }

        if ($collections === []) {
            return new ExpiryPlan([], [], [], [], [], [], [], 0);
        }

        // ---- Candidates -----------------------------------------------------

        $rows = EntryState::query()
            ->whereIn('collection', $collections)
            ->where('status', Status::Expired->value)
            ->whereNotNull('next_review_at')
            ->orderBy('next_review_at')   // most overdue first: the order the cap honours
            ->get();

        $candidates = [];
        $unowned = [];
        $uris = [];
        $applied = $this->appliedThisCycle($rows->pluck('entry_id')->all());

        foreach ($rows as $row) {
            foreach ($this->enabled->for($row->collection) as $action) {
                if (isset($applied[$row->entry_id][$action->value]) && $applied[$row->entry_id][$action->value] >= $row->next_review_at) {
                    continue;
                }

                $candidate = new Candidate($row->entry_id, $row->site, $row->collection, $action, $row->next_review_at);

                if ($row->owner_id === null || $row->owner_type !== 'user') {
                    $unowned[] = $candidate;

                    continue;
                }

                $candidates[] = $candidate;
                $uris[$row->entry_id] ??= Entry::find($row->entry_id)?->uri();
            }
        }

        // ---- Rails ----------------------------------------------------------

        $touch = $this->neverTouch->check($candidates, $uris);
        $refused = $touch->refused;

        $nav = $this->navigation->check(
            $touch->allowed,
            $this->references->for(array_unique(array_map(fn (Candidate $c): string => $c->entryId, $touch->allowed))),
            $allowInNavigation,
        );
        $refused = [...$refused, ...$nav->refused];

        $breaker = $this->breaker->check($nav->allowed, $this->collectionSizes($collections));

        $notice = $this->noticePeriod->check(
            $breaker->allowed,
            $this->noticesSent->for(array_unique(array_map(fn (Candidate $c): string => $c->entryId, $breaker->allowed))),
            $now,
        );

        $capped = $this->cap->check($notice->allowed, $this->actionsToday->count($now));

        // ---- Notices to send ------------------------------------------------

        $owners = $rows->pluck('owner_id', 'entry_id');
        $notices = [];

        foreach ($notice->toNotify as $candidate) {
            $notices[] = new Reminder(
                entryId: $candidate->entryId,
                type: ReminderType::Notice,
                daysBefore: null,
                dueAt: $candidate->dueAt,
                recipient: (string) $owners[$candidate->entryId],
                ownerId: (string) $owners[$candidate->entryId],
                action: $candidate->action,
                actionableFrom: $this->noticePeriod->actionableFrom($now),
            );
        }

        return new ExpiryPlan(
            allowed: $capped->allowed,
            notices: $notices,
            waiting: $notice->waiting,
            deferred: $capped->deferred,
            refused: $refused,
            trips: $breaker->trips,
            unowned: $unowned,
            expired: $rows->count(),
        );
    }

    /**
     * When each action last happened to each entry, applied or since put
     * back — so a change a person undid is not made again this cycle.
     *
     * @param  list<string>  $entryIds
     * @return array<string, array<string, DateTimeImmutable>> entry => action => latest applied_at
     */
    private function appliedThisCycle(array $entryIds): array
    {
        $applied = [];

        foreach (array_chunk($entryIds, 500) as $chunk) {
            $rows = AutomatedAction::query()
                ->whereIn('entry_id', $chunk)
                ->where('action', '!=', ActionType::Restore->value)
                ->get(['entry_id', 'action', 'applied_at']);

            foreach ($rows as $row) {
                $current = $applied[$row->entry_id][$row->action->value] ?? null;

                if ($current === null || $row->applied_at > $current) {
                    $applied[$row->entry_id][$row->action->value] = $row->applied_at;
                }
            }
        }

        return $applied;
    }

    /** @param  list<string>  $collections  @return array<string, int> published entries per collection */
    private function collectionSizes(array $collections): array
    {
        return EntryState::query()
            ->whereIn('collection', $collections)
            ->selectRaw('collection, count(*) as n')
            ->groupBy('collection')
            ->pluck('n', 'collection')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }
}
