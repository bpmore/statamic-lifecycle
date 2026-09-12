<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

/**
 * How often one person wants to hear. A preference on the user, never on
 * the record: muting email changes nothing about what is due or what the
 * dashboard shows (spec §5).
 */
enum Frequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Never = 'never';

    public function label(): string
    {
        return match ($this) {
            self::Daily => __('Daily, when there is something'),
            self::Weekly => __('Weekly at most'),
            self::Never => __('Never by email — I will use the dashboard'),
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $frequency) {
            $options[$frequency->value] = $frequency->label();
        }

        return $options;
    }

    /** From whatever a preference holds: daily unless it clearly says otherwise. */
    public static function fromPreference(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Daily) : self::Daily;
    }
}
