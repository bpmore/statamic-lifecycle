<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Http\Controllers\EntryStatusController;
use Illuminate\Support\Facades\Route;

// Same idiom Statamic core uses for its own control panel routes.
Route::prefix('lifecycle')->name('lifecycle.')->group(function (): void {
    Route::get('entries/{entry}/status', [EntryStatusController::class, 'show'])->name('entries.status');
});
