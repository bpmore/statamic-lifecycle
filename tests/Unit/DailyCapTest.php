<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\Candidate;
use Bpmore\StatamicLifecycle\Expiry\DailyCap;

/** @return list<Candidate> */
function wanting(int $count, string $action = 'unpublish', string $prefix = 'e'): array
{
    $candidates = [];
    for ($i = 0; $i < $count; $i++) {
        $candidates[] = new Candidate("{$prefix}-{$i}", 'default', 'pages', ActionType::from($action));
    }

    return $candidates;
}

it('lets twenty-five through on a fresh day, and puts the rest off', function () {
    $verdict = (new DailyCap)->check(wanting(40), usedToday: 0);

    expect($verdict->cap)->toBe(25)
        ->and($verdict->remaining())->toBe(25)
        ->and($verdict->allowed)->toHaveCount(25)
        ->and($verdict->deferred)->toHaveCount(15)
        ->and($verdict->reason())->toBe('Put off 15 actions until tomorrow: 25 a day is the cap, and 0 were already taken today.');
});

it('counts what the day has already spent', function () {
    $verdict = (new DailyCap)->check(wanting(10), usedToday: 20);

    expect($verdict->allowed)->toHaveCount(5)
        ->and($verdict->deferred)->toHaveCount(5)
        ->and($verdict->reason())->toBe('Put off 5 actions until tomorrow: 25 a day is the cap, and 20 were already taken today.');
});

it('holds everything when the day is already full, or over', function () {
    expect((new DailyCap)->check(wanting(3), usedToday: 25)->allowed)->toBe([])
        ->and((new DailyCap)->check(wanting(3), usedToday: 30)->deferred)->toHaveCount(3)
        ->and((new DailyCap)->check(wanting(3), usedToday: 30)->remaining())->toBe(0);
});

it('takes them in the order the run wants them done', function () {
    // The run puts the most overdue first; the cap must not reorder it.
    $verdict = (new DailyCap(2))->check(wanting(4), usedToday: 0);

    expect(array_map(fn ($c) => $c->entryId, $verdict->allowed))->toBe(['e-0', 'e-1'])
        ->and(array_map(fn ($c) => $c->entryId, $verdict->deferred))->toBe(['e-2', 'e-3']);
});

it('never counts or holds a restore', function () {
    $verdict = (new DailyCap(2))->check([...wanting(3), ...wanting(3, 'restore', 'r')], usedToday: 1);

    expect(array_map(fn ($c) => $c->entryId, $verdict->allowed))->toBe(['e-0', 'r-0', 'r-1', 'r-2'])
        ->and(array_map(fn ($c) => $c->entryId, $verdict->deferred))->toBe(['e-1', 'e-2']);
});

it('says nothing when nothing was held back', function () {
    expect((new DailyCap)->check(wanting(5), usedToday: 0)->reason())->toBeNull()
        ->and((new DailyCap)->check([], usedToday: 0)->allowed)->toBe([]);
});

it('can be zero, which stops everything but a way back', function () {
    $verdict = (new DailyCap(0))->check([...wanting(2), ...wanting(1, 'restore', 'r')], usedToday: 0);

    expect(array_map(fn ($c) => $c->entryId, $verdict->allowed))->toBe(['r-0'])
        ->and($verdict->deferred)->toHaveCount(2);
});

it('is read from config, twenty-five unless told otherwise, never negative', function () {
    expect(DailyCap::fromConfig([])->perDay)->toBe(25)
        ->and(DailyCap::fromConfig(['daily_cap' => 100])->perDay)->toBe(100)
        ->and(DailyCap::fromConfig(['daily_cap' => -5])->perDay)->toBe(0);
    expect(fn () => new DailyCap(-1))->toThrow(InvalidArgumentException::class);
});
