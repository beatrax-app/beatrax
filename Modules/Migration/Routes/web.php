<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/*
 * The four-route migration wizard (Req 11, UI-SPEC assumption #4): index ->
 * upload -> preview -> results. Mirrors `Modules\Import\Routes\web.php`'s
 * shape exactly (`Route::view` for the two id-less pages, a closure
 * rendering the page shell for the two run-scoped pages, `->where('id',
 * '[0-9]+')` on both).
 *
 * `/migrations/new` accepts an optional `?reconcile_of={run}` query
 * parameter (Req 10 entry point from the index's "Check for updates" row
 * action); `NewMigration::mount()` reads it via injected `Request`, not a
 * route parameter, so this stays a plain `Route::view` like `imports.new`.
 */
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
