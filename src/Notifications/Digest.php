<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

/**
 * Everything one person needs to hear today, in one place. An owner with
 * 300 stale pages gets one email listing 300 pages, never 300 emails
 * (spec §5): this is the one.
 */
final readonly class Digest
{
    /**
     * @param  list<DigestItem>  $items
     * @param  list<Reminder>  $reminders  what the items stand for, to be recorded once sent
     */
    public function __construct(
        public string $recipientId,
        public string $recipientName,
        public string $email,
        public array $items,
        public array $reminders,
    ) {}

    public function count(): int
    {
        return count($this->items);
    }

    /** @return list<DigestItem> */
    public function of(ReminderType $type): array
    {
        return array_values(array_filter($this->items, fn (DigestItem $item): bool => $item->type === $type));
    }

    public function subject(): string
    {
        $overdue = count($this->of(ReminderType::Overdue)) + count($this->of(ReminderType::Escalation));

        if ($overdue > 0 && $overdue === $this->count()) {
            return trans_choice('{1}A page you own is overdue for review|[2,*]:count pages you own are overdue for review', $overdue, ['count' => $overdue]);
        }

        return trans_choice('{1}A page you own needs a review|[2,*]:count pages you own need a review', $this->count(), ['count' => $this->count()]);
    }
}
