<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/** What an expiry action does to a live page. Stored in lifecycle_actions.action. */
enum ActionType: string
{
    /** Exposes a variable the template may render. Lifecycle never injects markup. */
    case Banner = 'banner';

    /** Asks search engines to drop the page. */
    case Noindex = 'noindex';

    /** Takes the page down. */
    case Unpublish = 'unpublish';

    /** Undoes one of the above. Never refused by the rails: a way back is not a risk. */
    case Restore = 'restore';

    public function label(): string
    {
        return match ($this) {
            self::Banner => __('show a banner'),
            self::Noindex => __('hide from search engines'),
            self::Unpublish => __('unpublish'),
            self::Restore => __('restore'),
        };
    }
}
