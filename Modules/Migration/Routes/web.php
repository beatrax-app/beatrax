<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

// The four-route migration wizard: index -> upload -> preview -> results —
// see .docs/features/migration/architecture.md ("Wizard pages") for the
// `?reconcile_of={run}` query-parameter contract on the upload route.
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/migrations', 'migration::index')->name('migrations.index');

    Route::view('/migrations/new', 'migration::new')->name('migrations.new');

    Route::get('/migrations/{id}/preview', static function (string $id, ViewFactory $views): Response {
        return new Response($views->make('migration::preview', ['id' => (int) $id])->render());
    })
        ->where('id', '[0-9]+')
        ->name('migrations.preview');

    Route::get('/migrations/{id}/results', static function (string $id, ViewFactory $views): Response {
        return new Response($views->make('migration::results', ['id' => (int) $id])->render());
    })
        ->where('id', '[0-9]+')
        ->name('migrations.results');
});
