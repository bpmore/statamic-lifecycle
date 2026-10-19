<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Exemptions;

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Gate\PublishGate;
use Bpmore\StatamicLifecycle\StateRecomputer;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry;

/**
 * Writes review_exempt on entries — the second thing in this addon that
 * writes to an entry, for the same reason as the owner: an exemption is an
 * editorial decision, it belongs in content, and it should show in git.
 *
 * So it behaves the way the owner assigner does. Only the entries whose
 * exemption actually changes are saved; whoever ran it is not stamped as
 * the last author; the rows it touched are recomputed at once, so the page
 * reads "exempt" — or has its date back — before the person looks.
 */
final class ExemptionWriter
{
    public function __construct(private readonly StateRecomputer $recomputer) {}

    /** @param  iterable<Entry>  $entries */
    public function set(iterable $entries, bool $exempt, bool $dryRun = false): ExemptionChange
    {
        $changed = [];
        $unchanged = [];

        foreach ($entries as $entry) {
            if (self::isExempt($entry) === $exempt) {
                $unchanged[] = (string) $entry->id();

                continue;
            }

            if (! $dryRun) {
                PublishGate::suspended(function () use ($entry, $exempt): void {
                    if ($exempt) {
                        $entry->set(LifecycleFields::REVIEW_EXEMPT, true);
                    } elseif ($entry->hasOrigin()) {
                        // A translation inherits its origin's exemption;
                        // clearing it there means saying "not this one".
                        $entry->set(LifecycleFields::REVIEW_EXEMPT, false);
                    } else {
                        // Not exempt is the absence of the line, as it was
                        // before anybody exempted it.
                        $entry->remove(LifecycleFields::REVIEW_EXEMPT);
                    }

                    $entry->save();
                });
            }

            $changed[] = (string) $entry->id();
        }

        if (! $dryRun && $changed !== []) {
            $this->recomputer->recompute($changed, Carbon::now()->toImmutable());
        }

        return new ExemptionChange($exempt, $changed, $unchanged, $dryRun);
    }

    /** As the entry stands: its own value, or its origin's. */
    public static function isExempt(Entry $entry): bool
    {
        return LifecycleFields::isExemptValue($entry->value(LifecycleFields::REVIEW_EXEMPT));
    }
}
