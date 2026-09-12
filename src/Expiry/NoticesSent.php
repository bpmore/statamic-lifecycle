<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use Bpmore\StatamicLifecycle\Models\Notification;
use Bpmore\StatamicLifecycle\Notifications\ReminderType;

/** The notices already sent, read from the notifications log for the notice period to count from. */
final class NoticesSent
{
    /**
     * @param  iterable<string>  $entryIds
     * @return list<Notice>
     */
    public function for(iterable $entryIds): array
    {
        $ids = array_values(array_unique(is_array($entryIds) ? $entryIds : iterator_to_array($entryIds, false)));
        $notices = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Notification::query()
                ->whereIn('entry_id', $chunk)
                ->where('type', ReminderType::Notice->value)
                ->whereNotNull('action')
                ->get();

            foreach ($rows as $row) {
                $notices[] = new Notice($row->entry_id, $row->action, $row->due_at, $row->sent_at);
            }
        }

        return $notices;
    }
}
