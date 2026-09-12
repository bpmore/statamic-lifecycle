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
        $notices = count($this->of(ReminderType::Notice));
        $escalated = count($this->of(ReminderType::Escalation));
        $overdue = count($this->of(ReminderType::Overdue));

        if ($notices > 0) {
            return trans_choice('{1}A page you own will change unless you review it|[2,*]:count pages you own will change unless you review them', $notices, ['count' => $notices]);
        }

        if ($escalated > 0 && $escalated === $this->count()) {
            return trans_choice('{1}An overdue page has been escalated to you|[2,*]:count overdue pages have been escalated to you', $escalated, ['count' => $escalated]);
        }

        if ($overdue > 0 && $overdue === $this->count()) {
            return trans_choice('{1}A page you own is overdue for review|[2,*]:count pages you own are overdue for review', $overdue, ['count' => $overdue]);
        }

        $own = $this->count() - $escalated;

        if ($escalated > 0) {
            return trans_choice('{1}A page needs your attention|[2,*]:count pages need your attention', $this->count(), ['count' => $this->count()]);
        }

        return trans_choice('{1}A page you own needs a review|[2,*]:count pages you own need a review', $own, ['count' => $own]);
    }
}
