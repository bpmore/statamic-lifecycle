<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Notification;
use Bpmore\StatamicLifecycle\Owners\EscalationContacts;
use Bpmore\StatamicLifecycle\Owners\FallbackOwners;
use Bpmore\StatamicLifecycle\State\Status;
use DateTimeImmutable;

/**
 * What is owed today, read from the tables: the entries whose review date
 * is inside the schedule's horizon or already past, with somebody to tell,
 * against what has already been sent for the same date.
 *
 * The planner decides; this only fetches and hands over.
 */
final class Reminders
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ReminderPlanner $planner,
        private readonly EscalationContacts $contacts,
        private readonly FallbackOwners $fallback,
    ) {}

    /** @return list<Reminder> */
    public function owed(DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);

        // Everything due within the horizon, plus everything overdue. Exempt
        // pages have no date; pages with nobody to tell have no reminder —
        // the dashboard is where those show.
        $states = EntryState::query()
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<', $today->modify('+'.($this->schedule->horizonDays() + 1).' days'))
            ->where('status', '!=', Status::Exempt->value)
            ->whereNotNull('owner_id')
            ->where('owner_type', 'user')
            ->orderBy('next_review_at')
            ->get(['entry_id', 'next_review_at', 'owner_id', 'site', 'collection']);

        if ($states->isEmpty()) {
            return [];
        }

        $contacts = $this->contacts->resolved();
        $lastResort = $this->fallback->resolved();

        $entries = $states->map(fn (EntryState $row): Reminderable => new Reminderable(
            $row->entry_id,
            $row->next_review_at,
            $row->owner_id,
            escalateTo: $contacts[$row->collection] ?? null,
            lastResort: $lastResort[$row->site] ?? null,
        ))->all();

        $sent = [];

        foreach ($states->pluck('entry_id')->chunk(500) as $ids) {
            foreach (Notification::query()->whereIn('entry_id', $ids->all())->get() as $row) {
                $sent[] = new SentReminder($row->entry_id, $row->type, $row->days_before, $row->due_at, $row->sent_at, $row->recipient);
            }
        }

        return $this->planner->plan($entries, $sent, $today);
    }
}
