<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Http\Controllers;

use Bpmore\StatamicLifecycle\Reporting\EntryListing;
use Bpmore\StatamicLifecycle\Reporting\Overview;
use Bpmore\StatamicLifecycle\State\Status;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Http\Request;
use Inertia\Response;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The lists: every page with its owner, date and state, filtered; and
 * "My content", the same list for the person looking at it — the screen
 * an owner actually lives in (spec §8).
 */
class EntriesController extends CpController
{
    public function index(Request $request, LifecycleDatabase $database, EntryListing $listing): Response
    {
        return $this->render($request, $database, $listing, EntryListing::filtersFrom($request->all()), __('Content review'), false);
    }

    public function mine(Request $request, LifecycleDatabase $database, EntryListing $listing): Response
    {
        $filters = EntryListing::filtersFrom($request->all());
        $filters['owner'] = (string) User::current()?->id();

        return $this->render($request, $database, $listing, $filters, __('My content'), true);
    }

    /** @param  array<string, string|null>  $filters */
    private function render(Request $request, LifecycleDatabase $database, EntryListing $listing, array $filters, string $title, bool $mine): Response
    {
        $installed = $database->isInstalled();

        return inertia('lifecycle/Entries', [
            'title' => $title,
            'mine' => $mine,
            'installed' => $installed,
            'entries' => $installed ? $listing->page($filters) : null,
            'filters' => $filters,
            'statusMeta' => Overview::statusMeta(),
            // Statamic's Select reads `label` and `value` off each option.
            'options' => [
                'statuses' => collect(Status::cases())->map(fn (Status $s): array => ['value' => $s->value, 'label' => Overview::statusMeta()[$s->value]['label']])->all(),
                'attention' => [
                    ['value' => 'never_reviewed', 'label' => __('Never reviewed')],
                    ['value' => 'no_owner', 'label' => __('No owner')],
                    ['value' => 'inferred_owner', 'label' => __('Owner is a guess')],
                ],
                'collections' => Collection::all()->sortBy->title()->map(fn ($c): array => ['value' => $c->handle(), 'label' => (string) $c->title()])->values()->all(),
                'sites' => Site::all()->map(fn ($s): array => ['value' => $s->handle(), 'label' => (string) $s->name()])->values()->all(),
            ],
            'dashboardUrl' => cp_route('lifecycle.dashboard'),
            'entriesUrl' => cp_route('lifecycle.entries'),
            'mineUrl' => cp_route('lifecycle.mine'),
        ]);
    }
}
