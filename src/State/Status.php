<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

/**
 * Where an entry is in its review cycle. Stored in lifecycle_states.status.
 *
 * Day-granular: a review cadence is measured in months, and nobody wants a
 * page to flip to overdue at 14:00 because that is when it was last reviewed.
 */
enum Status: string
{
    case Fresh = 'fresh';

    /** Due within the policy's window, or due today. */
    case DueSoon = 'due_soon';

    case Overdue = 'overdue';

    /** Overdue for long enough that expiry actions, where enabled, may apply. */
    case Expired = 'expired';

    /** Deliberately outside the cycle. No next review, no reminders. */
    case Exempt = 'exempt';
}
