<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three columns the computation turned out to need the row to remember.
 *
 * baseline_review_at is the date lifecycle:baseline spread the entry to.
 * It is not the same as next_review_at: a hard deadline on the entry
 * overrides next_review_at, and if that deadline is later removed the
 * baseline date has to still be there to fall back to. The computed date
 * cannot be the record of the assigned one.
 *
 * The two source columns say where the interval and the next date came
 * from — entry, blueprint, collection, global; hard date, last review,
 * baseline, last modification — so a listing can explain a row without
 * computing it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lifecycle_states', function (Blueprint $table) {
            $table->dateTime('baseline_review_at')->nullable()->after('baselined');
            $table->string('interval_source', 16)->nullable()->after('interval_days');
            $table->string('next_review_source', 16)->nullable()->after('next_review_at');
        });
    }

    public function down(): void
    {
        Schema::table('lifecycle_states', function (Blueprint $table) {
            $table->dropColumn(['baseline_review_at', 'interval_source', 'next_review_source']);
        });
    }
};
