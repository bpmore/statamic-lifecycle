<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Listeners;

use Bpmore\StatamicLifecycle\Storage\Creations;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Support\Carbon;
use Statamic\Events\EntryCreated;
use Throwable;

/**
 * Writes down the moment an entry was created, because Statamic does not,
 * and the gate's promise never to touch content older than itself needs it.
 */
class RecordEntryCreated
{
    public function __construct(
        private readonly LifecycleDatabase $database,
        private readonly Creations $creations,
    ) {}

    public function handle(EntryCreated $event): void
    {
        // Creating an entry must never fail because this addon's tables are
        // not there yet, or its database is unreachable.
        try {
            if (! $this->database->isInstalled()) {
                return;
            }

            $entry = $event->entry;

            $this->creations->record(
                (string) $entry->id(),
                (string) $entry->locale(),
                (string) $entry->collectionHandle(),
                Carbon::now()->toImmutable(),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
