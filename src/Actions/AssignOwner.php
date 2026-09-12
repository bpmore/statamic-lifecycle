<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Actions;

use Bpmore\StatamicLifecycle\Assignment\OwnerAssigner;
use Bpmore\StatamicLifecycle\Auth\Permissions;
use Exception;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

/**
 * "Assign owner" on entries in the listing, one or many — filter the list
 * to a department's pages, select them all, assign. The half of spec §2's
 * bulk assignment that happens in the control panel rather than on the
 * command line; the same OwnerAssigner does the writing.
 */
class AssignOwner extends Action
{
    public static function title()
    {
        return __('Assign owner');
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Entry;
    }

    public function visibleToBulk($items): bool
    {
        return $items->every(fn ($item): bool => $this->visibleTo($item));
    }

    /** Spec §8's `assign owners`, on a page the person may edit — it writes the entry. */
    public function authorize($user, $item): bool
    {
        return $item instanceof Entry && Permissions::mayAssign($user, $item);
    }

    public function buttonText()
    {
        return trans_choice('Assign owner|Assign owner to :count entries', 'count');
    }

    public function confirmationText()
    {
        return __('This writes the owner into each entry, like an edit — it shows in the entry\'s history and in git.');
    }

    protected function fieldItems()
    {
        return [
            'owner' => [
                'display' => __('Content owner'),
                'instructions' => __('The person who knows whether these pages are still accurate. They get the reminders.'),
                'type' => 'users',
                'max_items' => 1,
                'mode' => 'select',
                'validate' => 'required',
            ],
        ];
    }

    public function run($items, $values): string
    {
        $ownerId = (string) ($values['owner'] ?? '');
        $owner = $ownerId === '' ? null : User::find($ownerId);

        if ($owner === null) {
            // Shown to the person as the action's failure message.
            throw new Exception(__('There is no user with the ID [:id].', ['id' => $ownerId]));
        }

        $result = app(OwnerAssigner::class)->assign($items, $ownerId);
        $name = $owner->name() ?: (string) $owner->email();

        if ($result->assigned === []) {
            return trans_choice(
                '{1}That page already has :name as its owner.|[2,*]All :count pages already have :name as their owner.',
                count($result->unchanged),
                ['count' => count($result->unchanged), 'name' => $name],
            );
        }

        $message = trans_choice(
            '{1}Assigned :name to :count page.|[2,*]Assigned :name to :count pages.',
            count($result->assigned),
            ['count' => count($result->assigned), 'name' => $name],
        );

        if ($result->unchanged !== []) {
            $message .= ' '.trans_choice(
                '{1}:count already had them.|[2,*]:count already had them.',
                count($result->unchanged),
                ['count' => count($result->unchanged)],
            );
        }

        return $message;
    }
}
