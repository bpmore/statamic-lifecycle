<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\State\BaselineCandidate;
use Bpmore\StatamicLifecycle\State\BaselinePlanner;

$today = new DateTimeImmutable('2026-09-11 15:42');   // a time of day, to prove it is dropped

/** @return list<BaselineCandidate> $count entries modified one per day, oldest first, all on one interval */
function candidates(int $count, int $intervalDays = 365, string $prefix = 'e'): array
{
    $list = [];
    for ($i = 0; $i < $count; $i++) {
        $list[] = new BaselineCandidate("{$prefix}-{$i}", (new DateTimeImmutable('2020-01-01'))->modify("+{$i} days"), $intervalDays);
    }

    return $list;
}

/** @param  array<string, DateTimeImmutable>  $plan */
function offsetsInDays(array $plan, DateTimeImmutable $today): array
{
    $start = $today->setTime(0, 0);

    return array_map(fn (DateTimeImmutable $d): int => (int) $start->diff($d)->days, $plan);
}

it('plans nothing for nothing', function () use ($today) {
    expect((new BaselinePlanner)->plan([], 365, $today))->toBe([]);
});

it('puts a lone entry first thing tomorrow', function () use ($today) {
    $plan = (new BaselinePlanner)->plan(candidates(1), 365, $today);

    expect($plan)->toHaveCount(1)
        ->and($plan['e-0'])->toEqual(new DateTimeImmutable('2026-09-12 00:00'));
});

it('spreads across (0, horizon], oldest content first, at the start of each day', function () use ($today) {
    $plan = (new BaselinePlanner)->plan(candidates(10), 365, $today);
    $offsets = offsetsInDays($plan, $today);

    // Oldest tomorrow, then every ~36 days, never past the horizon.
    expect(array_keys($plan))->toBe(array_map(fn (int $i): string => "e-{$i}", range(0, 9)))
        ->and(min($offsets))->toBe(1)
        ->and(max($offsets))->toBeLessThanOrEqual(365)
        ->and(array_values($offsets))->toBe([1, 37, 74, 110, 147, 183, 220, 256, 293, 329]);

    foreach ($plan as $date) {
        expect($date->format('H:i:s'))->toBe('00:00:00');
    }
});

it('is a genuine spread, not a cluster, when there are more entries than days', function () use ($today) {
    // 10,000 entries over a year is 27.4 a day. Every day should carry 27
    // or 28 — never a pile on one day and nothing the next.
    $plan = (new BaselinePlanner)->plan(candidates(10_000), 365, $today);
    $perDay = array_count_values(offsetsInDays($plan, $today));

    expect($plan)->toHaveCount(10_000)
        ->and(count($perDay))->toBe(365)
        ->and(min($perDay))->toBeGreaterThanOrEqual(27)
        ->and(max($perDay))->toBeLessThanOrEqual(28);
});

it('keeps order by last modification within the spread', function () use ($today) {
    $plan = (new BaselinePlanner)->plan(candidates(1_000), 365, $today);
    $offsets = array_values(offsetsInDays($plan, $today));

    // e-0 is the oldest and the keys came out in modification order; the
    // offsets must never go backwards along that order.
    for ($i = 1; $i < count($offsets); $i++) {
        expect($offsets[$i])->toBeGreaterThanOrEqual($offsets[$i - 1]);
    }
});

it('bounds the window by a shorter interval, and spreads within it rather than piling at its end', function () use ($today) {
    // A quarterly page cannot sensibly get its first review eleven months
    // out. But clamping would put every quarterly page on day 90.
    $plan = (new BaselinePlanner)->plan(candidates(900, intervalDays: 90, prefix: 'q'), 365, $today);
    $offsets = offsetsInDays($plan, $today);
    $perDay = array_count_values($offsets);

    expect(max($offsets))->toBeLessThanOrEqual(90)
        ->and(count($perDay))->toBe(90)
        ->and(max($perDay))->toBe(10)
        ->and(min($perDay))->toBe(10);
});

it('spreads each interval group on its own', function () use ($today) {
    $yearly = candidates(365, intervalDays: 365, prefix: 'y');
    $monthly = candidates(30, intervalDays: 30, prefix: 'm');

    $plan = (new BaselinePlanner)->plan([...$yearly, ...$monthly], 365, $today);
    $offsets = offsetsInDays($plan, $today);

    $monthlyOffsets = array_filter($offsets, fn (string $id): bool => str_starts_with($id, 'm-'), ARRAY_FILTER_USE_KEY);
    $yearlyOffsets = array_filter($offsets, fn (string $id): bool => str_starts_with($id, 'y-'), ARRAY_FILTER_USE_KEY);

    // The monthly thirty take days 1..30; the yearly 365 take days 1..365.
    // Neither group's layout depends on the other's size.
    expect(array_values($monthlyOffsets))->toBe(range(1, 30))
        ->and(array_values($yearlyOffsets))->toBe(range(1, 365));
});

it('never reaches beyond the horizon whatever the interval', function () use ($today) {
    $plan = (new BaselinePlanner)->plan(candidates(50, intervalDays: 1095), 365, $today);

    expect(max(offsetsInDays($plan, $today)))->toBeLessThanOrEqual(365);
});

it('breaks ties on ID so two runs agree', function () use ($today) {
    $same = new DateTimeImmutable('2021-06-01');
    $a = [new BaselineCandidate('b', $same, 365), new BaselineCandidate('a', $same, 365), new BaselineCandidate('c', $same, 365)];
    $b = [new BaselineCandidate('c', $same, 365), new BaselineCandidate('a', $same, 365), new BaselineCandidate('b', $same, 365)];

    expect((new BaselinePlanner)->plan($a, 365, $today))->toEqual((new BaselinePlanner)->plan($b, 365, $today))
        ->and(array_keys((new BaselinePlanner)->plan($a, 365, $today)))->toBe(['a', 'b', 'c']);
});

it('refuses a horizon shorter than a day', function () use ($today) {
    expect(fn () => (new BaselinePlanner)->plan(candidates(1), 0, $today))->toThrow(InvalidArgumentException::class);
});
