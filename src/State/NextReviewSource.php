<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

/**
 * Where the next review date came from, so a panel can say "1 March 2027,
 * from the deadline you set" rather than just a date.
 */
enum NextReviewSource: string
{
    /** The entry's review_by field: an explicit deadline, not yet met. */
    case HardDate = 'hard_date';

    /** The last recorded review plus the interval. */
    case LastReview = 'last_review';

    /** The date lifecycle:baseline spread this entry to, never reviewed since. */
    case Baseline = 'baseline';

    /** Never reviewed and never baselined: the last modification plus the interval. */
    case LastModified = 'last_modified';
}
