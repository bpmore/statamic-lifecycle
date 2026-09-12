<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Actions\PutBack;
use Bpmore\StatamicLifecycle\Expiry\ActionLog;
use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Models\AutomatedAction;
use Bpmore\StatamicLifecycle\Panel\SidebarPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Action;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    config(['statamic.editions.pro' => true]);
    Collection::make('pages')->save();
    $this->admin = User::make()->id('admin')->email('admin@example.edu')->makeSuper()->save();
    Entry::make()->id('page')->collection('pages')->slug('page')->published(true)->data(['title' => 'Page'])->save();
});

afterEach(fn () => Carbon::setTestNow());

describe('the log', function () {
    it('writes down what was done, by whom, and what the page looked like before', function () {
        $row = app(ActionLog::class)->record(Entry::find('page'), ActionType::Unpublish, ['published' => true], 'lifecycle:expire');

        expect($row->entry_id)->toBe('page')
            ->and($row->action)->toBe(ActionType::Unpublish)
            ->and($row->applied_at->format('Y-m-d H:i'))->toBe('2026-09-11 15:00')
            ->and($row->triggered_by)->toBe('lifecycle:expire')
            ->and($row->previous_state)->toBe(['published' => true])
            ->and($row->reverted_at)->toBeNull()
            ->and(AutomatedAction::query()->count())->toBe(1);
    });

    it('knows the latest change to a page that has not been put back', function () {
        $log = app(ActionLog::class);
        $entry = Entry::find('page');

        expect($log->outstanding('page'))->toBeNull();

        $log->record($entry, ActionType::Banner, ['lifecycle_banner' => null], 'lifecycle:expire', new DateTimeImmutable('2026-09-01'));
        $later = $log->record($entry, ActionType::Noindex, ['lifecycle_noindex' => null], 'lifecycle:expire', new DateTimeImmutable('2026-09-05'));

        expect($log->outstanding('page')->id)->toBe($later->id)
            ->and(array_map(fn ($a) => $a->action->value, $log->history('page')))->toBe(['noindex', 'banner']);
    });
});

describe('putting back', function () {
    it('republishes a page that was unpublished, through Statamic\'s own path, and records both halves', function () {
        $entry = Entry::find('page');
        $entry->unpublish();
        $action = app(ActionLog::class)->record(Entry::find('page'), ActionType::Unpublish, ['published' => true], 'lifecycle:expire', new DateTimeImmutable('2026-09-10'));

        expect(Entry::find('page')->published())->toBeFalse();

        $restore = app(ActionLog::class)->revert($action, 'admin');

        expect(Entry::find('page')->published())->toBeTrue()
            ->and($restore->action)->toBe(ActionType::Restore)
            ->and($restore->previous_state)->toBe(['published' => false])
            ->and($restore->triggered_by)->toBe('admin')
            ->and($restore->applied_at->format('Y-m-d H:i'))->toBe('2026-09-11 15:00');

        $action->refresh();

        expect($action->reverted_at->format('Y-m-d H:i'))->toBe('2026-09-11 15:00')
            ->and($action->reverted_by)->toBe('admin')
            ->and(app(ActionLog::class)->outstanding('page'))->toBeNull();
    });

    it('puts data keys back to what they were, removing what was not there', function () {
        // A banner that set a flag on an entry that had none; a noindex that
        // changed a value that was already there.
        $entry = Entry::find('page');
        $entry->set('lifecycle_banner', true)->set('robots', 'noindex')->save();
        $action = app(ActionLog::class)->record($entry, ActionType::Banner, ['lifecycle_banner' => null, 'robots' => 'index'], 'lifecycle:expire');

        $restore = app(ActionLog::class)->revert($action, 'admin');

        $fresh = Entry::find('page');

        expect($fresh->get('lifecycle_banner'))->toBeNull()
            ->and($fresh->data()->has('lifecycle_banner'))->toBeFalse()
            ->and($fresh->get('robots'))->toBe('index')
            ->and($restore->previous_state)->toBe(['lifecycle_banner' => true, 'robots' => 'noindex']);
    });

    it('refuses to put back twice, to put back a restore, or to put back a page that is gone', function () {
        $log = app(ActionLog::class);
        $action = $log->record(Entry::find('page'), ActionType::Unpublish, ['published' => true], 'lifecycle:expire');
        $restore = $log->revert($action, 'admin');

        expect(fn () => $log->revert($action->refresh(), 'admin'))->toThrow(InvalidArgumentException::class, 'already put back');
        expect(fn () => $log->revert($restore, 'admin'))->toThrow(InvalidArgumentException::class, 'not something to put back');

        $orphan = $log->record(Entry::find('page'), ActionType::Unpublish, ['published' => true], 'lifecycle:expire');
        Entry::find('page')->deleteQuietly();
        expect(fn () => $log->revert($orphan, 'admin'))->toThrow(InvalidArgumentException::class, 'no longer exists');
    });

    it('does not make the person putting it back the last author', function () {
        Entry::find('page')->set('updated_by', 'editor')->save();
        $action = app(ActionLog::class)->record(Entry::find('page'), ActionType::Banner, ['lifecycle_banner' => null], 'lifecycle:expire');
        Entry::find('page')->set('lifecycle_banner', true)->save();

        app(ActionLog::class)->revert($action, 'admin');

        expect(Entry::find('page')->get('updated_by'))->toBe('editor');
    });
});

describe('one click', function () {
    it('is offered only on a page with something of Lifecycle\'s to put back', function () {
        $action = Action::get('put_back');

        expect($action)->toBeInstanceOf(PutBack::class)
            ->and($action->visibleTo(Entry::find('page')))->toBeFalse();

        app(ActionLog::class)->record(Entry::find('page'), ActionType::Unpublish, ['published' => true], 'lifecycle:expire');

        expect($action->visibleTo(Entry::find('page')))->toBeTrue()
            ->and($action->visibleToBulk(collect([Entry::find('page')])))->toBeTrue();
    });

    it('puts the page back from the control panel, as the person who clicked', function () {
        Entry::find('page')->unpublish();
        app(ActionLog::class)->record(Entry::find('page'), ActionType::Unpublish, ['published' => true], 'lifecycle:expire');

        $this->actingAs($this->admin)
            ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
                'action' => 'put_back',
                'selections' => ['page'],
                'values' => [],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Put it back: Lifecycle had chosen to unpublish it.');

        expect(Entry::find('page')->published())->toBeTrue()
            ->and(AutomatedAction::query()->where('action', 'restore')->first()->triggered_by)->toBe('admin')
            ->and(AutomatedAction::query()->where('action', 'unpublish')->first()->reverted_by)->toBe('admin');
    });

    it('says so when there is nothing to put back', function () {
        $this->actingAs($this->admin)
            ->postJson(cp_route('collections.entries.actions.run', ['collection' => 'pages']), [
                'action' => 'put_back',
                'selections' => ['page'],
                'values' => [],
            ])
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'There is nothing of Lifecycle\'s to put back on that page.');
    });

    it('shows in the sidebar panel what Lifecycle last did, so the click is where the editor looks', function () {
        $this->actingAs($this->admin);

        expect(app(SidebarPanel::class)->for(Entry::find('page'))['last_action'])->toBeNull();

        app(ActionLog::class)->record(Entry::find('page'), ActionType::Noindex, ['lifecycle_noindex' => null], 'lifecycle:expire', new DateTimeImmutable('2026-09-10 02:00'));

        $panel = app(SidebarPanel::class)->for(Entry::find('page'));

        expect($panel['last_action'])->toMatchArray(['action' => 'noindex', 'label' => 'hide it from search engines', 'triggered_by' => 'lifecycle:expire'])
            ->and($panel['last_action']['applied_at'])->toStartWith('2026-09-10T02:00')
            ->and($panel['can_put_back'])->toBeTrue();
    });
});
