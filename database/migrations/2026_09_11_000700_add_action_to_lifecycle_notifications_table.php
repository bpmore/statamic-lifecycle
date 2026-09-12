<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A notice is a reminder of a particular impending action: "this page will
 * be unpublished on the 18th unless reviewed". Which action has to be on
 * the row, because the notice period is counted per action — a notice of
 * a banner is not notice of an unpublish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lifecycle_notifications', function (Blueprint $table) {
            // banner | noindex | unpublish, for type `notice`; null otherwise.
            $table->string('action', 16)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('lifecycle_notifications', function (Blueprint $table) {
            $table->dropColumn('action');
        });
    }
};
