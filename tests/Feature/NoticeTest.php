<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\Candidate;
use Bpmore\StatamicLifecycle\Expiry\Notice;
use Bpmore\StatamicLifecycle\Expiry\NoticePeriod;
use Bpmore\StatamicLifecycle\Expiry\NoticesSent;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Notification;
use Bpmore\StatamicLifecycle\Notifications\DigestMail;
use Bpmore\StatamicLifecycle\Notifications\Notifier;
use Bpmore\StatamicLifecycle\Notifications\Reminder;
use Bpmore\StatamicLifecycle\Notifications\Reminders;
use Bpmore\StatamicLifecycle\Notifications\ReminderType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 07:00:00');
    Collection::make('news')->title('News')->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
    Entry::make()->id('story')->collection('news')->slug('story')->published(true)->data(['title' => 'A story'])->save();
});

afterEach(fn () => Carbon::setTestNow());

function noticeOf(string $action, string $from = '2026-09-18'): Reminder
{
    return new Reminder('story', ReminderType::Notice, null, new DateTimeImmutable('2026-08-01'), 'alice', 'alice', ActionType::from($action), new DateTimeImmutable($from));
}

it('reaches the owner in their digest, naming the action and the day it may happen from', function () {
    $digest = app(Notifier::class)->digests([noticeOf('unpublish')])[0];

    expect($digest->subject())->toBe('A page you own will change unless you review it');

    $html = (new DigestMail($digest))->render();

    expect($html)->toContain('Unless reviewed, these pages will change (1)')
        ->and($html)->toContain('Lifecycle will unpublish it on or after 18 Sep 2026')
        ->and($html)->toContain('A story')
        ->and($html)->toContain('Reviewing a page — even to say it is still current — stops this')
        ->and($html)->not->toContain('You are the content owner of');
});

it('is recorded with its action, and read back for the period to count from', function () {
    Mail::fake();

    app(Notifier::class)->send(app(Notifier::class)->digests([noticeOf('noindex'), noticeOf('unpublish')]), new DateTimeImmutable('2026-09-11 07:00'));

    $rows = Notification::query()->where('type', 'notice')->orderBy('action')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->action)->toBe(ActionType::Noindex)
        ->and($rows[1]->action)->toBe(ActionType::Unpublish)
        ->and($rows[1]->due_at->format('Y-m-d'))->toBe('2026-08-01');

    $notices = (new NoticesSent)->for(['story', 'other']);

    expect($notices)->toHaveCount(2)
        ->and($notices[0])->toBeInstanceOf(Notice::class)
        ->and($notices[0]->sentAt->format('Y-m-d H:i'))->toBe('2026-09-11 07:00');

    // And the period reads them the way the rule expects.
    $verdict = app(NoticePeriod::class)->check(
        [new Candidate('story', 'default', 'news', ActionType::Unpublish, new DateTimeImmutable('2026-08-01'))],
        $notices,
        new DateTimeImmutable('2026-09-18'),
    );

    expect($verdict->allowed)->toHaveCount(1);
});

it('comes first in a digest that also has ordinary reminders', function () {
    $overdue = new Reminder('story', ReminderType::Overdue, null, new DateTimeImmutable('2026-08-01'), 'alice', 'alice');

    $digest = app(Notifier::class)->digests([$overdue, noticeOf('banner')])[0];

    expect($digest->items[0]->type)->toBe(ReminderType::Notice)
        ->and($digest->subject())->toBe('A page you own will change unless you review it');

    $html = (new DigestMail($digest))->render();

    expect(strpos($html, 'Unless reviewed'))->toBeLessThan(strpos($html, 'Overdue (1)'))
        ->and($html)->toContain('Lifecycle will show a banner on it on or after');
});

it('ignores the notice rows when planning ordinary reminders', function () {
    // A notice is not a stage; it must not stop the overdue reminder.
    Notification::query()->insert([
        'entry_id' => 'story', 'type' => 'notice', 'action' => 'unpublish', 'due_at' => '2026-08-01 00:00:00', 'recipient' => 'alice', 'sent_at' => '2026-09-10 07:00:00',
    ]);
    EntryState::query()->insert([
        'entry_id' => 'story', 'site' => 'default', 'collection' => 'news', 'owner_id' => 'alice', 'owner_type' => 'user', 'owner_source' => 'assigned',
        'interval_days' => 365, 'next_review_at' => '2026-08-01 00:00:00', 'status' => 'expired', 'baselined' => false, 'computed_at' => '2026-09-11 00:00:00',
    ]);

    $owed = app(Reminders::class)->owed(new DateTimeImmutable('2026-09-11'));

    expect($owed)->toHaveCount(1)->and($owed[0]->type)->toBe(ReminderType::Overdue);
});
