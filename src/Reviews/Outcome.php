<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reviews;

/**
 * What the reviewer decided. Stored in lifecycle_reviews.outcome.
 *
 * Four answers, in the reviewer's own words rather than the schema's: the
 * question is "is this page still right?", and these are the ways a person
 * answers it.
 */
enum Outcome: string
{
    /** Read it; it is accurate as it stands. */
    case Current = 'current';

    /** Read it and fixed it; it is accurate now. */
    case Updated = 'updated';

    /** It needs more than a fix, and that work is planned. */
    case RewriteScheduled = 'rewrite_scheduled';

    /** It should come down. */
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Current => __('Still current'),
            self::Updated => __('I updated it'),
            self::RewriteScheduled => __('Needs a rewrite, and one is planned'),
            self::Retired => __('Retire it'),
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $outcome) {
            $options[$outcome->value] = $outcome->label();
        }

        return $options;
    }
}
