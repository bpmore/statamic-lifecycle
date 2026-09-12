<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reporting;

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Panel\SidebarPanel;
use Bpmore\StatamicLifecycle\State\IntervalSource;
use Bpmore\StatamicLifecycle\State\NextReviewSource;
use Bpmore\StatamicLifecycle\State\OwnerSource;
use Bpmore\StatamicLifecycle\State\Status;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * The list behind the dashboards and behind "My content": pages, with
 * their owner, date and state, filtered the ways spec §8 asks — overdue,
 * due soon, never reviewed, no owner, inferred owner — and by collection,
 * site and owner. Most overdue first.
 */
final class EntryListing
{
    public const FILTERS = ['status', 'attention', 'collection', 'site', 'owner'];

    public const ATTENTION = ['never_reviewed', 'no_owner', 'inferred_owner'];

    /**
     * @param  array<string, string|null>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $page = $this->query($filters)
            ->orderByRaw('case when next_review_at is null then 1 else 0 end')
            ->orderBy('next_review_at')
            ->orderBy('entry_id')
            ->paginate($perPage)
            ->withQueryString();

        $titles = Collection::all()->mapWithKeys(fn ($c) => [$c->handle() => (string) $c->title()])->all();
        $people = [];

        return $page->through(function (EntryState $row) use ($titles, &$people): array {
            $entry = Entry::find($row->entry_id);
            $status = Status::from($row->status);

            return [
                'id' => $row->entry_id,
                'title' => $entry === null ? $row->entry_id : (string) ($entry->get('title') ?: $entry->slug()),
                'uri' => $entry?->uri(),
                'edit_url' => $entry?->editUrl(),
                'exists' => $entry !== null,
                'collection' => $titles[$row->collection] ?? $row->collection,
                'site' => $row->site,
                'owner' => $row->owner_id === null ? null : $this->person($row->owner_id, $people),
                'owner_source' => $row->owner_source,
                'owner_source_label' => $row->owner_source === null ? null : SidebarPanel::ownerSourceLabel(OwnerSource::from($row->owner_source)),
                'status' => $status->value,
                'status_label' => SidebarPanel::statusLabel($status),
                'colour' => SidebarPanel::statusColour($status),
                'next_review_at' => $row->next_review_at?->format('Y-m-d'),
                'next_review_reason' => $row->next_review_source === null ? null : SidebarPanel::nextReviewReason(NextReviewSource::from($row->next_review_source)),
                'interval_label' => $row->interval_days === null ? null : SidebarPanel::intervalLabel($row->interval_days),
                'interval_reason' => $row->interval_source === null ? null : SidebarPanel::intervalReason(IntervalSource::from($row->interval_source)),
                'last_reviewed_at' => $row->last_reviewed_at?->format('Y-m-d'),
                'last_reviewed_by' => $row->last_reviewed_by === null ? null : $this->person($row->last_reviewed_by, $people)['name'],
            ];
        });
    }

    /** @param  array<string, string|null>  $filters */
    public function query(array $filters): Builder
    {
        return EntryState::query()
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['collection'] ?? null, fn (Builder $q, string $c) => $q->where('collection', $c))
            ->when($filters['site'] ?? null, fn (Builder $q, string $s) => $q->where('site', $s))
            ->when($filters['owner'] ?? null, fn (Builder $q, string $o) => $q->where('owner_id', $o))
            ->when($filters['attention'] ?? null, fn (Builder $q, string $a) => match ($a) {
                'never_reviewed' => $q->whereNull('last_reviewed_at')->where('status', '!=', Status::Exempt->value),
                'no_owner' => $q->whereNull('owner_id'),
                'inferred_owner' => $q->where('owner_source', OwnerSource::Inferred->value),
                default => $q,
            });
    }

    /**
     * The filters as the request gave them, kept to what the listing knows.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    public static function filtersFrom(array $input): array
    {
        $filters = [];

        foreach (self::FILTERS as $name) {
            $value = trim((string) ($input[$name] ?? ''));
            $filters[$name] = $value === '' ? null : $value;
        }

        if ($filters['status'] !== null && Status::tryFrom($filters['status']) === null) {
            $filters['status'] = null;
        }
        if ($filters['attention'] !== null && ! in_array($filters['attention'], self::ATTENTION, true)) {
            $filters['attention'] = null;
        }

        return $filters;
    }

    /** @param  array<string, array{id: string, name: string, exists: bool}>  $cache  @return array{id: string, name: string, exists: bool} */
    private function person(string $userId, array &$cache): array
    {
        if (! isset($cache[$userId])) {
            $user = User::find($userId);
            $cache[$userId] = ['id' => $userId, 'name' => $user === null ? $userId : ($user->name() ?: (string) $user->email()), 'exists' => $user !== null];
        }

        return $cache[$userId];
    }
}
