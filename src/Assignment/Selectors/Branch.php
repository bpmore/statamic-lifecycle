<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment\Selectors;

use Bpmore\StatamicLifecycle\Assignment\Selection;
use InvalidArgumentException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Site;

/**
 * "Everything under /cardiology belongs to the Cardiology team."
 *
 * A branch is a page in a structured collection's tree and every page
 * beneath it, in one site's tree. Pages in the tree that are not entries
 * (links, text) have nothing to own and are left out.
 */
final class Branch
{
    public function select(string $uri, ?string $site = null): Selection
    {
        $site ??= Site::default()->handle();
        $uri = '/'.trim($uri, '/');

        $root = Entries::findByUri($uri, $site);

        if ($root === null) {
            throw new InvalidArgumentException("There is no page at [{$uri}] in the [{$site}] site.");
        }

        $page = $root->page();

        if ($page === null) {
            throw new InvalidArgumentException(
                "[{$uri}] is in the [{$root->collectionHandle()}] collection, which has no structure, so it has no branch. Assign by collection instead."
            );
        }

        $entries = $page->flattenedPages()
            ->map(fn ($descendant) => $descendant->entry())
            ->filter(fn ($entry): bool => $entry instanceof Entry)
            ->prepend($root)
            ->values();

        return new Selection(__('everything under :uri in the :site site', ['uri' => $uri, 'site' => $site]), $entries);
    }
}
