<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Widgets;

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Snapshot;
use Bpmore\StatamicLifecycle\Reporting\Overview;
use Bpmore\StatamicLifecycle\Reporting\Snapshots;
use Bpmore\StatamicLifecycle\State\Status;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Statamic\Facades\User;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

/**
 * The dashboard widget (spec §8): my overdue count, the site's, and the
 * thirty-day trend. Each number is a way into the screen behind it.
 *
 * Enabled by the site in config/statamic/cp.php:
 *   ['type' => 'lifecycle', 'width' => 50]
 */
class Lifecycle extends Widget
{
    public function component(): VueComponent
    {
        $database = app(LifecycleDatabase::class);
        $installed = $database->isInstalled();
        $user = User::current();
        $now = Carbon::now()->toImmutable();

        if (! $installed) {
            return VueComponent::render('lifecycle-widget', [
                'title' => $this->config('title', __('Content review')),
                'installed' => false,
                'dashboardUrl' => cp_route('lifecycle.dashboard'),
            ]);
        }

        $overview = app(Overview::class);
        $statuses = $overview->statuses();
        $late = [Status::Overdue->value, Status::Expired->value];

        $mine = $user === null ? null : [
            'overdue' => EntryState::query()->where('owner_id', (string) $user->id())->whereIn('status', $late)->count(),
            'due_soon' => EntryState::query()->where('owner_id', (string) $user->id())->where('status', Status::DueSoon->value)->count(),
        ];

        $trend = array_map(fn (Snapshot $s): array => ['day' => $s->day->format('Y-m-d'), 'late' => $s->late()], app(Snapshots::class)->last(30, $now));
        $lateNow = $statuses['overdue'] + $statuses['expired'];

        // Against the oldest day in the window — an earlier day, not today's own snapshot.
        $oldest = $trend[0] ?? null;
        $since = $oldest === null || $oldest['day'] >= $now->format('Y-m-d')
            ? null
            : $now->setTime(0, 0)->diffInDays(new DateTimeImmutable($oldest['day']), true);

        return VueComponent::render('lifecycle-widget', [
            'title' => $this->config('title', __('Content review')),
            'installed' => true,
            'mine' => $mine,
            'site' => [
                'overdue' => $lateNow,
                'due_soon' => $statuses['due_soon'],
                'total' => $overview->total(),
            ],
            'trend' => $trend,
            'change' => $since === null ? null : $lateNow - $oldest['late'],
            'since' => $since === null ? null : (int) $since,
            'days' => 30,
            'dashboardUrl' => cp_route('lifecycle.dashboard'),
            'mineUrl' => cp_route('lifecycle.mine'),
            'overdueUrl' => cp_route('lifecycle.entries', ['status' => Status::Overdue->value]),
        ]);
    }
}
