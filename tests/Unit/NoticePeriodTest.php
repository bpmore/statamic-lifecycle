<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\Candidate;
use Bpmore\StatamicLifecycle\Expiry\Notice;
use Bpmore\StatamicLifecycle\Expiry\NoticePeriod;

function candidate(string $id = 'page', string $action = 'unpublish', string $due = '2026-08-01'): Candidate
{
    return new Candidate($id, 'default', 'pages', ActionType::from($action), new DateTimeImmutable($due));
}

function notice(string $sentAt, string $id = 'page', string $action = 'unpublish', string $due = '2026-08-01'): Notice
{
    return new Notice($id, ActionType::from($action), new DateTimeImmutable($due), new DateTimeImmutable($sentAt));
}

function sorted(array $candidates, array $notices, string $today, ?NoticePeriod $period = null): array
{
    $verdict = ($period ?? new NoticePeriod)->check($candidates, $notices, new DateTimeImmutable($today));

    return [
        'allowed' => array_map(fn ($c) => $c->entryId, $verdict->allowed),
        'waiting' => array_map(fn ($w) => $w->candidate->entryId.' until '.$w->actionableFrom->format('Y-m-d'), $verdict->waiting),
        'notify' => array_map(fn ($c) => $c->entryId, $verdict->toNotify),
    ];
}

it('turns a page never noticed into a notice, and does nothing else to it', function () {
    expect(sorted([candidate()], [], '2026-09-11'))->toBe(['allowed' => [], 'waiting' => [], 'notify' => ['page']]);
});

it('makes a page wait until the period has passed, and says until when', function () {
    $noticed = notice('2026-09-10 07:00');

    expect(sorted([candidate()], [$noticed], '2026-09-11'))->toBe(['allowed' => [], 'waiting' => ['page until 2026-09-17'], 'notify' => []])
        ->and(sorted([candidate()], [$noticed], '2026-09-16'))->toBe(['allowed' => [], 'waiting' => ['page until 2026-09-17'], 'notify' => []]);
});

it('lets a page through once the period has passed', function () {
    $noticed = notice('2026-09-10 07:00');

    expect(sorted([candidate()], [$noticed], '2026-09-17'))->toBe(['allowed' => ['page'], 'waiting' => [], 'notify' => []])
        ->and(sorted([candidate()], [$noticed], '2026-10-01'))->toBe(['allowed' => ['page'], 'waiting' => [], 'notify' => []]);
});

it('is never the same run, even with a period of zero', function () {
    $zero = new NoticePeriod(0);
    $noticed = notice('2026-09-11 07:00');

    expect(sorted([candidate()], [$noticed], '2026-09-11 09:00', $zero)['waiting'])->toBe(['page until 2026-09-12'])
        ->and(sorted([candidate()], [$noticed], '2026-09-12', $zero)['allowed'])->toBe(['page']);
});

it('counts from the first notice, not a later repeat', function () {
    $notices = [notice('2026-09-14 07:00'), notice('2026-09-10 07:00')];

    expect(sorted([candidate()], $notices, '2026-09-17')['allowed'])->toBe(['page']);
});

it('counts per action: notice of a banner is not notice of an unpublish', function () {
    $bannerNotice = notice('2026-09-01 07:00', action: 'banner');

    expect(sorted([candidate(action: 'banner'), candidate(action: 'unpublish')], [$bannerNotice], '2026-09-11'))
        ->toBe(['allowed' => ['page'], 'waiting' => [], 'notify' => ['page']]);
});

it('counts per cycle: a review starts over', function () {
    $oldNotice = notice('2026-01-05 07:00', due: '2026-01-01');

    // Same page, same action, new review date: never noticed for this one.
    expect(sorted([candidate(due: '2026-08-01')], [$oldNotice], '2026-09-11')['notify'])->toBe(['page']);
});

it('needs no notice for a restore', function () {
    $restore = new Candidate('page', 'default', 'pages', ActionType::Restore);

    expect(sorted([$restore], [], '2026-09-11')['allowed'])->toBe(['page']);
});

it('keeps pages apart', function () {
    $notices = [notice('2026-09-01 07:00', id: 'a')];

    expect(sorted([candidate('a'), candidate('b'), candidate('c')], $notices, '2026-09-11'))
        ->toBe(['allowed' => ['a'], 'waiting' => [], 'notify' => ['b', 'c']]);
});

it('is read from config, seven days unless told otherwise, never negative', function () {
    expect(NoticePeriod::fromConfig([])->days)->toBe(7)
        ->and(NoticePeriod::fromConfig(['notice_days' => 14])->days)->toBe(14)
        ->and(NoticePeriod::fromConfig(['notice_days' => -3])->days)->toBe(0)
        ->and((new NoticePeriod(14))->actionableFrom(new DateTimeImmutable('2026-09-10 23:00'))->format('Y-m-d H:i'))->toBe('2026-09-24 00:00');
    expect(fn () => new NoticePeriod(-1))->toThrow(InvalidArgumentException::class);
});
