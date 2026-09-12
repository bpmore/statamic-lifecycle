<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Auth;

use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;

/**
 * The permissions (spec §8), and the one place each is asked about, so the
 * action, the panel button and the screen cannot disagree.
 *
 *   view lifecycle            the site-wide screens and numbers
 *   assign owners             say who owns a page
 *   attest reviews            mark a page reviewed, in one's own name
 *   configure expiry actions  put back what an automated action changed
 *
 * Seeing one's own content needs no permission: "My content" and the
 * sidebar panel are about pages the person is already allowed to edit.
 *
 * "Manage exemptions" from spec §8 is not registered yet. There is nothing
 * for it to guard — where an exemption is recorded is still undecided (see
 * PROGRESS.md) — and a permission that grants nothing is a lie in the roles
 * screen. It arrives with the feature.
 *
 * Expiry configuration itself lives in config/lifecycle.php, which only
 * whoever deploys the site can change. The permission covers the one thing
 * the control panel can do about expiry: undo it.
 */
final class Permissions
{
    public const VIEW = 'view lifecycle';

    public const ASSIGN_OWNERS = 'assign owners';

    public const ATTEST_REVIEWS = 'attest reviews';

    public const CONFIGURE_EXPIRY = 'configure expiry actions';

    /** The dashboard, the full listing, the widget's site-wide numbers. */
    public static function mayView(?User $user): bool
    {
        return $user !== null && (bool) $user->can(self::VIEW);
    }

    /** An attestation is in the reviewer's own name, on a page they can at least see. */
    public static function mayAttest(?User $user, Entry $entry): bool
    {
        return $user !== null && (bool) $user->can(self::ATTEST_REVIEWS) && (bool) $user->can('view', $entry);
    }

    /** Assigning writes the entry, so the person has to be allowed to edit it. */
    public static function mayAssign(?User $user, Entry $entry): bool
    {
        return $user !== null && (bool) $user->can(self::ASSIGN_OWNERS) && (bool) $user->can('edit', $entry);
    }

    /** Putting back writes the entry too. */
    public static function mayPutBack(?User $user, Entry $entry): bool
    {
        return $user !== null && (bool) $user->can(self::CONFIGURE_EXPIRY) && (bool) $user->can('edit', $entry);
    }
}
