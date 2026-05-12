<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/uncategorized', 'categorization::triage')->name('uncategorized');
});
