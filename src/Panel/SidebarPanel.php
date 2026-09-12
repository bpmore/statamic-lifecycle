<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Panel;

use Bpmore\StatamicLifecycle\Auth\Permissions;
use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Expiry\ActionLog;
use Bpmore\StatamicLifecycle\Models\AutomatedAction;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Bpmore\StatamicLifecycle\Reviews\ReviewHistory;
use Bpmore\StatamicLifecycle\State\IntervalSource;
use Bpmore\StatamicLifecycle\State\NextReviewSource;
use Bpmore\StatamicLifecycle\State\OwnerSource;
use Bpmore\StatamicLifecycle\State\Status;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

/**
 * Everything the sidebar panel shows about one entry, in one array: where
 * it is in its cycle and why, who owns it and how sure that is, when a
 * person last confirmed it, the history, and what the panel may do next.
 *
 * Built once for the publish form (through the fieldtype) and again on
 * request after a review is recorded (through the refresh route), so the
 * panel never shows something it worked out for itself.
 */
final class SidebarPanel
{
    public function __construct(
        private readonly LifecycleDatabase $database,
        private readonly ReviewHistory $history,
        private readonly ActionLog $actions,
    ) {}

    /** @return array<string, mixed> */
    public function for(Entry $entry): array
    {
        $id = $entry->id();

        // A page being created has no ID yet, and nothing to say about it.
        if ($id === null || $id === '') {
            return $this->unsaved();
        }

        $id = (string) $id;
        $installed = $this->database->isInstalled();
        $row = $installed ? EntryState::query()->find($id) : null;
        $user = User::current();

        return [
            'installed' => $installed,
            'entry' => [
                'id' => $id,
                'published' => $entry->status() === 'published',
            ],
            'state' => $row === null ? null : $this->state($row),
            'timeline' => $installed ? $this->history->for($id)->toArray() : null,
            // The last thing Lifecycle did to the page that has not been put
            // back, if anything — and the one click that puts it back.
            'last_action' => $installed ? $this->lastAction($this->actions->outstanding($id)) : null,
            'outcomes' => Outcome::options(),
            // The same rules the actions apply, so the button never offers
            // what the action would refuse.
            'can_attest' => Permissions::mayAttest($user, $entry),
            'can_put_back' => Permissions::mayPutBack($user, $entry),
            'urls' => [
                'attest' => cp_route('collections.entries.actions.run', ['collection' => $entry->collectionHandle()]),
                'refresh' => cp_route('lifecycle.entries.status', ['entry' => $id]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function unsaved(): array
    {
        return [
            'installed' => $this->database->isInstalled(),
            'entry' => null,
            'state' => null,
            'timeline' => null,
            'last_action' => null,
            'outcomes' => Outcome::options(),
            'can_attest' => false,
            'can_put_back' => false,
            'urls' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function state(EntryState $row): array
    {
        $status = Status::from($row->status);
        $ownerSource = $row->owner_source === null ? null : OwnerSource::from($row->owner_source);
        $intervalSource = $row->interval_source === null ? null : IntervalSource::from($row->interval_source);
        $nextSource = $row->next_review_source === null ? null : NextReviewSource::from($row->next_review_source);

        return [
            'status' => $status->value,
            'status_label' => self::statusLabel($status),
            'colour' => self::statusColour($status),
            'next_review_at' => $row->next_review_at?->format(DATE_ATOM),
            'next_review_source' => $nextSource?->value,
            'next_review_reason' => $nextSource === null ? null : self::nextReviewReason($nextSource),
            'interval_days' => $row->interval_days,
            'interval_label' => $row->interval_days === null ? null : self::intervalLabel($row->interval_days),
            'interval_source' => $intervalSource?->value,
            'interval_reason' => $intervalSource === null ? null : self::intervalReason($intervalSource),
            'owner' => $row->owner_id === null ? null : [
                ...$this->person($row->owner_id),
                'source' => $ownerSource?->value,
                'source_label' => $ownerSource === null ? null : self::ownerSourceLabel($ownerSource),
            ],
            'last_reviewed_at' => $row->last_reviewed_at?->format(DATE_ATOM),
            'last_reviewed_by' => $row->last_reviewed_by === null ? null : $this->person($row->last_reviewed_by),
            'baselined' => $row->baselined,
            'baseline_review_at' => $row->baseline_review_at?->format(DATE_ATOM),
            'computed_at' => $row->computed_at->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed>|null */
    private function lastAction(?AutomatedAction $action): ?array
    {
        if ($action === null) {
            return null;
        }

        return [
            'id' => (int) $action->id,
            'action' => $action->action->value,
            'label' => $action->action->label(),
            'applied_at' => $action->applied_at->format(DATE_ATOM),
            'triggered_by' => $action->triggered_by,
        ];
    }

    /** @return array{id: string, name: string, exists: bool} */
    private function person(string $userId): array
    {
        $user = User::find($userId);

        return [
            'id' => $userId,
            'name' => $user === null ? $userId : ($user->name() ?: (string) $user->email()),
            'exists' => $user !== null,
        ];
    }

    public static function statusLabel(Status $status): string
    {
        return match ($status) {
            Status::Fresh => __('Fresh'),
            Status::DueSoon => __('Due soon'),
            Status::Overdue => __('Overdue'),
            Status::Expired => __('Expired'),
            Status::Exempt => __('Exempt'),
        };
    }

    /** Badge colours the control panel's UI kit knows. */
    public static function statusColour(Status $status): string
    {
        return match ($status) {
            Status::Fresh => 'green',
            Status::DueSoon => 'amber',
            Status::Overdue, Status::Expired => 'red',
            Status::Exempt => 'default',
        };
    }

    public static function nextReviewReason(NextReviewSource $source): string
    {
        return match ($source) {
            NextReviewSource::HardDate => __('the deadline set on this page'),
            NextReviewSource::LastReview => __('the last review plus the interval'),
            NextReviewSource::Baseline => __('the schedule set when Lifecycle was installed'),
            NextReviewSource::LastModified => __('the last change plus the interval'),
        };
    }

    public static function intervalReason(IntervalSource $source): string
    {
        return match ($source) {
            IntervalSource::Entry => __('set on this page'),
            IntervalSource::Blueprint => __('the default for this blueprint'),
            IntervalSource::Collection => __('the default for this collection'),
            IntervalSource::Global => __('the site default'),
        };
    }

    public static function ownerSourceLabel(OwnerSource $source): string
    {
        return match ($source) {
            OwnerSource::Assigned => __('chosen'),
            OwnerSource::Inferred => __('a guess, from the last person to edit it'),
            OwnerSource::Fallback => __('the owner of last resort'),
        };
    }

    /** "Quarterly" for 90 days; "Every 45 days" for anything without a name. */
    public static function intervalLabel(int $days): string
    {
        foreach (LifecycleFields::INTERVALS as $interval) {
            if ($interval['days'] === $days) {
                return __($interval['label']);
            }
        }

        return trans_choice('Every :count day|Every :count days', $days, ['count' => $days]);
    }
}
