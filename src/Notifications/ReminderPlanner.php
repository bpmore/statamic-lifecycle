<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use Bpmore\StatamicLifecycle\State\Days;
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
 *   - After `escalateAfter` days overdue the collection's escalation contact
 *     hears too, and after `lastResortAfter` the site's owner of last
 *     resort — each on the same cadence, each once per cycle per person,
 *     and never somebody who is already hearing about it as the owner.
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
        $lastOverdue = [];     // entry|cycle => latest overdue sent_at
        $lastEscalation = [];  // entry|cycle|recipient => latest escalation sent_at

        foreach ($sent as $record) {
            $key = $record->entryId.'|'.($record->dueAt?->format('Y-m-d') ?? '');

            if ($record->type === ReminderType::Overdue) {
                if (! isset($lastOverdue[$key]) || $record->sentAt > $lastOverdue[$key]) {
                    $lastOverdue[$key] = $record->sentAt;
                }

                continue;
            }

            if ($record->type === ReminderType::Escalation) {
                $who = $key.'|'.$record->recipient;

                if (! isset($lastEscalation[$who]) || $record->sentAt > $lastEscalation[$who]) {
                    $lastEscalation[$who] = $record->sentAt;
                }

                continue;
            }

            $stagesSent[$key][$record->type->value.':'.($record->daysBefore ?? '')] = true;
        }

        $reminders = [];

        foreach ($entries as $entry) {
            $due = $entry->dueAt->setTime(0, 0);
            $key = $entry->entryId.'|'.$due->format('Y-m-d');
            $daysUntil = Days::between($today, $due) * ($due >= $today ? 1 : -1);

            if ($daysUntil > 0) {
                $stage = $this->schedule->stageFor($daysUntil);

                if ($stage !== null && ! isset($stagesSent[$key]['due_soon:'.$stage])) {
                    $reminders[] = new Reminder($entry->entryId, ReminderType::DueSoon, $stage, $due, $entry->recipient, $entry->recipient);
                }

                continue;
            }

            if ($daysUntil === 0) {
                if ($this->schedule->onDueDay && ! isset($stagesSent[$key]['due:0'])) {
                    $reminders[] = new Reminder($entry->entryId, ReminderType::Due, 0, $due, $entry->recipient, $entry->recipient);
                }

                continue;
            }

            // Overdue: the owner, on the cadence.
            if ($this->onCadence($lastOverdue[$key] ?? null, $today)) {
                $reminders[] = new Reminder($entry->entryId, ReminderType::Overdue, null, $due, $entry->recipient, $entry->recipient);
            }

            // Then, once the owner has had long enough, other people — each
            // on the same cadence, and never somebody already hearing about
            // it in another role.
            $daysOverdue = -$daysUntil;
            $told = [$entry->recipient];

            foreach ([
                [$this->schedule->escalateAfter, $entry->escalateTo],
                [$this->schedule->lastResortAfter, $entry->lastResort],
            ] as [$after, $person]) {
                if ($person === null || $person === '' || $daysOverdue < $after || in_array($person, $told, true)) {
                    continue;
                }

                $told[] = $person;

                if ($this->onCadence($lastEscalation[$key.'|'.$person] ?? null, $today)) {
                    $reminders[] = new Reminder($entry->entryId, ReminderType::Escalation, null, $due, $person, $entry->recipient);
                }
            }
        }

        return $reminders;
    }

    /** At once when never sent; then every `overdueEvery` days; never again when that is zero. */
    private function onCadence(?DateTimeImmutable $last, DateTimeImmutable $today): bool
    {
        if ($last === null) {
            return true;
        }

        $every = $this->schedule->overdueEvery;

        return $every > 0 && Days::between($last->setTime(0, 0), $today) >= $every;
    }
}
