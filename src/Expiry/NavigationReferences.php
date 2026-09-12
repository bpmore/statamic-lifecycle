<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use Statamic\Facades\Nav;

/**
 * Which navigations link to which entries, read from every navigation's
 * tree in every site. One pass over the trees, however many entries are
 * asked about: a menu is small and a run is not.
 */
final class NavigationReferences
{
    /**
     * @param  iterable<string>  $entryIds
     * @return array<string, list<string>> entry ID => navigation names, for the entries that are linked
     */
    public function for(iterable $entryIds): array
    {
        $wanted = array_fill_keys(is_array($entryIds) ? $entryIds : iterator_to_array($entryIds, false), true);
        $references = [];

        if ($wanted === []) {
            return [];
        }

        foreach (Nav::all() as $nav) {
            foreach ($nav->trees() as $site => $tree) {
                foreach ($tree->flattenedPages() as $page) {
                    $id = $page->reference();

                    if ($id === null || ! isset($wanted[(string) $id])) {
                        continue;
                    }

                    $name = (string) $nav->title().($nav->sites()->count() > 1 ? " ({$site})" : '');
                    $references[(string) $id][$name] = true;
                }
            }
        }

        return array_map(fn (array $names): array => array_keys($names), $references);
    }
}
