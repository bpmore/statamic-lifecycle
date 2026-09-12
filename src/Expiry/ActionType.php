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

    /** As a verb phrase with its object, so "Lifecycle will …" reads right. */
    public function label(): string
    {
        return match ($this) {
            self::Banner => __('show a banner on it'),
            self::Noindex => __('hide it from search engines'),
            self::Unpublish => __('unpublish it'),
            self::Restore => __('put it back'),
        };
    }
}
