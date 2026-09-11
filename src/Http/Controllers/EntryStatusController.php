<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Http\Controllers;

use Bpmore\StatamicLifecycle\Panel\SidebarPanel;
use Illuminate\Http\JsonResponse;
use Statamic\Contracts\Entries\Entry;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The sidebar panel's payload, fresh, after it has recorded a review — so
 * the panel shows what the tables say rather than what it assumes happened.
 */
class EntryStatusController extends CpController
{
    /** Statamic binds {entry} for us, and answers 404 when there is none. */
    public function show(Entry $entry, SidebarPanel $panel): JsonResponse
    {
        $this->authorize('view', $entry);

        return response()->json($panel->for($entry));
    }
}
