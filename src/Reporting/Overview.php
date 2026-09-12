<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reporting;

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Panel\SidebarPanel;
use Bpmore\StatamicLifecycle\State\OwnerSource;
use Bpmore\StatamicLifecycle\State\Status;
use Illuminate\Database\Eloquent\Builder;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * The numbers the utility screen leads with: how much content is in what
 * state, and the same broken down by collection, by site and by owner —
 * spec §8's dashboards. Read from lifecycle_states in a handful of grouped
 * queries, never by walking entries.
 */
final class Overview
{
    /** @return array<string, int> status => count, every status present */
    public function statuses(): array
    {
        $counts = array_fill_keys(array_map(fn (Status $s): string => $s->value, Status::cases()), 0);

        foreach (EntryState::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status') as $status => $n) {
            $counts[$status] = (int) $n;
        }

        return $counts;
    }

    public function total(): int
    {
        return EntryState::query()->count();
    }

    /** The headline filters: what needs attention beyond being due. @return array<string, int> */
    public function attention(): array
    {
        return [
            'never_reviewed' => EntryState::query()->whereNull('last_reviewed_at')->where('status', '!=', Status::Exempt->value)->count(),
            'no_owner' => EntryState::query()->whereNull('owner_id')->count(),
            'inferred_owner' => EntryState::query()->where('owner_source', OwnerSource::Inferred->value)->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function byCollection(): array
    {
        $titles = Collection::all()->mapWithKeys(fn ($c) => [$c->handle() => (string) $c->title()])->all();

        return $this->breakdown('collection', fn (string $handle): string => $titles[$handle] ?? $handle);
    }

    /** @return list<array<string, mixed>> */
    public function bySite(): array
    {
        $names = Site::all()->mapWithKeys(fn ($s) => [$s->handle() => (string) $s->name()])->all();

        return $this->breakdown('site', fn (string $handle): string => $names[$handle] ?? $handle);
    }

    /** @return list<array<string, mixed>> owners with the most overdue first; the ownerless last */
    public function byOwner(): array
    {
        $rows = $this->breakdown('owner_id', function (?string $id): string {
            if ($id === null || $id === '') {
                return __('Nobody');
            }
            $user = User::find($id);

            return $user === null ? $id : ($user->name() ?: (string) $user->email());
        });

        usort($rows, function (array $a, array $b): int {
            if (($a['key'] === null) !== ($b['key'] === null)) {
                return $a['key'] === null ? 1 : -1;
            }

            return [$b['overdue'] + $b['expired'], $b['total']] <=> [$a['overdue'] + $a['expired'], $a['total']];
        });

        return $rows;
    }

    /**
     * Counts per status for each distinct value of a column, plus never
     * reviewed, as rows the tables render directly.
     *
     * @param  callable(?string): string  $label
     * @return list<array<string, mixed>>
     */
    private function breakdown(string $column, callable $label): array
    {
        $rows = [];

        $counts = EntryState::query()
            ->selectRaw("{$column} as k, status, count(*) as n")
            ->groupBy($column, 'status')
            ->get();

        foreach ($counts as $count) {
            $key = $count->k === null ? null : (string) $count->k;
            $rows[$key ?? ''] ??= ['key' => $key, 'label' => $label($key), 'total' => 0]
                + array_fill_keys(array_map(fn (Status $s): string => $s->value, Status::cases()), 0)
                + ['never_reviewed' => 0];
            $rows[$key ?? ''][$count->status] = (int) $count->n;
            $rows[$key ?? '']['total'] += (int) $count->n;
        }

        $never = EntryState::query()
            ->selectRaw("{$column} as k, count(*) as n")
            ->whereNull('last_reviewed_at')
            ->where('status', '!=', Status::Exempt->value)
            ->groupBy($column)
            ->pluck('n', 'k');

        foreach ($never as $key => $n) {
            $key = $key === null ? '' : (string) $key;
            if (isset($rows[$key])) {
                $rows[$key]['never_reviewed'] = (int) $n;
            }
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $rows;
    }

    /** @return array<string, array{label: string, colour: string}> */
    public static function statusMeta(): array
    {
        $meta = [];

        foreach (Status::cases() as $status) {
            $meta[$status->value] = ['label' => SidebarPanel::statusLabel($status), 'colour' => SidebarPanel::statusColour($status)];
        }

        return $meta;
    }

    /** A builder the listing shares: every state row. */
    public static function query(): Builder
    {
        return EntryState::query();
    }
}
