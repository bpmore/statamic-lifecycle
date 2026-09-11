<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Actions;

use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Bpmore\StatamicLifecycle\Reviews\RecordReview;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

/**
 * "Mark reviewed" on an entry, one or many: from the listing, from the
 * entry's action menu, and — later — from the sidebar panel.
 *
 * The outcome is required; the note is not. A review with no outcome is a
 * tick in a box, and the record is worth more than that.
 */
class MarkReviewed extends Action
{
    public static function title()
    {
        return __('Mark reviewed');
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Entry;
    }

    public function visibleToBulk($items): bool
    {
        return $items->every(fn ($item): bool => $this->visibleTo($item));
    }

    /**
     * Whoever may edit the page may attest to it, for now. Spec §8 names a
     * separate `attest reviews` permission; that arrives with the rest of
     * the permissions in Phase 7 and replaces this.
     */
    public function authorize($user, $item): bool
    {
        return $item instanceof Entry && (bool) $user?->can('edit', $item);
    }

    public function buttonText()
    {
        return trans_choice('Mark reviewed|Mark :count reviewed', 'count');
    }

    public function confirmationText()
    {
        return __('You are confirming that this content is accurate as it stands. That goes on the record with your name and today\'s date.');
    }

    /** Nothing here touches the entry, so unsaved edits are not at risk. */
    public function bypassesDirtyWarning(): bool
    {
        return true;
    }

    protected function fieldItems()
    {
        return [
            'outcome' => [
                'display' => __('What did you find?'),
                'type' => 'select',
                'options' => Outcome::options(),
                'default' => Outcome::Current->value,
                'validate' => 'required|in:'.implode(',', array_keys(Outcome::options())),
            ],
            'note' => [
                'display' => __('Note'),
                'instructions' => __('Optional. Anything the next reviewer, or an auditor, should know.'),
                'type' => 'textarea',
                'validate' => 'nullable|string|max:2000',
            ],
        ];
    }

    public function run($items, $values): string
    {
        $user = User::current();

        if ($user === null) {
            return __('You need to be signed in to mark content reviewed.');
        }

        $outcome = Outcome::from((string) $values['outcome']);
        $recorder = app(RecordReview::class);

        foreach ($items as $entry) {
            $recorder->record($entry, (string) $user->id(), $outcome, $values['note'] ?? null);
        }

        return trans_choice(
            '{1}Marked :count page as reviewed.|[2,*]Marked :count pages as reviewed.',
            $items->count(),
            ['count' => $items->count()],
        );
    }
}
