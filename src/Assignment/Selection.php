<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment;

use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Entry;

/**
 * A set of entries somebody wants to assign in one go, and the words that
 * say which: "everything under /cardiology", "every entry in news".
 *
 * Every selector — branch, collection, blueprint, author, taxonomy term,
 * a CSV — produces one of these, so the command and the assigner never
 * care which way the entries were chosen.
 */
final readonly class Selection
{
    /** @param  Collection<int, Entry>  $entries */
    public function __construct(
        public string $description,
        public Collection $entries,
    ) {}

    public function count(): int
    {
        return $this->entries->count();
    }
}
