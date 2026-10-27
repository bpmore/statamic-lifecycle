<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\State\Days;
use Carbon\CarbonImmutable;

it('counts the days in a year from a Carbon date, which Carbon 3.8 counted as none', function () {
    $today = CarbonImmutable::parse('2026-09-11')->setTime(0, 0);

    expect(Days::between($today, $today->modify('+12 months')))->toBe(365);
});

it('counts the same either way round, and the same for plain dates', function () {
    $due = new DateTimeImmutable('2026-09-01');
    $today = CarbonImmutable::parse('2026-09-11 15:42');

    expect(Days::between($due, $today))->toBe(10)
        ->and(Days::between($today, $due))->toBe(10)
        ->and(Days::between($due, new DateTimeImmutable('2026-09-11')))->toBe(10);
});

it('counts a day across a clock change as one day', function () {
    $before = new DateTimeImmutable('2026-03-08 00:00', new DateTimeZone('America/Chicago'));

    expect(Days::between($before, $before->modify('+1 day')))->toBe(1);
});
