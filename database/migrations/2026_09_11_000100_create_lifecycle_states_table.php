<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per entry: where it is in its review cycle right now.
 *
 * Computed, not authored. The owner and interval an editor chose live in the
 * entry's blueprint fields (spec §3); this table holds what the computation
 * service made of them, and is rewritten by every recompute. Nothing here is
 * the record of anything — that is lifecycle_reviews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_states', function (Blueprint $table) {
            // Statamic's entry ID: a UUID by default, but a site can use its
            // own strings, and the Eloquent driver its own keys. One row per
            // entry, and a localisation is its own entry, so this is already
            // per site (spec §11: a translation can be stale while the
            // original is current).
            $table->string('entry_id', 191)->primary();
            $table->string('site', 64);
            $table->string('collection', 128);

            // All three null when nobody owns it and no owner-of-last-resort
            // is configured — the "no owner" filter is owner_id IS NULL.
            $table->string('owner_id', 191)->nullable();
            // user | group. Groups are what institutions actually need,
            // because people leave; the schema supports both from day one
            // even where the UI only offers users.
            $table->string('owner_type', 8)->nullable();
            // assigned | inferred | fallback. The dashboard has to be able to
            // tell "a human said so" from "we guessed from the last author"
            // from "nobody, so the site's default".
            $table->string('owner_source', 16)->nullable();

            // The effective interval after resolving entry → blueprint →
            // collection → global. Null only when exempt, where there is none.
            $table->unsignedInteger('interval_days')->nullable();

            // Denormalised from the newest lifecycle_reviews row so the
            // dashboard does not join 40,000 rows to a history table.
            $table->dateTime('last_reviewed_at')->nullable();
            $table->string('last_reviewed_by', 191)->nullable();

            // Every dashboard query and every notification run hangs off
            // this. Null when exempt.
            $table->dateTime('next_review_at')->nullable()->index();

            // fresh | due_soon | overdue | expired | exempt
            $table->string('status', 16)->index();

            // Set by lifecycle:baseline. "Never reviewed since baselining" is
            // baselined AND last_reviewed_at IS NULL.
            $table->boolean('baselined')->default(false);

            $table->dateTime('computed_at');

            // Dashboards by collection, by site, by owner (spec §8).
            $table->index('collection');
            $table->index('site');
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_states');
    }
};
