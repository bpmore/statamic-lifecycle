<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every automated change to the live site, with enough to undo it.
 *
 * Expiry actions are off by default and opt-in per collection (spec §6).
 * When one runs, this is where it is written down — and previous_state is
 * what one-click revert reads. An action that is not in here did not happen
 * through this addon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_actions', function (Blueprint $table) {
            $table->id();

            $table->string('entry_id', 191);

            // banner | noindex | unpublish | restore
            $table->string('action', 16);

            $table->dateTime('applied_at');

            // A user ID, or the name of the schedule or command that did it.
            // Never null: an unattributed change to somebody's live site is
            // the thing this table exists to rule out.
            $table->string('triggered_by', 191);

            // What the entry looked like before, as the revert needs it.
            $table->json('previous_state')->nullable();

            $table->dateTime('reverted_at')->nullable();
            // Not in the spec's sketch. The sibling addon shipped its
            // exemption log without a `revoked_by` and had to add one: a
            // withdrawal is an unattributed change to a record whose whole
            // purpose is attribution. Same here.
            $table->string('reverted_by', 191)->nullable();

            // The per-entry action history.
            $table->index(['entry_id', 'applied_at']);
            // The daily cap (spec §6): how many actions have run today.
            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_actions');
    }
};
