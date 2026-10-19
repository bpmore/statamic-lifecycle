<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment;

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Statamic\Contracts\Entries\Entry;

/**
 * Writes content_owner on entries — and on purpose: an owner is an
 * editorial decision, it belongs in content, and it should show in git
 * (spec §3). EntryWriter says how; this says what.
 */
final class OwnerAssigner
{
    public function __construct(private readonly EntryWriter $writer) {}

    /** @param  iterable<Entry>  $entries */
    public function assign(iterable $entries, string $ownerId, bool $dryRun = false): Assignment
    {
        [$assigned, $unchanged] = $this->writer->write(
            $entries,
            // value(), not get(): a localisation synced to an origin that
            // already has this owner has this owner, and writing it would
            // only break the sync.
            fn (Entry $entry): bool => (string) $entry->value(LifecycleFields::CONTENT_OWNER) === $ownerId,
            fn (Entry $entry) => $entry->set(LifecycleFields::CONTENT_OWNER, $ownerId),
            $dryRun,
        );

        return new Assignment($ownerId, $assigned, $unchanged, $dryRun);
    }
}
