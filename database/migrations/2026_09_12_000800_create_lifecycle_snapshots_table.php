<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per day: how much content was in what state, and how many
 * reviews were recorded. Written by the nightly recompute.
 *
 * lifecycle_states is rewritten every night and says nothing about
 * yesterday; the dashboard widget's 30-day trend and the governance
 * report's "review activity over time" need a memory. Small: a year is
 * 365 rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_snapshots', function (Blueprint $table) {
            $table->date('day')->primary();

            $table->unsignedInteger('total');
            $table->unsignedInteger('fresh');
            $table->unsignedInteger('due_soon');
            $table->unsignedInteger('overdue');
            $table->unsignedInteger('expired');
            $table->unsignedInteger('exempt');
            $table->unsignedInteger('never_reviewed');
            $table->unsignedInteger('no_owner');

            // Reviews recorded on this day, whatever they were about.
            $table->unsignedInteger('reviews');

            $table->dateTime('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_snapshots');
    }
};
