<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Notification;
use Bpmore\StatamicLifecycle\Notifications\DigestMail;
use Bpmore\StatamicLifecycle\Notifications\Notifier;
use Bpmore\StatamicLifecycle\Notifications\Reminder;
use Bpmore\StatamicLifecycle\Notifications\Reminders;
use Bpmore\StatamicLifecycle\Notifications\ReminderType;
use Bpmore\StatamicLifecycle\Owners\EscalationContacts;
use Bpmore\StatamicLifecycle\Owners\FallbackOwners;
use Bpmore\StatamicLifecycle\Settings\SettingsBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Addon;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 07:00:00');
    config(['statamic.editions.pro' => true]);
    File::delete(resource_path('addons/lifecycle.yaml'));

    Collection::make('news')->title('News')->save();
    Collection::make('pages')->title('Pages')->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
    User::make()->id('head')->email('head@example.edu')->data(['name' => 'Head of Comms'])->save();
    User::make()->id('webmaster')->email('web@example.edu')->data(['name' => 'The Web Office'])->save();
});

afterEach(function () {
    File::delete(resource_path('addons/lifecycle.yaml'));
    Carbon::setTestNow();
});

function overduePage(string $id, string $owner, string $due, string $collection = 'news'): void
{
    Entry::make()->id($id)->collection($collection)->slug($id)->published(true)->data(['title' => 'Page '.$id])->save();
    EntryState::query()->insert([
        'entry_id' => $id, 'site' => 'default', 'collection' => $collection,
        'owner_id' => $owner, 'owner_type' => 'user', 'owner_source' => 'assigned',
        'interval_days' => 365, 'next_review_at' => $due, 'status' => 'overdue',
        'baselined' => false, 'computed_at' => '2026-09-11 00:00:00',
    ]);
}

function owedLines(): array
{
    return array_map(
        fn (Reminder $r): string => $r->entryId.' '.$r->type->value.' → '.$r->recipient,
        app(Reminders::class)->owed(new DateTimeImmutable('2026-09-11')),
    );
}

describe('the contacts', function () {
    it('are named per collection in config, or in the settings screen, which wins', function () {
        config(['lifecycle.escalation.contacts' => ['news' => 'head', 'pages' => 'alice', 'nope' => 'ignored']]);
        expect(app(EscalationContacts::class)->configured())->toBe(['news' => 'head', 'pages' => 'alice']);

        Addon::get(FallbackOwners::PACKAGE)->settings()->set(SettingsBlueprint::handleForCollection('pages'), 'webmaster')->save();
        expect(app(EscalationContacts::class)->configured())->toBe(['news' => 'head', 'pages' => 'webmaster']);
    });

    it('do not include a person who has left, and say who', function () {
        config(['lifecycle.escalation.contacts' => ['news' => 'someone-who-left', 'pages' => 'head']]);

        expect(app(EscalationContacts::class)->resolved())->toBe(['pages' => 'head'])
            ->and(app(EscalationContacts::class)->missing())->toBe(['news' => 'someone-who-left']);
    });

    it('have a field per collection on the settings screen', function () {
        $sections = SettingsBlueprint::contents()['tabs']['owners']['sections'];

        expect($sections[1]['display'])->toBe('Escalation contacts')
            ->and(collect($sections[1]['fields'])->pluck('handle')->all())->toBe(['escalation_contact_news', 'escalation_contact_pages'])
            ->and($sections[1]['fields'][0]['field']['type'])->toBe('users')
            ->and($sections[1]['fields'][0]['field']['display'])->toBe('News');
    });
});

describe('who is told', function () {
    it('brings in the contact, then the owner of last resort, as the page stays overdue', function () {
        config(['lifecycle.escalation.contacts' => ['news' => 'head'], 'lifecycle.owners.fallback' => ['default' => 'webmaster']]);

        overduePage('fresh-overdue', 'alice', '2026-09-05');   // 6 days: owner only
        overduePage('escalated', 'alice', '2026-08-20');       // 22 days: contact too
        overduePage('last-resort', 'alice', '2026-08-01');     // 41 days: everyone

        expect(owedLines())->toBe([
            'last-resort overdue → alice',
            'last-resort escalation → head',
            'last-resort escalation → webmaster',
            'escalated overdue → alice',
            'escalated escalation → head',
            'fresh-overdue overdue → alice',
        ]);
    });

    it('goes straight to the owner of last resort for a collection with no contact', function () {
        config(['lifecycle.owners.fallback' => ['default' => 'webmaster']]);
        overduePage('old', 'alice', '2026-08-01', 'pages');

        expect(owedLines())->toBe(['old overdue → alice', 'old escalation → webmaster']);
    });

    it('does not escalate a page to the person who already owns it', function () {
        config(['lifecycle.escalation.contacts' => ['news' => 'head'], 'lifecycle.owners.fallback' => ['default' => 'webmaster']]);
        overduePage('heads-own', 'head', '2026-08-01');
        overduePage('web-own', 'webmaster', '2026-08-01');

        expect(owedLines())->toBe([
            'heads-own overdue → head',
            'heads-own escalation → webmaster',
            'web-own overdue → webmaster',
            'web-own escalation → head',
        ]);
    });
});

describe('the email', function () {
    it('tells the contact whose pages they are, and records the escalation to them', function () {
        Mail::fake();
        config(['lifecycle.escalation.contacts' => ['news' => 'head']]);
        overduePage('escalated', 'alice', '2026-08-20');

        $this->artisan('lifecycle:notify')
            ->expectsOutputToContain('1 escalated')
            ->expectsOutputToContain('Sent 2 digests covering 2 reminders.')
            ->assertExitCode(0);

        Mail::assertSentCount(2);
        Mail::assertSent(DigestMail::class, fn (DigestMail $m): bool => $m->hasTo('head@example.edu')
            && $m->digest->subject() === 'An overdue page has been escalated to you');

        // Already sent today, so nothing is owed; build the head's digest by hand to read it.
        $reminder = new Reminder('escalated', ReminderType::Escalation, null, new DateTimeImmutable('2026-08-20'), 'head', 'alice');
        $html = (new DigestMail(app(Notifier::class)->digests([$reminder])[0]))->render();

        expect($html)->toContain('Escalated to you (1)')
            ->and($html)->toContain('owned by Alice Ahmed')
            ->and($html)->toContain('was due 20 Aug 2026')
            ->and($html)->not->toContain('You are the content owner');

        $row = Notification::query()->where('recipient', 'head')->first();
        expect($row->type->value)->toBe('escalation')
            ->and($row->entry_id)->toBe('escalated')
            ->and($row->due_at->format('Y-m-d'))->toBe('2026-08-20');
    });

    it('mixes escalations into an owner\'s own digest when they have both', function () {
        config(['lifecycle.escalation.contacts' => ['news' => 'head']]);
        overduePage('escalated', 'alice', '2026-08-20');
        overduePage('heads-own', 'head', '2026-09-08', 'pages');

        $digests = collect(app(Notifier::class)->digests(app(Reminders::class)->owed(new DateTimeImmutable('2026-09-11'))));
        $heads = $digests->first(fn ($d): bool => $d->recipientId === 'head');

        expect($heads->subject())->toBe('2 pages need your attention')
            ->and($heads->count())->toBe(2);

        $html = (new DigestMail($heads))->render();

        expect($html)->toContain('You are the content owner of a page')
            ->and($html)->toContain('Escalated to you (1)')
            ->and($html)->toContain('Overdue (1)');
    });
});
