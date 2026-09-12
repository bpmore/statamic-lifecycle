<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment;

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Gate\PublishGate;
use Bpmore\StatamicLifecycle\StateRecomputer;
use DateTimeImmutable;
use Statamic\Contracts\Entries\Entry;

/**
 * Writes content_owner on entries — the one thing in this addon that does
 * write to an entry, and on purpose: an owner is an editorial decision, it
 * belongs in content, and it should show in git (spec §3).
 *
 * So it behaves like an editor would. It saves the entry, and only the
 * entries that actually change; it does not stamp itself as the last
 * author, because whoever ran the assignment did not edit the page and
 * must not become its inferred owner everywhere else. Then it recomputes
 * the rows it touched, so the dashboard says "chosen" at once.
 */
final class OwnerAssigner
{
    public function __construct(private readonly StateRecomputer $recomputer) {}

    /** @param  iterable<Entry>  $entries */
    public function assign(iterable $entries, string $ownerId, bool $dryRun = false): Assignment
    {
        $assigned = [];
        $unchanged = [];

        foreach ($entries as $entry) {
            // value(), not get(): a localisation synced to an origin that
            // already has this owner has this owner, and writing it would
            // only break the sync.
            if ((string) $entry->value(LifecycleFields::CONTENT_OWNER) === $ownerId) {
                $unchanged[] = (string) $entry->id();

                continue;
            }

            if (! $dryRun) {
                PublishGate::suspended(fn () => $entry->set(LifecycleFields::CONTENT_OWNER, $ownerId)->save());
            }

            $assigned[] = (string) $entry->id();
        }

        if (! $dryRun && $assigned !== []) {
            $this->recomputer->recompute($assigned, new DateTimeImmutable);
        }

        return new Assignment($ownerId, $assigned, $unchanged, $dryRun);
    }
}
