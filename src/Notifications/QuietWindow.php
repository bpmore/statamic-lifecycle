<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Days nothing is sent: weekends by default, plus any dates the site names.
 *
 * Nothing is lost by a quiet day. What is owed stays owed, and goes out on
 * the next day that is not quiet — the log, not the calendar, decides what
 * has been said.
 */
final readonly class QuietWindow
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** @var list<string> */
    public array $days;

    /** @var list<string> Y-m-d */
    public array $dates;

    /**
     * @param  list<string>  $days  day names, any case
     * @param  list<string>  $dates  Y-m-d
     */
    public function __construct(array $days = ['saturday', 'sunday'], array $dates = [])
    {
        $normalised = [];

        foreach ($days as $day) {
            $day = strtolower(trim((string) $day));

            if (! in_array($day, self::DAYS, true)) {
                throw new InvalidArgumentException("[{$day}] is not a day of the week.");
            }

            $normalised[] = $day;
        }

        $this->days = array_values(array_unique($normalised));

        $this->dates = array_values(array_unique(array_map(function ($date): string {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== (string) $date) {
                throw new InvalidArgumentException("[{$date}] is not a date as Y-m-d.");
            }

            return $parsed->format('Y-m-d');
        }, $dates)));
    }

    /** @param  array<string, mixed>  $config  the `lifecycle.notifications` array */
    public static function fromConfig(array $config): self
    {
        return new self(
            days: array_values((array) ($config['quiet_days'] ?? ['saturday', 'sunday'])),
            dates: array_values((array) ($config['quiet_dates'] ?? [])),
        );
    }

    public function isQuiet(DateTimeImmutable $day): bool
    {
        return in_array(strtolower($day->format('l')), $this->days, true)
            || in_array($day->format('Y-m-d'), $this->dates, true);
    }

    /** Why today is quiet, in words, or null when it is not. */
    public function reason(DateTimeImmutable $day): ?string
    {
        if (in_array($day->format('Y-m-d'), $this->dates, true)) {
            return $day->format('j M Y').' is a quiet date';
        }

        if (in_array(strtolower($day->format('l')), $this->days, true)) {
            return 'it is '.$day->format('l');
        }

        return null;
    }
}
