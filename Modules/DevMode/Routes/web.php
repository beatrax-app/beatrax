<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DevMode\Internal\Http\Controllers\AdvancedToggleController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanCancelController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanSpawnController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanStreamController;
use Modules\DevMode\Internal\Http\Controllers\DestructiveSpawnController;
use Modules\DevMode\Internal\Http\Controllers\LogStreamController;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Http\Livewire\AuditLogPage;
use Modules\DevMode\Internal\Http\Livewire\DevOverviewPage;
use Modules\DevMode\Internal\Http\Livewire\DoctorPanelPage;
use Modules\DevMode\Internal\Http\Livewire\LogTailerPage;
use Modules\DevMode\Internal\Http\Livewire\QueueInspectorPage;
use Modules\DevMode\Internal\Http\Livewire\SqlPanelPage;
use Modules\DevMode\Internal\Http\Livewire\SystemSnapshotPage;

// An arch test requires ensureDeveloperMode on every /dev/* route, so one
// added outside this group fails the build rather than shipping ungated.
Route::middleware(['web', 'auth', 'ensureDeveloperMode'])
    ->prefix('/dev')
    ->group(static function (): void {
        Route::get('/', DevOverviewPage::class)->name('dev.overview');

        Route::get('/artisan', ArtisanRunnerPage::class)->name('dev.artisan');
        Route::get('/audit', AuditLogPage::class)->name('dev.audit');

        Route::post('/artisan/spawn', ArtisanSpawnController::class)
            ->name('dev.artisan.spawn');
        Route::get('/artisan/stream/{runId}', ArtisanStreamController::class)
            ->name('dev.artisan.stream');
        Route::post('/artisan/cancel/{runId}', ArtisanCancelController::class)
            ->name('dev.artisan.cancel');

        Route::post('/advanced-toggle', AdvancedToggleController::class)
            ->name('dev.advanced-toggle');

        Route::post('/artisan/destructive-spawn', DestructiveSpawnController::class)
            ->name('dev.artisan.destructive-spawn');

        // Polling, not SSE: the SSE version held the single-threaded PHP
        // built-in server's only worker for the life of the stream.
        Route::get('/logs', LogTailerPage::class)->name('dev.logs');
        Route::get('/logs/poll', [LogStreamController::class, 'poll'])->name('dev.logs.poll');
        Route::get('/logs/context', [LogStreamController::class, 'context'])->name('dev.logs.context');
        Route::get('/logs/stats', [LogStreamController::class, 'stats'])->name('dev.logs.stats');

        // `dev.queue.tab` is the name deep-link consumers build against;
        // `dev.queue` exists only to redirect the bare URL onto it.
        Route::get('/queue', static fn () => redirect()->route('dev.queue.tab', ['tab' => 'pending']))
            ->name('dev.queue');
        Route::get('/queue/{tab}', QueueInspectorPage::class)
            ->where('tab', 'pending|failed|batches')
            ->name('dev.queue.tab');

        Route::get('/doctor', DoctorPanelPage::class)->name('dev.doctor');
        Route::get('/system', SystemSnapshotPage::class)->name('dev.system');
        // The schema viewer is an inner sidebar on this page, not its own
        // /dev/sql/schema route.
        Route::get('/sql', SqlPanelPage::class)->name('dev.sql');

        // dev.horizon is absent on purpose: the ServiceProvider registers it
        // in boot(), only once the dev-mode flag and Horizon signals agree.
    });
