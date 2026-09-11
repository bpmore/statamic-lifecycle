<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment\Selectors;

use Bpmore\StatamicLifecycle\Assignment\Selection;
use InvalidArgumentException;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/** "Everything in news belongs to the communications office." */
final class InCollection
{
    public function select(string $handle, ?string $site = null): Selection
    {
        if (Collection::find($handle) === null) {
            throw new InvalidArgumentException("There is no collection called [{$handle}].");
        }

        $query = Entry::query()->where('collection', $handle);

        if ($site !== null) {
            $query->where('site', $site);
        }

        return new Selection(
            $site === null
                ? __('every entry in :collection', ['collection' => $handle])
                : __('every entry in :collection in the :site site', ['collection' => $handle, 'site' => $site]),
            $query->get()->values(),
        );
    }
}
