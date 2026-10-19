<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Actions;

use Bpmore\StatamicLifecycle\Auth\Permissions;
use Bpmore\StatamicLifecycle\Exemptions\ExemptionWriter;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;

/**
 * "Clear exemption": the page goes back into the review cycle. Its next
 * review date is worked out as for any other page — from its last review,
 * its baseline date, or its last modification — so a page exempt for
 * years may come back overdue, which is the honest reading.
 *
 * Offered where at least one selected page is exempt.
 */
class ClearExemption extends Action
{
    public static function title()
    {
        return __('Clear exemption');
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Entry && ExemptionWriter::isExempt($item);
    }

    public function visibleToBulk($items): bool
    {
        return $items->every(fn ($item): bool => $item instanceof Entry)
            && $items->contains(fn ($item): bool => $this->visibleTo($item));
    }

    /** The same permission as exempting: both write the entry. */
    public function authorize($user, $item): bool
    {
        return $item instanceof Entry && Permissions::mayManageExemptions($user, $item);
    }

    public function buttonText()
    {
        return trans_choice('Clear it|Clear :count exemptions', 'count');
    }

    public function confirmationText()
    {
        return __('The page goes back into the review cycle and gets a review date like any other. It writes the entry, like an edit.');
    }

    public function run($items, $values): string
    {
        $result = app(ExemptionWriter::class)->set($items, false);

        if ($result->changed === []) {
            return trans_choice(
                '{1}That page was not exempt.|[2,*]None of the :count pages were exempt.',
                count($result->unchanged),
                ['count' => count($result->unchanged)],
            );
        }

        $message = trans_choice(
            '{1}Cleared the exemption on :count page; it is back in the review cycle.|[2,*]Cleared the exemption on :count pages; they are back in the review cycle.',
            count($result->changed),
            ['count' => count($result->changed)],
        );

        if ($result->unchanged !== []) {
            $message .= ' '.trans_choice(
                '{1}:count was not exempt.|[2,*]:count were not exempt.',
                count($result->unchanged),
                ['count' => count($result->unchanged)],
            );
        }

        return $message;
    }
}
