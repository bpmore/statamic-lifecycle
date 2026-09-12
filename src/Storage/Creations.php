<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Storage;

use Bpmore\StatamicLifecycle\Models\Creation;
use DateTimeImmutable;

/** Reads and writes lifecycle_creations: when the addon saw each entry created. */
final class Creations
{
    /** Write the moment down; a second sighting of the same ID keeps the first. */
    public function record(string $entryId, string $site, string $collection, DateTimeImmutable $at): void
    {
        Creation::query()->insertOrIgnore([
            'entry_id' => $entryId,
            'site' => $site,
            'collection' => $collection,
            'created_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }

    /** When the entry was created, if the addon saw it happen. */
    public function createdAt(string $entryId): ?DateTimeImmutable
    {
        return Creation::query()->find($entryId)?->created_at;
    }

    /** @param  list<string>  $entryIds */
    public function remove(array $entryIds): void
    {
        if ($entryIds !== []) {
            Creation::query()->whereIn('entry_id', $entryIds)->delete();
        }
    }
}
