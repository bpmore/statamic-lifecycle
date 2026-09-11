<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every reminder that went out, one row per entry per recipient.
 *
 * Reminders are digests — one email per recipient listing every page, never
 * one email per page (spec §5) — so a single send produces many rows here.
 * That is what lets the next run answer "has this entry already been
 * notified at this stage?" and skip it, and what the frequency opt-out is
 * measured against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_notifications', function (Blueprint $table) {
            $table->id();

            $table->string('entry_id', 191);

            // due_soon | due | overdue | escalation
            $table->string('type', 16);

            // A user ID where there is one; an address where the fallback is
            // only an address.
            $table->string('recipient', 191);

            $table->dateTime('sent_at');

            // "Was this entry notified at this stage?"
            $table->index(['entry_id', 'type']);
            // "What has this person been sent, and when?"
            $table->index(['recipient', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_notifications');
    }
};
