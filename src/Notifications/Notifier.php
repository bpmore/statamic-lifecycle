<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use Bpmore\StatamicLifecycle\Models\Notification;
use DateTimeImmutable;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Throwable;

/**
 * Turns what is owed into one email per person, sends it, and writes down
 * what went out — in that order, so a send that fails is owed again
 * tomorrow and a send that worked is never repeated.
 *
 * The grouping is the whole point (spec §5): a person with 300 pages due
 * gets one message listing 300 pages. One per entry gets a sending domain
 * marked as spam and an addon uninstalled the same morning.
 */
final class Notifier
{
    public function __construct(private readonly ReminderPreferences $preferences) {}

    /**
     * @param  list<Reminder>  $owed
     * @return list<Digest> one per person, with something to say
     */
    public function digests(array $owed): array
    {
        $byRecipient = [];

        foreach ($owed as $reminder) {
            $byRecipient[$reminder->recipient][] = $reminder;
        }

        $digests = [];

        foreach ($byRecipient as $recipientId => $reminders) {
            $user = User::find($recipientId);
            $email = (string) ($user?->email() ?? '');

            if ($user === null || $email === '') {
                continue;   // reported by the sender as skipped
            }

            $items = [];
            $kept = [];

            foreach ($reminders as $reminder) {
                $entry = Entry::find($reminder->entryId);

                if ($entry === null) {
                    continue;   // gone since the row was computed; the sweep will take the row
                }

                $ownerName = null;

                if ($reminder->type === ReminderType::Escalation && $reminder->ownerId !== null) {
                    $owner = User::find($reminder->ownerId);
                    $ownerName = $owner === null ? $reminder->ownerId : ($owner->name() ?: (string) $owner->email());
                }

                $items[] = new DigestItem(
                    entryId: $reminder->entryId,
                    title: (string) ($entry->get('title') ?: $entry->slug()),
                    editUrl: $entry->editUrl(),
                    collection: (string) ($entry->collection()?->title() ?? $entry->collectionHandle()),
                    dueAt: $reminder->dueAt,
                    type: $reminder->type,
                    daysBefore: $reminder->daysBefore,
                    ownerName: $ownerName,
                    action: $reminder->action,
                    actionableFrom: $reminder->actionableFrom,
                );
                $kept[] = $reminder;
            }

            if ($items === []) {
                continue;
            }

            // What is about to happen first, then overdue, due today, due soon; nearest date first within each.
            $order = [ReminderType::Notice->value => 0, ReminderType::Escalation->value => 1, ReminderType::Overdue->value => 1, ReminderType::Due->value => 2, ReminderType::DueSoon->value => 3];
            usort($items, fn (DigestItem $a, DigestItem $b): int => [$order[$a->type->value], $a->dueAt] <=> [$order[$b->type->value], $b->dueAt]);

            $digests[] = new Digest((string) $recipientId, $user->name() ?: $email, $email, $items, $kept);
        }

        return $digests;
    }

    /**
     * Which digests go today and which are held, by each person's own
     * preference: none for somebody who has muted email, and one a week at
     * most for somebody who asked for that — a week since their last one,
     * whatever it listed.
     *
     * @param  list<Digest>  $digests
     * @return array{send: list<Digest>, held: array<string, string>} held is email => why
     */
    public function deliverable(array $digests, DateTimeImmutable $today): array
    {
        $send = [];
        $held = [];

        foreach ($digests as $digest) {
            $frequency = $this->preferences->frequencyFor($digest->recipientId);

            if ($frequency === Frequency::Never) {
                $held[$digest->email] = 'has muted review reminders';

                continue;
            }

            if ($frequency === Frequency::Weekly) {
                $last = Notification::query()->where('recipient', $digest->recipientId)->max('sent_at');

                if ($last !== null) {
                    $last = new DateTimeImmutable((string) $last);
                    $days = (int) $last->setTime(0, 0)->diff($today->setTime(0, 0))->days;

                    if ($days < 7) {
                        $held[$digest->email] = 'gets a weekly digest, and had one '.($days === 0 ? 'today' : ($days === 1 ? 'yesterday' : $days.' days ago'));

                        continue;
                    }
                }
            }

            $send[] = $digest;
        }

        return ['send' => $send, 'held' => $held];
    }

    /**
     * Send each digest, and record its reminders only once it has gone.
     *
     * @param  list<Digest>  $digests
     * @return array{sent: int, reminders: int, failed: list<string>}
     */
    public function send(array $digests, DateTimeImmutable $now): array
    {
        $sent = 0;
        $recorded = 0;
        $failed = [];

        foreach ($digests as $digest) {
            try {
                Mail::to($digest->email, $digest->recipientName)->send(new DigestMail($digest));
            } catch (Throwable $e) {
                report($e);
                $failed[] = $digest->email;

                continue;
            }

            $this->record($digest, $now);
            $sent++;
            $recorded += count($digest->reminders);
        }

        return ['sent' => $sent, 'reminders' => $recorded, 'failed' => $failed];
    }

    /** Who was owed a digest but could not be sent one, and why. @param  list<Reminder>  $owed  @return array<string, string> */
    public function unreachable(array $owed): array
    {
        $reasons = [];

        foreach (array_unique(array_map(fn (Reminder $r): string => $r->recipient, $owed)) as $recipientId) {
            $user = User::find($recipientId);

            if ($user === null) {
                $reasons[$recipientId] = 'no longer a user';
            } elseif ((string) $user->email() === '') {
                $reasons[$recipientId] = 'no email address';
            }
        }

        return $reasons;
    }

    private function record(Digest $digest, DateTimeImmutable $now): void
    {
        $rows = [];

        foreach ($digest->reminders as $reminder) {
            $rows[] = [
                'entry_id' => $reminder->entryId,
                'type' => $reminder->type->value,
                'action' => $reminder->action?->value,
                'days_before' => $reminder->daysBefore,
                'due_at' => $reminder->dueAt->format('Y-m-d H:i:s'),
                'recipient' => $digest->recipientId,
                'sent_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Notification::query()->insert($chunk);
        }
    }
}
