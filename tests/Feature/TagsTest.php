<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Antlers;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * The Antlers tags (spec §8): last_reviewed, is_stale, next_review, and a
 * pair with a `stale` boolean the template can hang its own banner on.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-12 10:00:00');
    Blink::flush();
    Collection::make('pages')->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
    User::make()->id('bob')->email('bob@example.edu')->save();

    $row = function (string $id, string $status, array $extra = []) {
        Entry::make()->id($id)->collection('pages')->slug($id)->published(true)->data(['title' => ucfirst($id)])->save();
        EntryState::query()->insert($extra + [
            'entry_id' => $id, 'site' => 'default', 'collection' => 'pages',
            'owner_id' => 'alice', 'owner_type' => 'user', 'owner_source' => 'assigned',
            'interval_days' => 365, 'next_review_at' => '2027-03-04 00:00:00', 'status' => $status,
            'last_reviewed_at' => '2026-03-04 09:30:00', 'last_reviewed_by' => 'bob',
            'baselined' => false, 'computed_at' => '2026-09-12 00:00:00',
        ]);
    };
    $row('reviewed', 'fresh');
    $row('late', 'overdue', ['next_review_at' => '2026-08-01 00:00:00']);
    $row('gone', 'expired', ['next_review_at' => '2026-06-01 00:00:00']);
    $row('never', 'fresh', ['last_reviewed_at' => null, 'last_reviewed_by' => null, 'owner_id' => null, 'owner_type' => null, 'owner_source' => null]);
    Entry::make()->id('untracked')->collection('pages')->slug('untracked')->published(true)->data(['title' => 'Untracked'])->save();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Parse as a template would be, not as user content: Antlers::parse() is
 * untrusted by default and lets no tag run at all — the way an Antlers
 * field in a bard is parsed — which a test reads as every tag being empty.
 */
function antlers(string $template, ?string $entryId): string
{
    return (string) Antlers::parse($template, $entryId === null ? [] : ['id' => $entryId], trusted: true);
}

describe('last_reviewed', function () {
    it('prints the date a person last confirmed the page, in the site\'s format', function () {
        expect(antlers('{{ lifecycle:last_reviewed }}', 'reviewed'))->toBe('March 4th, 2026');
    });

    it('takes a format, so a template can say "Last reviewed: March 2026"', function () {
        expect(antlers('Last reviewed: {{ lifecycle:last_reviewed format="F Y" }}', 'reviewed'))->toBe('Last reviewed: March 2026');
    });

    it('is empty for a page nobody has reviewed, so {{ if }} works', function () {
        expect(antlers('[{{ lifecycle:last_reviewed }}]', 'never'))->toBe('[]')
            ->and(antlers('{{ if {lifecycle:last_reviewed} }}yes{{ else }}no{{ /if }}', 'never'))->toBe('no')
            ->and(antlers('{{ if {lifecycle:last_reviewed} }}yes{{ else }}no{{ /if }}', 'reviewed'))->toBe('yes');
    });
});

describe('next_review', function () {
    it('prints the next review date', function () {
        expect(antlers('{{ lifecycle:next_review format="Y-m-d" }}', 'reviewed'))->toBe('2027-03-04')
            ->and(antlers('{{ lifecycle:next_review format="Y-m-d" }}', 'late'))->toBe('2026-08-01');
    });
});

describe('is_stale', function () {
    it('is true when the page is overdue or expired, and false otherwise', function () {
        $template = '{{ if {lifecycle:is_stale} }}stale{{ else }}fine{{ /if }}';

        expect(antlers($template, 'reviewed'))->toBe('fine')
            ->and(antlers($template, 'late'))->toBe('stale')
            ->and(antlers($template, 'gone'))->toBe('stale')
            ->and(antlers($template, 'never'))->toBe('fine');
    });

    it('is false for a page Lifecycle does not track', function () {
        expect(antlers('{{ if {lifecycle:is_stale} }}stale{{ else }}fine{{ /if }}', 'untracked'))->toBe('fine')
            ->and(antlers('[{{ lifecycle:status }}]', 'untracked'))->toBe('[]');
    });
});

describe('the pair', function () {
    it('gives the template everything at once, with a stale boolean for its own banner', function () {
        $template = '{{ lifecycle }}{{ if stale }}<p class="notice">This page may be out of date.</p>{{ /if }}'
            .'{{ status }}|{{ status_label }}|{{ last_reviewed format="F Y" }}|{{ last_reviewed_by }}|{{ next_review format="Y-m-d" }}|{{ owner }}{{ /lifecycle }}';

        expect(antlers($template, 'late'))->toBe('<p class="notice">This page may be out of date.</p>overdue|Overdue|March 2026|bob@example.edu|2026-08-01|Alice Ahmed')
            ->and(antlers($template, 'reviewed'))->toBe('fresh|Fresh|March 2026|bob@example.edu|2027-03-04|Alice Ahmed');
    });

    it('still renders, empty, for a page that is not tracked', function () {
        expect(antlers('{{ lifecycle }}[{{ tracked }}][{{ stale }}][{{ last_reviewed }}]{{ /lifecycle }}', 'untracked'))->toBe('[][][]');
    });
});

describe('which page', function () {
    it('reads the page being rendered, or the entry the tag names', function () {
        expect(antlers('{{ lifecycle:status }}', 'late'))->toBe('overdue')
            ->and(antlers('{{ lifecycle:status entry="reviewed" }}', 'late'))->toBe('fresh')
            ->and(antlers('[{{ lifecycle:status }}]', null))->toBe('[]');
    });

    it('reads the row once however many tags a page uses', function () {
        DB::connection()->enableQueryLog();

        antlers('{{ lifecycle:last_reviewed }}{{ lifecycle:next_review }}{{ lifecycle:is_stale }}{{ lifecycle:status }}', 'reviewed');

        // One row read; the other mention is the schema check that the table is there.
        $reads = collect(DB::connection()->getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'select * from "lifecycle_states"'));
        expect($reads)->toHaveCount(1);
    });

    it('never breaks the front end: not installed reads as nothing, not as an error', function () {
        Schema::connection(LifecycleDatabase::connectionName())->rename('lifecycle_states', 'lifecycle_states_absent');
        expect(app(LifecycleDatabase::class)->isInstalled())->toBeFalse();

        expect(antlers('[{{ lifecycle:last_reviewed }}]{{ if {lifecycle:is_stale} }}stale{{ else }}fine{{ /if }}', 'reviewed'))->toBe('[]fine');
    });
});
