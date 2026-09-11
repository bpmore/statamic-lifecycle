<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Blueprint;

/**
 * The three fields an editor authors: who owns the page, how often it needs
 * looking at, and any hard deadline.
 *
 * These live in the entry, not in the addon's tables (spec §3): an owner is an
 * editorial decision, it belongs in content, it should show in git and travel
 * with revisions. Everything computed from them — next review date, status,
 * the attestation record — lives in the tables and never touches the entry.
 *
 * One definition, used wherever the fields are needed, so the handles the rest
 * of the addon reads are the handles the blueprint writes.
 */
final class LifecycleFields
{
    public const CONTENT_OWNER = 'content_owner';

    public const REVIEW_INTERVAL = 'review_interval';

    public const REVIEW_BY = 'review_by';

    /**
     * The intervals an editor can pick, stored by name rather than by number.
     *
     * `review_interval: quarterly` in a file is an author's intent and reads as
     * one; `review_interval: 90` is a setting. The number of days each name
     * means is decided here, once, and the computation reads it from here.
     */
    public const INTERVALS = [
        'monthly' => ['days' => 30, 'label' => 'Monthly'],
        'quarterly' => ['days' => 90, 'label' => 'Quarterly'],
        'every_6_months' => ['days' => 180, 'label' => 'Every six months'],
        'yearly' => ['days' => 365, 'label' => 'Yearly'],
        'every_2_years' => ['days' => 730, 'label' => 'Every two years'],
        'every_3_years' => ['days' => 1095, 'label' => 'Every three years'],
    ];

    /**
     * Field configs keyed by handle, in the order they appear.
     *
     * All three are localizable: a translation can be stale while the original
     * is current, so its owner and cadence are its own (spec §11). All three
     * are available as listing columns but hidden until somebody turns them
     * on — a governance tool does not get to redesign every entry listing.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            self::CONTENT_OWNER => [
                'type' => 'users',
                'display' => __('Content owner'),
                'instructions' => __('The person who knows whether this page is still accurate. They get the reminder when a review is due.'),
                'max_items' => 1,
                'mode' => 'select',
                'localizable' => true,
                'listable' => 'hidden',
            ],
            self::REVIEW_INTERVAL => [
                'type' => 'select',
                'display' => __('Review interval'),
                'instructions' => __('How often someone should confirm this page is still accurate. Leave it empty to use the default for this collection.'),
                'options' => self::intervalOptions(),
                'clearable' => true,
                'localizable' => true,
                'listable' => 'hidden',
            ],
            self::REVIEW_BY => [
                'type' => 'date',
                'display' => __('Review by'),
                'instructions' => __('A hard deadline that overrides the interval. Use it when something outside the page sets the date: an accreditation visit, a board meeting, a rule that changes.'),
                'mode' => 'single',
                'time_enabled' => false,
                'localizable' => true,
                'listable' => 'hidden',
            ],
        ];
    }

    /** @return array<string, string> interval name => label */
    public static function intervalOptions(): array
    {
        return array_map(fn (array $interval): string => __($interval['label']), self::INTERVALS);
    }

    /**
     * How many days an interval name means, or null for a name this addon
     * does not know — a site that replaced the options with its own is not
     * an error here, it is the computation's problem to resolve.
     */
    public static function intervalDays(string $name): ?int
    {
        return self::INTERVALS[$name]['days'] ?? null;
    }
}
