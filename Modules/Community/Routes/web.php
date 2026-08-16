<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/community', 'community::index')->name('community.index');

    Route::view('/community/mystery-merchants', 'community::mystery-merchants')
        ->name('community.mystery-merchants');
});
