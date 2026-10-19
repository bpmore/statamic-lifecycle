<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Actions;

use Bpmore\StatamicLifecycle\Auth\Permissions;
use Bpmore\StatamicLifecycle\Exemptions\ExemptionWriter;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;

/**
 * "Exempt from review" on entries in the listing, one or many: the page is
 * fixed at a point in time — a published report, a minuted decision, an
 * archive — and will never need a person to confirm it again.
 *
 * Offered where at least one selected page is not already exempt; the
 * ones that are get left alone and counted.
 */
class Exempt extends Action
{
    public static function title()
    {
        return __('Exempt from review');
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Entry && ! ExemptionWriter::isExempt($item);
    }

    public function visibleToBulk($items): bool
    {
        return $items->every(fn ($item): bool => $item instanceof Entry)
            && $items->contains(fn ($item): bool => $this->visibleTo($item));
    }

    /** Spec §8's `manage exemptions`, on a page the person may edit — it writes the entry. */
    public function authorize($user, $item): bool
    {
        return $item instanceof Entry && Permissions::mayManageExemptions($user, $item);
    }

    public function buttonText()
    {
        return trans_choice('Exempt it|Exempt :count entries', 'count');
    }

    public function confirmationText()
    {
        return __('This takes the page out of the review cycle: no review date, no reminders, no expiry action. It writes the entry, like an edit — it shows in the entry\'s history and in git.');
    }

    public function run($items, $values): string
    {
        $result = app(ExemptionWriter::class)->set($items, true);

        if ($result->changed === []) {
            return trans_choice(
                '{1}That page is already exempt from review.|[2,*]All :count pages are already exempt from review.',
                count($result->unchanged),
                ['count' => count($result->unchanged)],
            );
        }

        $message = trans_choice(
            '{1}Exempted :count page from review.|[2,*]Exempted :count pages from review.',
            count($result->changed),
            ['count' => count($result->changed)],
        );

        if ($result->unchanged !== []) {
            $message .= ' '.trans_choice(
                '{1}:count already was.|[2,*]:count already were.',
                count($result->unchanged),
                ['count' => count($result->unchanged)],
            );
        }

        return $message;
    }
}
