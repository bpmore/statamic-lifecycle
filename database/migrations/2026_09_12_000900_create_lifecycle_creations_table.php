<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this addon saw each entry created.
 *
 * Statamic does not record an entry's creation, and the gate's promise —
 * never to apply to entries created before it was enabled — needs one. So
 * the addon writes the moment down itself, from the EntryCreated event, and
 * an entry it never saw created is treated as older than any gate. Drafts
 * included: lifecycle_states only holds published entries, and a draft
 * started after the gate went on is exactly what the gate is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_creations', function (Blueprint $table) {
            $table->string('entry_id')->primary();
            $table->string('site');
            $table->string('collection');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_creations');
    }
};
