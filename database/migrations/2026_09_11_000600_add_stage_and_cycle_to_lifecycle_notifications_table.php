<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which stage a reminder was, and which review cycle it was about.
 *
 * "Was this entry notified at this stage?" needs both. A schedule of
 * 30 / 7 / 0 days is three due_soon-ish stages, not one, so the type alone
 * cannot tell them apart; and once the page is reviewed its date moves on,
 * and reminders about the old date must not silence the new ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lifecycle_notifications', function (Blueprint $table) {
            // How many days before the review date this stage fires; null
            // for the stages that come after it.
            $table->unsignedSmallInteger('days_before')->nullable()->after('type');
            // The review date the reminder was about: the cycle.
            $table->dateTime('due_at')->nullable()->after('days_before');

            $table->index(['entry_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('lifecycle_notifications', function (Blueprint $table) {
            $table->dropIndex(['entry_id', 'due_at']);
            $table->dropColumn(['days_before', 'due_at']);
        });
    }
};
