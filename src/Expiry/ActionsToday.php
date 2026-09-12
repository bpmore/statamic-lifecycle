<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use Bpmore\StatamicLifecycle\Models\AutomatedAction;
use DateTimeInterface;

/** How much of the day's cap is already spent, read from the action log. */
final class ActionsToday
{
    public function count(DateTimeInterface $day): int
    {
        return AutomatedAction::query()->appliedOn($day)->counted()->count();
    }
}
