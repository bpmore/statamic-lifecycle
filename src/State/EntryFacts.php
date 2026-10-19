<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

use DateTimeImmutable;

/**
 * What the computation needs to know about an entry, and nothing else.
 *
 * Built from a Statamic entry by Entries\EntryFactsReader, or by hand in a
 * test. The computation never sees the entry itself, which is what keeps it
 * pure: everything Statamic-specific — localisation fallback, whether the
 * last author still exists, how a date is stored — is settled before this
 * object exists.
 */
final readonly class EntryFacts
{
    /**
     * @param  mixed  $reviewInterval  the review_interval field as stored: an
     *                                 interval name, a number of days, or null
     * @param  ?string  $lastAuthorId  who last modified the entry, only if that
     *                                 person still exists
     * @param  bool  $exempt  outside the cycle altogether: the entry's
     *                        review_exempt field, read like the others
     */
    public function __construct(
        public string $entryId,
        public string $site,
        public string $collection,
        public string $blueprint,
        public DateTimeImmutable $lastModifiedAt,
        public ?string $ownerId = null,
        public mixed $reviewInterval = null,
        public ?DateTimeImmutable $reviewBy = null,
        public ?string $lastAuthorId = null,
        public bool $exempt = false,
    ) {}
}
