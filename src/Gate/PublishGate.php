<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Gate;

use Bpmore\StatamicLifecycle\Entries\EntryFactsReader;
use Bpmore\StatamicLifecycle\Storage\Creations;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\User;

/**
 * The gate (spec §7): a site can require a confirmed owner and a review
 * date before an entry publishes. Off on install, enabled per collection.
 *
 * Confirmed means named on the entry — content_owner set, and set to a
 * person who still exists. An owner the addon inferred from the last
 * author, or the owner of last resort, is a guess or a safety net, not a
 * person who has said "this page is mine"; an owner who has left is a name,
 * not an owner. A review date is either a review-by deadline or a review
 * interval: both are the author saying when this page next needs looking at.
 *
 * Drafts are never gated. Somebody's work in progress is not what this
 * protects, and the gate is about publishing.
 *
 * Entries created before the gate was enabled are never gated unless the
 * site asks with 'all' — see GateSettings. The addon knows when an entry
 * was created only because it wrote the moment down (Storage\Creations);
 * an entry it did not see created is treated as older than the gate.
 */
final class PublishGate
{
    /**
     * The addon's own writes — assigning an owner, setting a banner flag,
     * reverting an action — go through unchecked. None of them makes a page
     * less owned or less dated, and a gate that refused its own product's
     * help would be arguing with itself.
     */
    private static int $suspended = 0;

    public function __construct(
        private readonly GateSettings $settings,
        private readonly Creations $creations,
        private readonly LifecycleDatabase $database,
        private readonly EntryFactsReader $reader,
    ) {}

    /** Run the callback with the gate suspended, whatever it saves. */
    public static function suspended(callable $callback): mixed
    {
        self::$suspended++;

        try {
            return $callback();
        } finally {
            self::$suspended--;
        }
    }

    public static function isSuspended(): bool
    {
        return self::$suspended > 0;
    }

    public function inspect(Entry $entry): GateResult
    {
        if (! $entry->published()) {
            return GateResult::passed('a draft');
        }

        $rule = $this->settings->for((string) $entry->collectionHandle());

        if ($rule === null) {
            return GateResult::passed('the collection is not gated');
        }

        if (! $rule->coversEverything() && ! $this->isNew($entry) && ! $rule->covers($this->createdAt($entry))) {
            return GateResult::passed('created before the gate');
        }

        $facts = $this->reader->read($entry);
        $missing = [];

        if ($facts->ownerId === null) {
            $missing[] = GateResult::OWNER;
        } elseif (User::find($facts->ownerId) === null) {
            $missing[] = GateResult::OWNER_GONE;
        }

        if ($facts->reviewBy === null && ($facts->reviewInterval === null || $facts->reviewInterval === '')) {
            $missing[] = GateResult::REVIEW_DATE;
        }

        if ($missing === []) {
            return GateResult::passed('owned and dated');
        }

        return GateResult::refused($missing, (string) ($entry->collection()?->title() ?? $entry->collectionHandle()));
    }

    /** Being created by this very save: there is no earlier moment to compare with. */
    private function isNew(Entry $entry): bool
    {
        return $entry->id() === null || Entries::find($entry->id()) === null;
    }

    private function createdAt(Entry $entry): ?\DateTimeImmutable
    {
        if (! $this->database->isInstalled()) {
            return null;
        }

        return $this->creations->createdAt((string) $entry->id());
    }
}
