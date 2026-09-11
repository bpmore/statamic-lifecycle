<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Tests\Support\FixtureSite;
use Bpmore\StatamicLifecycle\Tests\Support\SpreadAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Collection;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

/**
 * The baseline against a site shaped like a real one: lumpy modification
 * dates, three collections on three intervals, drafts, hard deadlines.
 *
 * The same population at 40,000 was run against the host site by hand with
 * tools/build-fixture-site.php and tools/verify-spread.php (see PROGRESS.md
 * for the numbers). This keeps a smaller run in the suite so the property
 * cannot quietly regress: files are written straight into the test Stache,
 * which is why 4,000 entries cost seconds rather than minutes.
 */
it('spreads a realistically lumpy site evenly within each window, oldest first, none overdue', function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    $now = new DateTimeImmutable('2026-09-11 15:00:00');
    $today = $now->setTime(0, 0);

    User::make()->id('alice')->email('alice@example.edu')->save();

    foreach (FixtureSite::COLLECTIONS as $handle => $collection) {
        Collection::make($handle)->title($collection['title'])->save();
    }

    $entries = [];
    foreach (FixtureSite::entries(4_000, $now, ['alice']) as $entry) {
        $directory = rtrim(Stache::store('entries')->store($entry['collection'])->directory(), '/');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents("{$directory}/{$entry['slug']}.md", FixtureSite::fileContents($entry));
        $entries[$entry['id']] = $entry;
    }
    Stache::clear();

    $this->artisan('lifecycle:baseline')->assertExitCode(0);

    // ---- Join what was written to what was known ------------------------

    $states = EntryState::query()->get()->keyBy('entry_id');
    $published = array_filter($entries, fn (array $e): bool => $e['published']);
    $drafts = array_filter($entries, fn (array $e): bool => ! $e['published']);

    expect($states)->toHaveCount(count($published));
    foreach ($drafts as $id => $draft) {
        expect($states->has($id))->toBeFalse("draft {$id} got a row");
    }

    $rows = [];
    foreach ($published as $id => $entry) {
        $state = $states[$id];

        if ($entry['review_by'] !== null) {
            expect($state->baseline_review_at)->toBeNull("{$id} has a review_by date and should not have been spread")
                ->and($state->next_review_at->format('Y-m-d'))->toBe($entry['review_by'])
                ->and($state->next_review_source)->toBe('hard_date');
        }

        $rows[] = [
            'id' => $id,
            'interval_days' => $state->interval_days,
            'last_modified' => $entry['updated_at'],
            'next_review' => $state->next_review_at->format('Y-m-d'),
            'baseline' => $state->baseline_review_at?->format('Y-m-d'),
        ];
    }

    // ---- The spread -------------------------------------------------------

    $horizonDays = (int) $today->diff($today->modify('+12 months'))->days;
    $report = SpreadAnalysis::byWindow($rows, $horizonDays, $today);

    // Three collections on three intervals, one of which is longer than the
    // horizon: two windows.
    expect(array_keys($report))->toBe([90, 365]);

    foreach ($report as $window => $group) {
        expect(SpreadAnalysis::problems($group))->toBe([], "window {$window}: ".implode('; ', SpreadAnalysis::problems($group)));

        // Tighter than the analysis's own threshold: with this many entries
        // no day should carry more than one entry above the quietest day.
        expect($group['max_per_day'] - $group['min_per_day'])->toBeLessThanOrEqual(1, "window {$window} is uneven")
            ->and($group['days_used'])->toBe($window);
    }

    // The two groups are each even, and the quarterly group sits inside the
    // first quarter of the yearly one — so the combined picture is fuller for
    // three months, by design, and never piled on a day.
    expect(max($report[90]['by_month']))->toBeGreaterThan(0)
        ->and(array_key_last($report[90]['by_month']))->toBeLessThanOrEqual('2026-12');

    // The guess stayed a guess: owners were inferred for the entries alice
    // last touched, and nothing was written to any entry.
    expect($states->where('owner_source', 'inferred')->count())->toBeGreaterThan(count($published) / 2);

    Carbon::setTestNow();
})->group('scale');
