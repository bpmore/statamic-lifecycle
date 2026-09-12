<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

/** The kinds of reminder, as stored in lifecycle_notifications.type. */
enum ReminderType: string
{
    /** Some days ahead of the review date. */
    case DueSoon = 'due_soon';

    /** On the day. */
    case Due = 'due';

    /** After it, repeated on a cadence while it stays overdue. */
    case Overdue = 'overdue';

    /** To somebody other than the owner, once the owner has had long enough. */
    case Escalation = 'escalation';
}
