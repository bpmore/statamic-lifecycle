<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use DateTimeImmutable;

/**
 * Decides, for today, which reminders are owed: which entries have reached
 * a stage of the schedule that has not yet been sent for their current
 * review date.
 *
 * Pure. The rules:
 *
 *   - Ahead of the date, only the latest stage to have begun is owed. A
 *     page that came due in five days with a 30 / 7 / 0 schedule gets the
 *     7-day reminder, not the 30-day one twenty-five days late.
 *   - Each stage is sent once per cycle. The cycle is the review date the
 *     reminder is about; a review moves the date, and the count starts over.
 *   - Once overdue, a reminder is owed at once, then every `overdueEvery`
 *     days, or once only when that is zero.
 *
 * Day-granular throughout, like the status it follows.
 */
final class ReminderPlanner
{
    public function __construct(private readonly Schedule $schedule) {}

    /**
     * @param  iterable<Reminderable>  $entries
     * @param  iterable<SentReminder>  $sent
     * @return list<Reminder>
     */
    public function plan(iterable $entries, iterable $sent, DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);

        // Index what has been sent by entry and cycle.
        $stagesSent = [];      // entry|cycle => [ "type:days" => true ]
        $lastOverdue = [];     // entry|cycle => latest sent_at

        foreach ($sent as $record) {
            $key = $record->entryId.'|'.($record->dueAt?->format('Y-m-d') ?? '');

            if ($record->type === ReminderType::Overdue) {
                if (! isset($lastOverdue[$key]) || $record->sentAt > $lastOverdue[$key]) {
                    $lastOverdue[$key] = $record->sentAt;
                }

                continue;
            }

            $stagesSent[$key][$record->type->value.':'.($record->daysBefore ?? '')] = true;
        }

        $reminders = [];

        foreach ($entries as $entry) {
            $due = $entry->dueAt->setTime(0, 0);
            $key = $entry->entryId.'|'.$due->format('Y-m-d');
            $daysUntil = (int) $today->diff($due)->days * ($due >= $today ? 1 : -1);

            if ($daysUntil > 0) {
                $stage = $this->schedule->stageFor($daysUntil);

                if ($stage !== null && ! isset($stagesSent[$key]['due_soon:'.$stage])) {
                    $reminders[] = new Reminder($entry->entryId, ReminderType::DueSoon, $stage, $due, $entry->recipient);
                }

                continue;
            }

            if ($daysUntil === 0) {
                if ($this->schedule->onDueDay && ! isset($stagesSent[$key]['due:0'])) {
                    $reminders[] = new Reminder($entry->entryId, ReminderType::Due, 0, $due, $entry->recipient);
                }

                continue;
            }

            // Overdue.
            $last = $lastOverdue[$key] ?? null;

            if ($last === null) {
                $reminders[] = new Reminder($entry->entryId, ReminderType::Overdue, null, $due, $entry->recipient);

                continue;
            }

            $every = $this->schedule->overdueEvery;

            if ($every > 0 && (int) $last->setTime(0, 0)->diff($today)->days >= $every) {
                $reminders[] = new Reminder($entry->entryId, ReminderType::Overdue, null, $due, $entry->recipient);
            }
        }

        return $reminders;
    }
}
