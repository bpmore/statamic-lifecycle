<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Listeners;

use Bpmore\StatamicLifecycle\Auth\Permissions;
use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Fieldtypes\LifecycleStatus;
use Statamic\Contracts\Auth\User;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\User as Users;

/**
 * Puts the lifecycle fields on every entry blueprint, without anybody editing
 * a blueprint.
 *
 * Installing on a site with sixty blueprints and asking somebody to add a
 * fieldset to each of them is the kind of step that turns "installed" into
 * "uninstalled". So the fields arrive on their own, in the sidebar, the same
 * way Statamic itself adds slug, date and taxonomy fields.
 *
 * A site that wants them somewhere else declares a field with the same handle
 * wherever it likes: ensureField leaves an existing field where it is and
 * lets its config win, so ours only fills in what the site left unsaid.
 */
class AddLifecycleFieldsToBlueprint
{
    public function handle(EntryBlueprintFound $event): void
    {
        $definitions = LifecycleFields::definitions();

        // Who owns a page, and whether it is in the cycle, are decisions the
        // permissions (spec §8) hand to particular people. The bulk actions
        // ask; the sidebar is the other way to set the same two fields, so
        // it shows them read-only to anyone else, the way Statamic shows the
        // author field to someone who may not edit other authors' entries.
        // Read-only is what the editor sees, not what a request can carry:
        // RefuseUnpermittedLifecycleChanges holds the line on save. With no
        // one signed in (a command, a queue) there is no editor to hold back.
        foreach (self::readOnlyFor(Users::current()) as $handle) {
            if ($event->blueprint->hasField($handle)) {
                // The site declared it, wherever it likes, with its own
                // config. Visibility is the one thing ours overrides.
                $event->blueprint->ensureFieldHasConfig($handle, ['visibility' => 'read_only']);
            } else {
                $definitions[$handle]['visibility'] = 'read_only';
            }
        }

        $event->blueprint->ensureFieldsInTab($definitions, 'sidebar');

        // And, under the three inputs, the panel that says what they mean:
        // status, next review, last review, history, and the button. Computed
        // visibility keeps it out of every save.
        $event->blueprint->ensureFieldInTab(LifecycleStatus::HANDLE, [
            'type' => LifecycleStatus::HANDLE,
            'display' => __('Review'),
            'visibility' => 'computed',
            'listable' => false,
        ], 'sidebar');
    }

    /** @return list<string> the guarded field handles this person may look at but not set */
    private static function readOnlyFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $handles = [];

        foreach (Permissions::guardedFields() as $handle => $permission) {
            if (! $user->can($permission)) {
                $handles[] = $handle;
            }
        }

        return $handles;
    }
}
