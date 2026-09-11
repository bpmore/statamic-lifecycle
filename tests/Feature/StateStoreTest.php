<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\State\IntervalSource;
use Bpmore\StatamicLifecycle\State\LifecycleState;
use Bpmore\StatamicLifecycle\State\NextReviewSource;
use Bpmore\StatamicLifecycle\State\OwnerSource;
use Bpmore\StatamicLifecycle\State\PriorState;
use Bpmore\StatamicLifecycle\State\Status;
use Bpmore\StatamicLifecycle\Storage\StateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function aState(string $id, array $overrides = []): LifecycleState
{
    return new LifecycleState(...array_merge([
        'entryId' => $id,
        'site' => 'default',
        'collection' => 'pages',
        'ownerId' => 'alice',
        'ownerType' => 'user',
        'ownerSource' => OwnerSource::Inferred,
        'intervalDays' => 365,
        'intervalSource' => IntervalSource::Global,
        'lastReviewedAt' => null,
        'lastReviewedBy' => null,
        'nextReviewAt' => new DateTimeImmutable('2027-01-15 00:00'),
        'nextReviewSource' => NextReviewSource::Baseline,
        'status' => Status::Fresh,
        'baselined' => true,
        'computedAt' => new DateTimeImmutable('2026-09-11 10:00'),
    ], $overrides));
}

it('writes a state as a row and reads the prior state back from it', function () {
    $store = new StateStore;

    $store->put(aState('e-1', [
        'lastReviewedAt' => new DateTimeImmutable('2026-06-01 09:30'),
        'lastReviewedBy' => 'bob',
        'nextReviewAt' => new DateTimeImmutable('2027-06-01 09:30'),
        'nextReviewSource' => NextReviewSource::LastReview,
    ]), new DateTimeImmutable('2027-01-15'));

    $row = EntryState::query()->find('e-1');

    expect($row->site)->toBe('default')
        ->and($row->owner_id)->toBe('alice')
        ->and($row->owner_source)->toBe('inferred')
        ->and($row->interval_days)->toBe(365)
        ->and($row->interval_source)->toBe('global')
        ->and($row->next_review_source)->toBe('last_review')
        ->and($row->status)->toBe('fresh')
        ->and($row->baselined)->toBeTrue()
        ->and($row->baseline_review_at->format('Y-m-d'))->toBe('2027-01-15')
        ->and($row->computed_at->format('Y-m-d H:i'))->toBe('2026-09-11 10:00');

    $prior = $store->prior('e-1');

    expect($prior->lastReviewedAt)->toEqual(new DateTimeImmutable('2026-06-01 09:30'))
        ->and($prior->lastReviewedBy)->toBe('bob')
        ->and($prior->baselined)->toBeTrue()
        ->and($prior->baselineNextReviewAt)->toEqual(new DateTimeImmutable('2027-01-15 00:00'));
});

it('answers none for an entry it has never seen', function () {
    expect((new StateStore)->prior('nope'))->toEqual(PriorState::none())
        ->and((new StateStore)->priorFor(['a', 'b']))->toEqual(['a' => PriorState::none(), 'b' => PriorState::none()]);
});

it('replaces a row rather than duplicating it', function () {
    $store = new StateStore;

    $store->put(aState('e-1', ['status' => Status::Fresh]));
    $store->put(aState('e-1', ['status' => Status::Overdue, 'ownerId' => 'carol', 'ownerSource' => OwnerSource::Assigned]));

    expect(EntryState::query()->count())->toBe(1)
        ->and(EntryState::query()->find('e-1')->status)->toBe('overdue')
        ->and(EntryState::query()->find('e-1')->owner_id)->toBe('carol');
});

it('writes many rows in a few statements, not one each', function () {
    $states = [];
    for ($i = 0; $i < 1_200; $i++) {
        $states[] = aState("e-{$i}");
    }

    DB::connection()->enableQueryLog();
    $written = (new StateStore)->putMany($states);
    $writes = collect(DB::connection()->getQueryLog())->filter(fn (array $q): bool => str_starts_with(strtolower($q['query']), 'insert'));

    expect($written)->toBe(1_200)
        ->and(EntryState::query()->count())->toBe(1_200)
        // 1,200 rows at 500 a statement is three.
        ->and($writes)->toHaveCount(3);
});

it('reads prior state for many entries in a few queries', function () {
    $states = [];
    for ($i = 0; $i < 1_200; $i++) {
        $states[] = aState("e-{$i}");
    }
    (new StateStore)->putMany($states);

    $ids = array_map(fn (int $i): string => "e-{$i}", range(0, 1_199));
    $ids[] = 'missing';

    DB::connection()->enableQueryLog();
    $prior = (new StateStore)->priorFor($ids);

    expect($prior)->toHaveCount(1_201)
        ->and($prior['e-7']->baselined)->toBeTrue()
        ->and($prior['missing'])->toEqual(PriorState::none())
        ->and(DB::connection()->getQueryLog())->toHaveCount(3);
});
