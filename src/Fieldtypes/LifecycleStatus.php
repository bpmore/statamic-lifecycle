<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Fieldtypes;

use Bpmore\StatamicLifecycle\Panel\SidebarPanel;
use Statamic\Contracts\Entries\Entry;
use Statamic\Fields\Fieldtype;

/**
 * The review panel on an entry's own screen.
 *
 * Stores nothing. Ensured onto every entry blueprint's sidebar with
 * `visibility: computed`, which keeps it out of what a save writes; and
 * process() returns null besides, so nothing could reach the entry even if
 * a site declared it without that visibility. Everything it shows is looked
 * up from the tables for the entry the field is attached to.
 */
class LifecycleStatus extends Fieldtype
{
    public const HANDLE = 'lifecycle_status';

    protected static $handle = self::HANDLE;

    protected static $title = 'Lifecycle review';

    protected $categories = ['special'];

    protected $icon = 'history';

    // A site may place the panel itself, so it has to be in the picker.
    protected $selectable = true;

    // Not for forms: it describes an entry's review state, and a submission
    // has none.
    protected $selectableInForms = false;

    /** The panel's whole payload is the field's value on the publish form. */
    public function preProcess($value): ?array
    {
        $entry = $this->field()?->parent();

        if (! $entry instanceof Entry) {
            return null;
        }

        return app(SidebarPanel::class)->for($entry);
    }

    /** Nothing is ever written: the tables are the source of truth. */
    public function process($value)
    {
        return null;
    }

    public function preProcessIndex($value)
    {
        return null;
    }

    public function augment($value)
    {
        return null;
    }
}
