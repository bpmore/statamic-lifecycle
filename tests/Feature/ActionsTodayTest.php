<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionsToday;
use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\DailyCap;
use Bpmore\StatamicLifecycle\Models\AutomatedAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function actionRow(string $id, string $action, string $appliedAt): void
{
    AutomatedAction::query()->insert([
        'entry_id' => $id, 'action' => $action, 'applied_at' => $appliedAt, 'triggered_by' => 'lifecycle:expire',
    ]);
}

it('counts the day\'s actions from the log, and nothing from other days', function () {
    actionRow('a', 'unpublish', '2026-09-11 00:00:00');   // first second of the day
    actionRow('b', 'noindex', '2026-09-11 13:15:00');
    actionRow('c', 'banner', '2026-09-11 23:59:59');       // last second
    actionRow('d', 'unpublish', '2026-09-10 23:59:59');    // yesterday
    actionRow('e', 'unpublish', '2026-09-12 00:00:00');    // tomorrow

    expect((new ActionsToday)->count(new DateTimeImmutable('2026-09-11 15:00')))->toBe(3);
});

it('does not count a restore against the cap', function () {
    actionRow('a', 'unpublish', '2026-09-11 10:00:00');
    actionRow('a', 'restore', '2026-09-11 11:00:00');

    expect((new ActionsToday)->count(new DateTimeImmutable('2026-09-11')))->toBe(1);
});

it('is built from config', function () {
    config(['lifecycle.expiry.daily_cap' => 40]);

    expect(app(DailyCap::class)->perDay)->toBe(40);
});

it('reads the row back with its types', function () {
    AutomatedAction::query()->insert([
        'entry_id' => 'a', 'action' => 'unpublish', 'applied_at' => '2026-09-11 10:00:00', 'triggered_by' => 'lifecycle:expire',
        'previous_state' => json_encode(['published' => true]),
    ]);

    $row = AutomatedAction::query()->first();

    expect($row->action)->toBe(ActionType::Unpublish)
        ->and($row->applied_at)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($row->previous_state)->toBe(['published' => true])
        ->and($row->reverted_at)->toBeNull();
});
