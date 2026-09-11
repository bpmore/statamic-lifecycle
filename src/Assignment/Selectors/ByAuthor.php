<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment\Selectors;

use Bpmore\StatamicLifecycle\Assignment\Selection;
use InvalidArgumentException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * "Sam has left; everything Sam wrote or last edited goes to Priya."
 *
 * Both the entry's author field, where the blueprint has one, and who
 * last saved it. A person's content is what they wrote and what they
 * kept up, and either alone would miss half of it.
 *
 * A scan rather than a query, because an author field may hold a list.
 */
final class ByAuthor
{
    public function select(string $handle, ?string $site = null): Selection
    {
        $user = User::find($handle) ?? (str_contains($handle, '@') ? User::findByEmail($handle) : null);

        if ($user === null) {
            throw new InvalidArgumentException("There is no user with the ID or email [{$handle}].");
        }

        $id = (string) $user->id();
        $query = Entry::query();

        if ($site !== null) {
            $query->where('site', $site);
        }

        $entries = $query->lazy(500)
            ->filter(fn (EntryContract $entry): bool => in_array($id, array_map('strval', (array) $entry->get('author')), true)
                || (string) $entry->get('updated_by') === $id)
            ->values()
            ->collect();

        $name = $user->name() ?: (string) $user->email();

        return new Selection(
            $site === null
                ? __('every entry written or last edited by :name', ['name' => $name])
                : __('every entry written or last edited by :name in the :site site', ['name' => $name, 'site' => $site]),
            $entries,
        );
    }
}
