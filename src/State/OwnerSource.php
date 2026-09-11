<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

/**
 * How the owner was arrived at. The dashboard has to be able to tell "a human
 * said so" from "we guessed" from "nobody, so the site's default".
 */
enum OwnerSource: string
{
    /** The content_owner field on the entry: a person chose. */
    case Assigned = 'assigned';

    /** The entry's last author, because nobody chose. Lives only in the table. */
    case Inferred = 'inferred';

    /** The site's owner-of-last-resort. */
    case Fallback = 'fallback';
}
