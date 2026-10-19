<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Http\Controllers;

use Bpmore\StatamicLifecycle\Auth\Permissions;
use Bpmore\StatamicLifecycle\Reporting\Overview;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Inertia\Response;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The utility screen (spec §8): how much content is in what state, by
 * collection, by site and by owner, with the way into each list.
 */
class DashboardController extends CpController
{
    public function index(LifecycleDatabase $database, Overview $overview): Response
    {
        abort_unless(Permissions::mayView(User::current()), 403);

        $installed = $database->isInstalled();

        return inertia('lifecycle/Dashboard', [
            'installed' => $installed,
            'total' => $installed ? $overview->total() : 0,
            'statuses' => $installed ? $overview->statuses() : [],
            'attention' => $installed ? $overview->attention() : [],
            'baseline' => $installed ? $overview->baseline() : null,
            'collections' => $installed ? $overview->byCollection() : [],
            'sites' => $installed ? $overview->bySite() : [],
            'owners' => $installed ? $overview->byOwner() : [],
            'statusMeta' => Overview::statusMeta(),
            'entriesUrl' => cp_route('lifecycle.entries'),
            'mineUrl' => cp_route('lifecycle.mine'),
            'me' => (string) (User::current()?->id() ?? ''),
        ]);
    }
}
