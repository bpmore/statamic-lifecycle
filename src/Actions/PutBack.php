<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Actions;

use Bpmore\StatamicLifecycle\Auth\Permissions;
use Bpmore\StatamicLifecycle\Expiry\ActionLog;
use Exception;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

/**
 * "Put back" on an entry Lifecycle has changed: the one click that undoes
 * the last automated action, from the listing or the entry's own menu.
 *
 * Offered only where there is something to undo.
 */
class PutBack extends Action
{
    public static function title()
    {
        return __('Put back what Lifecycle changed');
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Entry && app(ActionLog::class)->outstanding((string) $item->id()) !== null;
    }

    public function visibleToBulk($items): bool
    {
        return $items->every(fn ($item): bool => $this->visibleTo($item));
    }

    /** Spec §8's `configure expiry actions`: undoing one is the control panel's part of it. */
    public function authorize($user, $item): bool
    {
        return $item instanceof Entry && Permissions::mayPutBack($user, $item);
    }

    public function buttonText()
    {
        return trans_choice('Put it back|Put :count pages back', 'count');
    }

    public function confirmationText()
    {
        return __('This undoes the last change Lifecycle made to the page and records that it was undone.');
    }

    public function run($items, $values): string
    {
        $user = User::current();
        $log = app(ActionLog::class);
        $undone = [];

        foreach ($items as $entry) {
            $action = $log->outstanding((string) $entry->id());

            if ($action === null) {
                continue;
            }

            $log->revert($action, $user === null ? null : (string) $user->id());
            $undone[] = $action->action->label();
        }

        if ($undone === []) {
            throw new Exception(__('There is nothing of Lifecycle\'s to put back on that page.'));
        }

        return trans_choice(
            '{1}Put it back: Lifecycle had chosen to :what.|[2,*]Put :count pages back.',
            count($undone),
            ['count' => count($undone), 'what' => $undone[0]],
        );
    }
}
