<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use Statamic\Facades\Preference;
use Statamic\Facades\User;

/**
 * The one preference a person has about reminders: how often. It lives on
 * their user, is set on their own preferences screen, and changes nothing
 * about what is due — only whether email is how they hear.
 */
final class ReminderPreferences
{
    public const HANDLE = 'lifecycle_reminders';

    /** Puts the field on the control panel's preferences screen. */
    public static function register(): void
    {
        Preference::extend(fn () => [
            'lifecycle' => [
                'display' => __('Lifecycle'),
                'fields' => [
                    self::HANDLE => [
                        'type' => 'select',
                        'display' => __('Review reminders'),
                        'instructions' => __('How often to hear by email about pages you own that need a review. The dashboard shows them whatever you choose.'),
                        'options' => Frequency::options(),
                        'default' => Frequency::Daily->value,
                        'clearable' => false,
                    ],
                ],
            ],
        ]);
    }

    public function frequencyFor(string $userId): Frequency
    {
        return Frequency::fromPreference(User::find($userId)?->getPreference(self::HANDLE));
    }
}
