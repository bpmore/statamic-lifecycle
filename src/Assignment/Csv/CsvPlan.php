<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment\Csv;

use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Entry;

/**
 * What a CSV asks for: which entries go to which owner, and which rows
 * could not be understood.
 */
final readonly class CsvPlan
{
    /**
     * @param  array<string, Collection<int, Entry>>  $byOwner  owner ID => entries
     * @param  array<string, string>  $ownerNames  owner ID => display name
     * @param  list<CsvProblem>  $problems
     */
    public function __construct(
        public int $rows,
        public array $byOwner,
        public array $ownerNames,
        public array $problems,
    ) {}

    public function entries(): int
    {
        return array_sum(array_map(fn (Collection $entries): int => $entries->count(), $this->byOwner));
    }

    public function owners(): int
    {
        return count($this->byOwner);
    }
}
