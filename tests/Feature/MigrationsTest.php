<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The column lists of every index on a table, primary key included, so a test
 * can say "these columns are indexed" without caring what the index is called.
 *
 * @return list<list<string>>
 */
function indexedColumns(string $table): array
{
    return array_values(array_map(
        fn (array $index): array => $index['columns'],
        Schema::getIndexes($table),
    ));
}

it('creates the four tables', function () {
    expect(Schema::hasTable('lifecycle_states'))->toBeTrue()
        ->and(Schema::hasTable('lifecycle_reviews'))->toBeTrue()
        ->and(Schema::hasTable('lifecycle_notifications'))->toBeTrue()
        ->and(Schema::hasTable('lifecycle_actions'))->toBeTrue();
});

it('gives lifecycle_states the columns spec §3 names, keyed by entry', function () {
    expect(Schema::getColumnListing('lifecycle_states'))->toEqualCanonicalizing([
        'entry_id', 'site', 'collection',
        'owner_id', 'owner_type', 'owner_source',
        'interval_days', 'interval_source',
        'last_reviewed_at', 'last_reviewed_by',
        'next_review_at', 'next_review_source', 'status',
        'baselined', 'baseline_review_at', 'computed_at',
    ]);

    $primary = collect(Schema::getIndexes('lifecycle_states'))->firstWhere('primary', true);

    expect($primary)->not->toBeNull('lifecycle_states has no primary key')
        ->and($primary['columns'])->toBe(['entry_id']);
});

it('indexes next_review_at, which every dashboard query hangs off', function () {
    // The one index the spec mandates by name (§10). A 40,000-row scan for
    // "due in the next 30 days" on every notification run is not acceptable.
    expect(indexedColumns('lifecycle_states'))->toContain(['next_review_at']);
});

it('indexes lifecycle_states for the dashboards by status, collection, site and owner', function () {
    $indexes = indexedColumns('lifecycle_states');

    expect($indexes)->toContain(['status'])
        ->and($indexes)->toContain(['collection'])
        ->and($indexes)->toContain(['site'])
        ->and($indexes)->toContain(['owner_type', 'owner_id']);
});

it('lets an entry have no owner, no interval and no next review', function () {
    // Nobody owns it, no owner-of-last-resort is configured, and it is exempt:
    // every one of those is a legitimate state, and the row must be storable.
    $columns = collect(Schema::getColumns('lifecycle_states'))->keyBy('name');

    foreach (['owner_id', 'owner_type', 'owner_source', 'interval_days', 'next_review_at', 'last_reviewed_at', 'last_reviewed_by'] as $nullable) {
        expect($columns[$nullable]['nullable'])->toBeTrue("$nullable should be nullable");
    }

    foreach (['entry_id', 'site', 'collection', 'status', 'baselined', 'computed_at'] as $required) {
        expect($columns[$required]['nullable'])->toBeFalse("$required should be required");
    }
});

it('gives lifecycle_reviews an append-only shape', function () {
    expect(Schema::getColumnListing('lifecycle_reviews'))->toEqualCanonicalizing([
        'id', 'entry_id', 'reviewed_by', 'reviewed_at',
        'outcome', 'note', 'content_changed', 'entry_revision_ref',
        'created_at',
    ]);

    // No updated_at and no deleted_at: rows are written once. And no foreign
    // key to lifecycle_states, which is rewritten and removed under it.
    expect(Schema::hasColumn('lifecycle_reviews', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('lifecycle_reviews', 'deleted_at'))->toBeFalse()
        ->and(Schema::getForeignKeys('lifecycle_reviews'))->toBe([]);

    $columns = collect(Schema::getColumns('lifecycle_reviews'))->keyBy('name');

    // An attestation without a person is not one.
    expect($columns['reviewed_by']['nullable'])->toBeFalse()
        ->and($columns['reviewed_at']['nullable'])->toBeFalse()
        ->and($columns['outcome']['nullable'])->toBeFalse();

    $indexes = indexedColumns('lifecycle_reviews');

    // The per-entry timeline, and activity over time for the report.
    expect($indexes)->toContain(['entry_id', 'reviewed_at'])
        ->and($indexes)->toContain(['reviewed_at'])
        ->and($indexes)->toContain(['reviewed_by']);
});

it('gives lifecycle_notifications what the digest needs to not repeat itself', function () {
    expect(Schema::getColumnListing('lifecycle_notifications'))->toEqualCanonicalizing([
        'id', 'entry_id', 'type', 'recipient', 'sent_at',
    ]);

    $indexes = indexedColumns('lifecycle_notifications');

    // "Was this entry already notified at this stage?" and "what has this
    // person been sent, and when?"
    expect($indexes)->toContain(['entry_id', 'type'])
        ->and($indexes)->toContain(['recipient', 'sent_at']);
});

it('gives lifecycle_actions enough to revert, and to count against the daily cap', function () {
    expect(Schema::getColumnListing('lifecycle_actions'))->toEqualCanonicalizing([
        'id', 'entry_id', 'action', 'applied_at', 'triggered_by',
        'previous_state', 'reverted_at', 'reverted_by',
    ]);

    $columns = collect(Schema::getColumns('lifecycle_actions'))->keyBy('name');

    // An unattributed change to a live site is the thing this table rules out.
    expect($columns['triggered_by']['nullable'])->toBeFalse()
        ->and($columns['applied_at']['nullable'])->toBeFalse()
        ->and($columns['previous_state']['nullable'])->toBeTrue()
        ->and($columns['reverted_at']['nullable'])->toBeTrue();

    $indexes = indexedColumns('lifecycle_actions');

    expect($indexes)->toContain(['entry_id', 'applied_at'])
        ->and($indexes)->toContain(['applied_at']);
});

it('can be rolled back', function () {
    // Every migration has a down() that actually undoes it. Checked because a
    // rollback that leaves a table behind is found by the next `migrate` on a
    // site, with "table already exists", a long way from here.
    $this->artisan('migrate:rollback', [
        '--path' => realpath(__DIR__.'/../../database/migrations'),
        '--realpath' => true,
    ])->assertExitCode(0);

    expect(Schema::hasTable('lifecycle_states'))->toBeFalse()
        ->and(Schema::hasTable('lifecycle_reviews'))->toBeFalse()
        ->and(Schema::hasTable('lifecycle_notifications'))->toBeFalse()
        ->and(Schema::hasTable('lifecycle_actions'))->toBeFalse();
});
