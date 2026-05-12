<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/imports/new', 'import::wizard')->name('imports.new');

    Route::get('/imports/{id}/preview', static function (string $id) {
        return view('import::preview', ['id' => (int) $id]);
    })
        ->where('id', '[0-9]+')
        ->name('imports.preview');

    Route::get('/imports/{id}', static function (string $id) {
        return view('import::results', ['id' => (int) $id]);
    })
        ->where('id', '[0-9]+')
        ->name('imports.results');
});
