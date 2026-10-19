<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a review came from: a person pressing "Mark reviewed" (an
 * attestation), or — only on a site that has said editing counts — a
 * person saving the page (an edit).
 *
 * Spec §11 recommends that editing not count, and offers it as an option,
 * default off. If a site turns it on, the record has to keep the two apart:
 * an auditor asking "who confirmed this was accurate?" is owed the
 * difference between a signature and a save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lifecycle_reviews', function (Blueprint $table) {
            $table->string('source')->default('attestation')->after('entry_revision_ref');
        });
    }

    public function down(): void
    {
        Schema::table('lifecycle_reviews', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
