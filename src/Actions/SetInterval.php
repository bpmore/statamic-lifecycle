<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Actions;

use Bpmore\StatamicLifecycle\Assignment\IntervalSetter;
use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\State\Policy;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;

/**
 * "Set review interval" on entries in the listing, one or many: filter to
 * a department's policies, select them all, make them quarterly. The last
 * of spec §8's bulk actions.
 *
 * No permission of its own: the interval is a field an editor sets in the
 * sidebar, and this is the same edit done forty times. Whoever may edit
 * the pages may set it.
 */
class SetInterval extends Action
{
    /** The option that means "no interval of its own: the collection's default". */
    public const DEFAULT = 'default';

    public static function title()
    {
        return __('Set review interval');
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Entry;
    }

    public function visibleToBulk($items): bool
    {
        return $items->every(fn ($item): bool => $this->visibleTo($item));
    }

    public function authorize($user, $item): bool
    {
        return $item instanceof Entry && $user !== null && (bool) $user->can('edit', $item);
    }

    public function buttonText()
    {
        return trans_choice('Set interval|Set interval on :count entries', 'count');
    }

    public function confirmationText()
    {
        return __('This writes the interval into each entry, like an edit — it shows in the entry\'s history and in git. A page already reviewed keeps its last review date; the next one moves.');
    }

    protected function fieldItems()
    {
        $options = [self::DEFAULT => __('The default for the collection')] + LifecycleFields::intervalOptions()
            + $this->policyNamed();

        return [
            'interval' => [
                'display' => __('Review interval'),
                'instructions' => __('How often someone should confirm these pages are still accurate.'),
                'type' => 'select',
                'options' => $options,
                'default' => self::DEFAULT,
                'validate' => 'required|in:'.implode(',', array_keys($options)),
            ],
        ];
    }

    /**
     * Intervals the site added under intervals.named, so the action offers
     * what the sidebar field would accept.
     *
     * @return array<string, string>
     */
    private function policyNamed(): array
    {
        $named = [];

        foreach (array_keys(app(Policy::class)->namedIntervals) as $name) {
            if (! isset(LifecycleFields::INTERVALS[$name])) {
                $named[$name] = ucfirst(str_replace('_', ' ', $name));
            }
        }

        return $named;
    }

    public function run($items, $values): string
    {
        $choice = (string) ($values['interval'] ?? self::DEFAULT);
        $interval = $choice === self::DEFAULT ? null : $choice;
        $result = app(IntervalSetter::class)->set($items, $interval);
        $label = $interval === null
            ? __('the collection\'s default')
            : strtolower((string) (LifecycleFields::intervalOptions()[$interval] ?? $this->policyNamed()[$interval] ?? $interval));

        if ($result->changed === []) {
            return trans_choice(
                '{1}That page already has :interval as its review interval.|[2,*]All :count pages already have :interval as their review interval.',
                count($result->unchanged),
                ['count' => count($result->unchanged), 'interval' => $label],
            );
        }

        $message = trans_choice(
            '{1}Set the review interval to :interval on :count page.|[2,*]Set the review interval to :interval on :count pages.',
            count($result->changed),
            ['count' => count($result->changed), 'interval' => $label],
        );

        if ($result->unchanged !== []) {
            $message .= ' '.trans_choice(
                '{1}:count already had it.|[2,*]:count already had it.',
                count($result->unchanged),
                ['count' => count($result->unchanged)],
            );
        }

        return $message;
    }
}
