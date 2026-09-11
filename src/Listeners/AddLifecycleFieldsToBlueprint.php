<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Listeners;

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Statamic\Events\EntryBlueprintFound;

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
        $event->blueprint->ensureFieldsInTab(LifecycleFields::definitions(), 'sidebar');
    }
}
