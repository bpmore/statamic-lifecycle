<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Listeners;

use Bpmore\StatamicLifecycle\Gate\PublishGate;
use Bpmore\StatamicLifecycle\Models\Review;
use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Bpmore\StatamicLifecycle\Reviews\RecordReview;
use Bpmore\StatamicLifecycle\Reviews\ReviewSource;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Facades\User;
use Throwable;
use WeakMap;

/**
 * Spec §11's option, default off: a site may decide that editing a page
 * counts as reviewing it. Where it has, a person saving a published page
 * is recorded as having reviewed it — outcome "I updated it", source
 * "edit", so the record never confuses a save with a signature.
 *
 * What does not count: a draft (not in the cycle), a save with nothing
 * changed, a save with no person behind it (an import, a command), the
 * addon's own writes (an owner assigned in bulk is not a review by the
 * person who ran the command), and a second save by the same person on
 * the same day (one review a day is the record; ten saves are one edit).
 *
 * "Nothing changed" is known only before the write, and not from the
 * entry's own dirty state: Statamic's save() looks the entry up in the
 * Stache first, and that lookup marks the very object clean before
 * EntrySaving fires. So at EntrySaving the entry as it would be written
 * is compared with the file as it is, the answer kept per entry object,
 * and read back at EntrySaved.
 */
class RecordEditAsReview
{
    /** @var WeakMap<object, bool> whether each entry being saved had changes */
    private static ?WeakMap $dirty = null;

    public function __construct(
        private readonly Repository $config,
        private readonly LifecycleDatabase $database,
        private readonly RecordReview $recorder,
    ) {}

    public function noteDirty(EntrySaving $event): void
    {
        self::$dirty ??= new WeakMap;
        self::$dirty[$event->entry] = self::wouldChangeTheFile($event->entry);
    }

    /**
     * Whether writing the entry now would change what is on disk. A new
     * entry, or one not kept in a file at all, counts as a change — better
     * to over-count a save than to miss an edit.
     */
    private static function wouldChangeTheFile(object $entry): bool
    {
        try {
            $path = $entry->path();

            return ! is_string($path) || ! is_file($path) || file_get_contents($path) !== $entry->fileContents();
        } catch (Throwable) {
            return true;
        }
    }

    public function handle(EntrySaved $event): void
    {
        $entry = $event->entry;
        $wasDirty = self::$dirty[$entry] ?? true;
        unset(self::$dirty[$entry]);

        // Saving an entry must never fail because of this addon.
        try {
            if (! $this->config->get('lifecycle.reviews.editing_counts', false)) {
                return;
            }

            if (PublishGate::isSuspended() || ! $this->database->isInstalled()) {
                return;
            }

            $user = User::current();

            if ($user === null || ! $wasDirty || $entry->status() !== 'published') {
                return;
            }

            $userId = (string) $user->id();
            $now = Carbon::now()->toImmutable();

            $latest = Review::query()->forEntry((string) $entry->id())->newestFirst()->first();

            if ($latest !== null
                && $latest->source === ReviewSource::Edit
                && $latest->reviewed_by === $userId
                && $latest->reviewed_at->format('Y-m-d') === $now->format('Y-m-d')) {
                return;
            }

            $this->recorder->record($entry, $userId, Outcome::Updated, null, $now, ReviewSource::Edit);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
