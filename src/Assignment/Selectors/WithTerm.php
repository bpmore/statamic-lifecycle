<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment\Selectors;

use Bpmore\StatamicLifecycle\Assignment\Selection;
use InvalidArgumentException;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/** "Everything tagged cardiology belongs to the Cardiology team." */
final class WithTerm
{
    public function select(string $term, ?string $site = null): Selection
    {
        if (! str_contains($term, '::')) {
            throw new InvalidArgumentException("Name the term as taxonomy::slug, for example topics::cardiology; got [{$term}].");
        }

        [$taxonomy, $slug] = explode('::', $term, 2);

        if (Taxonomy::find($taxonomy) === null) {
            throw new InvalidArgumentException("There is no taxonomy called [{$taxonomy}].");
        }

        if (Term::find($term) === null) {
            throw new InvalidArgumentException("There is no term [{$slug}] in the [{$taxonomy}] taxonomy.");
        }

        $query = Entry::query()->whereTaxonomy($term);

        if ($site !== null) {
            $query->where('site', $site);
        }

        return new Selection(
            $site === null
                ? __('every entry tagged :slug in :taxonomy', ['slug' => $slug, 'taxonomy' => $taxonomy])
                : __('every entry tagged :slug in :taxonomy in the :site site', ['slug' => $slug, 'taxonomy' => $taxonomy, 'site' => $site]),
            $query->get()->values(),
        );
    }
}
