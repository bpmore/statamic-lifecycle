<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Entries;

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\State\EntryFacts;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry;
use Throwable;

/**
 * The one place a Statamic entry is turned into facts for the computation.
 *
 * Everything Statamic-specific is settled here so State\StateComputer never
 * has to know: localisations inherit a field from their origin unless they
 * set their own, the last author only counts if that person still exists,
 * and a date is stored as a string.
 */
final class EntryFactsReader
{
    public function read(Entry $entry): EntryFacts
    {
        return new EntryFacts(
            entryId: (string) $entry->id(),
            site: (string) $entry->locale(),
            collection: (string) $entry->collectionHandle(),
            blueprint: (string) $entry->blueprint()->handle(),
            lastModifiedAt: $entry->lastModified()->toImmutable(),
            // value(), not get(): a localisation that has not localised the
            // field is synced to its origin, and the owner of the original
            // owns the translation until somebody says otherwise.
            ownerId: $this->firstId($entry->value(LifecycleFields::CONTENT_OWNER)),
            reviewInterval: $entry->value(LifecycleFields::REVIEW_INTERVAL),
            reviewBy: $this->date($entry->value(LifecycleFields::REVIEW_BY)),
            // lastModifiedBy() looks the user up, and is null when they have
            // gone. An owner who left is not an owner.
            lastAuthorId: $entry->lastModifiedBy()?->id(),
            // Inherited the same way: a translation of an exempt page is
            // exempt until somebody says otherwise on the translation.
            exempt: LifecycleFields::isExemptValue($entry->value(LifecycleFields::REVIEW_EXEMPT)),
        );
    }

    /**
     * The users fieldtype stores a single ID at max_items 1, but a site that
     * declared the field itself may have left that off and stored a list.
     * Either way, one owner.
     */
    private function firstId(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        try {
            return Carbon::parse((string) $value)->toImmutable();
        } catch (Throwable) {
            // A date field holding something that is not a date is a
            // blueprint problem, not a reason for the whole computation to
            // stop. No deadline is the honest reading.
            return null;
        }
    }
}
