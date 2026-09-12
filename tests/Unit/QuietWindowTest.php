<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Notifications\Frequency;
use Bpmore\StatamicLifecycle\Notifications\QuietWindow;

it('is the weekend by default', function () {
    $quiet = QuietWindow::fromConfig([]);

    expect($quiet->days)->toBe(['saturday', 'sunday'])
        ->and($quiet->isQuiet(new DateTimeImmutable('2026-09-12')))->toBeTrue()    // Saturday
        ->and($quiet->isQuiet(new DateTimeImmutable('2026-09-13')))->toBeTrue()    // Sunday
        ->and($quiet->isQuiet(new DateTimeImmutable('2026-09-14')))->toBeFalse()   // Monday
        ->and($quiet->reason(new DateTimeImmutable('2026-09-12')))->toBe('it is Saturday')
        ->and($quiet->reason(new DateTimeImmutable('2026-09-14')))->toBeNull();
});

it('takes the site\'s days, any case, and dates for holidays', function () {
    $quiet = QuietWindow::fromConfig(['quiet_days' => ['Friday', 'SATURDAY'], 'quiet_dates' => ['2026-12-25', '2026-12-25', '2027-01-01']]);

    expect($quiet->days)->toBe(['friday', 'saturday'])
        ->and($quiet->dates)->toBe(['2026-12-25', '2027-01-01'])
        ->and($quiet->isQuiet(new DateTimeImmutable('2026-09-13')))->toBeFalse()   // a Sunday, no longer quiet
        ->and($quiet->isQuiet(new DateTimeImmutable('2026-12-25')))->toBeTrue()    // a Friday anyway, but named
        ->and($quiet->reason(new DateTimeImmutable('2026-12-25')))->toBe('25 Dec 2026 is a quiet date')
        ->and((new QuietWindow([], []))->isQuiet(new DateTimeImmutable('2026-09-13')))->toBeFalse();
});

it('refuses a day or date it cannot read', function () {
    expect(fn () => new QuietWindow(['weekend']))->toThrow(InvalidArgumentException::class, '[weekend] is not a day of the week.');
    expect(fn () => new QuietWindow([], ['25/12/2026']))->toThrow(InvalidArgumentException::class, '[25/12/2026] is not a date as Y-m-d.');
});

it('reads a frequency from whatever a preference holds, daily unless it clearly says otherwise', function () {
    expect(Frequency::fromPreference('weekly'))->toBe(Frequency::Weekly)
        ->and(Frequency::fromPreference('never'))->toBe(Frequency::Never)
        ->and(Frequency::fromPreference(null))->toBe(Frequency::Daily)
        ->and(Frequency::fromPreference('sometimes'))->toBe(Frequency::Daily)
        ->and(Frequency::fromPreference(['weekly']))->toBe(Frequency::Daily);
});
