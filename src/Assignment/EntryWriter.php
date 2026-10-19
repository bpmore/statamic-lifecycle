<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment;

use Bpmore\StatamicLifecycle\Gate\PublishGate;
use Bpmore\StatamicLifecycle\StateRecomputer;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry;

/**
 * The one way this addon writes an authored field into entries — owner,
 * exemption, interval — so the three bulk writers cannot drift apart.
 *
 * It behaves the way an editor would. Only the entries whose value actually
 * changes are saved; the gate is suspended, because none of these writes
 * makes a page less owned or less dated; whoever ran it is not stamped as
 * the last author, because they did not edit the page and must not become
 * its inferred owner everywhere else; and the rows touched are recomputed
 * at once, so the dashboard reflects the decision before the person looks.
 */
final class EntryWriter
{
    public function __construct(private readonly StateRecomputer $recomputer) {}

    /**
     * @param  iterable<Entry>  $entries
     * @param  callable(Entry): bool  $alreadySo  whether the entry is already as asked
     * @param  callable(Entry): void  $change  set (or remove) the field; the save is done here
     * @return array{list<string>, list<string>} entry IDs changed, entry IDs left alone
     */
    public function write(iterable $entries, callable $alreadySo, callable $change, bool $dryRun = false): array
    {
        $changed = [];
        $unchanged = [];

        foreach ($entries as $entry) {
            if ($alreadySo($entry)) {
                $unchanged[] = (string) $entry->id();

                continue;
            }

            if (! $dryRun) {
                PublishGate::suspended(function () use ($entry, $change): void {
                    $change($entry);
                    $entry->save();
                });
            }

            $changed[] = (string) $entry->id();
        }

        if (! $dryRun && $changed !== []) {
            $this->recomputer->recompute($changed, Carbon::now()->toImmutable());
        }

        return [$changed, $unchanged];
    }
}
