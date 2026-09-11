<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a person said "I looked at this page". Append-only.
 *
 * This is the compliance artifact, and the reason the product costs money
 * (spec §4): the per-page answer to "when did a human last confirm this was
 * accurate, and who were they?" Rows are never updated or deleted by the
 * addon. Marking a page reviewed writes here and to lifecycle_states, and
 * never to the entry — an attestation is not a content change and must not
 * create a revision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_reviews', function (Blueprint $table) {
            $table->id();

            // Not a foreign key to lifecycle_states: that row is rewritten by
            // every recompute and removed when the entry is deleted, and the
            // review record has to outlive both.
            $table->string('entry_id', 191);

            // Who. Never null: an attestation without a person is not one.
            $table->string('reviewed_by', 191);
            $table->dateTime('reviewed_at');

            // current | updated | rewrite_scheduled | retired
            $table->string('outcome', 24);
            $table->text('note')->nullable();

            // Whether the reviewer also changed the content. Editing does not
            // count as reviewing (spec §11) — this records that both happened,
            // which is a different thing.
            $table->boolean('content_changed')->default(false);

            // What the entry looked like when it was reviewed, so the record
            // can be read against the right version later.
            $table->string('entry_revision_ref', 191)->nullable();

            // When the row was written. Usually the same as reviewed_at, and
            // different when a review is recorded after the fact.
            $table->dateTime('created_at')->useCurrent();

            // The per-entry timeline, newest first.
            $table->index(['entry_id', 'reviewed_at']);
            // Review activity over time, and by reviewer, for the report.
            $table->index('reviewed_at');
            $table->index('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_reviews');
    }
};
