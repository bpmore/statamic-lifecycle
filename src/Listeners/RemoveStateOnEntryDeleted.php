<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Listeners;

use Bpmore\StatamicLifecycle\Storage\Creations;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Bpmore\StatamicLifecycle\Storage\StateStore;
use Statamic\Events\EntryDeleted;
use Throwable;

/**
 * Takes the row away the moment the entry goes, rather than leaving a
 * dashboard counting a page that is not there until the nightly sweep.
 *
 * The sweep still exists: this only hears deletions that fire the event,
 * and files removed from git or deleted quietly do not.
 */
class RemoveStateOnEntryDeleted
{
    public function __construct(
        private readonly LifecycleDatabase $database,
        private readonly StateStore $store,
        private readonly Creations $creations,
    ) {}

    public function handle(EntryDeleted $event): void
    {
        // Deleting an entry must never fail because this addon's tables are
        // not there yet, or its database is unreachable.
        try {
            if (! $this->database->isInstalled()) {
                return;
            }

            $this->store->remove([(string) $event->entry->id()]);
            $this->creations->remove([(string) $event->entry->id()]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
