<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/imports/new', 'import::wizard')->name('imports.new');

    Route::get('/imports/{id}/preview', static function (string $id, ViewFactory $views): Response {
        return new Response($views->make('import::preview', ['id' => (int) $id])->render());
    })
        ->where('id', '[0-9]+')
        ->name('imports.preview');

    Route::get('/imports/{id}', static function (string $id, ViewFactory $views): Response {
        return new Response($views->make('import::results', ['id' => (int) $id])->render());
    })
        ->where('id', '[0-9]+')
        ->name('imports.results');

    // Settings → Aliases (Phase 16.1 Plan 07). The power-user surface
    // for editing, merging, exporting, and importing the per-user
    // merchant_aliases table. Auth-gated; cross-user attempts hit the
    // 404-not-403 boundary inside the page actions.
    Route::get('/settings/aliases', AliasesSettingsPage::class)->name('settings.aliases');
});
