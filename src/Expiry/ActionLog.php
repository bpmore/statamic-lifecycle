<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use Bpmore\StatamicLifecycle\Models\AutomatedAction;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\User;

/**
 * Rail 6 (spec §6): every automated change to the live site is written
 * down with what the page looked like before, and can be put back in one
 * step.
 *
 * `previous_state` is a map of what the action changed: `published` for an
 * unpublish, otherwise the entry's own data keys with their old values
 * (null for a key that was not there). A revert applies that map, marks
 * the row reverted by whom and when, and writes a `restore` row of its own
 * — so the log says both what happened and that it was undone. An action
 * that is not in here did not happen through this addon.
 */
final class ActionLog
{
    /** @param  array<string, mixed>  $previousState */
    public function record(Entry $entry, ActionType $action, array $previousState, string $triggeredBy, ?DateTimeImmutable $at = null): AutomatedAction
    {
        return AutomatedAction::query()->create([
            'entry_id' => (string) $entry->id(),
            'action' => $action,
            'applied_at' => $at ?? Carbon::now()->toImmutable(),
            'triggered_by' => $triggeredBy,
            'previous_state' => $previousState,
        ]);
    }

    /**
     * The latest change to this page that has not been put back, if any —
     * what "put it back" would undo.
     */
    public function outstanding(string $entryId): ?AutomatedAction
    {
        return AutomatedAction::query()
            ->where('entry_id', $entryId)
            ->where('action', '!=', ActionType::Restore->value)
            ->whereNull('reverted_at')
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return list<AutomatedAction> newest first */
    public function history(string $entryId): array
    {
        return AutomatedAction::query()
            ->where('entry_id', $entryId)
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /**
     * Put the page back the way it was before this action, and say so.
     *
     * @return AutomatedAction the restore row
     */
    public function revert(AutomatedAction $action, ?string $userId = null, ?DateTimeImmutable $at = null): AutomatedAction
    {
        if ($action->action === ActionType::Restore) {
            throw new InvalidArgumentException('A restore is not something to put back.');
        }

        if ($action->reverted_at !== null) {
            throw new InvalidArgumentException('That change was already put back on '.$action->reverted_at->format('j M Y').'.');
        }

        $entry = Entries::find($action->entry_id);

        if ($entry === null) {
            throw new InvalidArgumentException("The page [{$action->entry_id}] no longer exists.");
        }

        $at ??= Carbon::now()->toImmutable();
        $undone = $this->apply($entry, (array) $action->previous_state, $userId);

        $action->reverted_at = $at;
        $action->reverted_by = $userId;
        $action->save();

        return $this->record($entry, ActionType::Restore, $undone, $userId ?? 'lifecycle', $at);
    }

    /**
     * Apply a previous state to the entry, and return the state it replaced
     * — so the restore's own row can say what it undid.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function apply(Entry $entry, array $state, ?string $userId): array
    {
        $replaced = [];
        $user = $userId === null ? null : User::find($userId);
        $touchedData = false;

        foreach ($state as $key => $value) {
            if ($key === 'published') {
                $replaced['published'] = (bool) $entry->published();

                continue;
            }

            $replaced[$key] = $entry->get($key);

            if ($value === null) {
                $entry->remove($key);
            } else {
                $entry->set($key, $value);
            }

            $touchedData = true;
        }

        // Publishing and unpublishing go through Statamic's own path, which
        // knows about revisions; a data change is saved as the addon's own.
        if (array_key_exists('published', $state)) {
            $options = ['user' => $user, 'message' => __('Put back by Lifecycle')];

            $state['published'] ? $entry->publish($options) : $entry->unpublish($options);
        } elseif ($touchedData) {
            $entry->save();
        }

        return $replaced;
    }
}
