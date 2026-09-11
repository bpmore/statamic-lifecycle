<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment\Selectors;

use Bpmore\StatamicLifecycle\Assignment\Selection;
use InvalidArgumentException;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * "Every policy page belongs to the compliance office."
 *
 * Matched on the blueprint an entry actually uses — Statamic resolves the
 * collection's default for entries that do not name one — within one
 * collection when given, otherwise wherever a blueprint by that handle is.
 */
final class WithBlueprint
{
    public function select(string $blueprint, ?string $collection = null, ?string $site = null): Selection
    {
        if ($collection !== null) {
            $found = Collection::find($collection);

            if ($found === null) {
                throw new InvalidArgumentException("There is no collection called [{$collection}].");
            }

            if (! $found->entryBlueprints()->contains(fn ($candidate): bool => $candidate->handle() === $blueprint)) {
                throw new InvalidArgumentException("The [{$collection}] collection has no blueprint called [{$blueprint}].");
            }
        } elseif (! Collection::all()->contains(fn ($candidate): bool => $candidate->entryBlueprints()->contains(fn ($b): bool => $b->handle() === $blueprint))) {
            throw new InvalidArgumentException("No collection has a blueprint called [{$blueprint}].");
        }

        $query = Entry::query();

        if ($collection !== null) {
            $query->where('collection', $collection);
        }
        if ($site !== null) {
            $query->where('site', $site);
        }

        $entries = $query->where('blueprint', $blueprint)->get()->values();

        $description = $collection === null
            ? __('every entry using the :blueprint blueprint', ['blueprint' => $blueprint])
            : __('every :blueprint entry in :collection', ['blueprint' => $blueprint, 'collection' => $collection]);

        return new Selection($site === null ? $description : $description.' '.__('in the :site site', ['site' => $site]), $entries);
    }
}
