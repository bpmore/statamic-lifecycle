<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment;

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Statamic\Contracts\Entries\Entry;

/**
 * Writes review_interval on entries: "these forty policies are quarterly".
 * An interval an author chose is content (spec §3); EntryWriter says how.
 *
 * Null means the default for the collection — the field removed from an
 * original, so the file reads as it did before anyone set it; written as
 * null on a translation, which is how a translation says "not the
 * original's cadence, the default".
 */
final class IntervalSetter
{
    public function __construct(private readonly EntryWriter $writer) {}

    /**
     * @param  iterable<Entry>  $entries
     * @param  ?string  $interval  an interval name, or null for the default
     */
    public function set(iterable $entries, ?string $interval, bool $dryRun = false): IntervalChange
    {
        [$changed, $unchanged] = $this->writer->write(
            $entries,
            fn (Entry $entry): bool => self::current($entry) === $interval,
            function (Entry $entry) use ($interval): void {
                if ($interval !== null) {
                    $entry->set(LifecycleFields::REVIEW_INTERVAL, $interval);
                } elseif ($entry->hasOrigin()) {
                    $entry->set(LifecycleFields::REVIEW_INTERVAL, null);
                } else {
                    $entry->remove(LifecycleFields::REVIEW_INTERVAL);
                }
            },
            $dryRun,
        );

        return new IntervalChange($interval, $changed, $unchanged, $dryRun);
    }

    /** The interval as the entry stands — its own, or its origin's — or null for the default. */
    public static function current(Entry $entry): ?string
    {
        $value = $entry->value(LifecycleFields::REVIEW_INTERVAL);

        return $value === null || $value === '' ? null : (string) $value;
    }
}
