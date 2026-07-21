<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * @link ../../../.docs/features/mobile/architecture.md
 */

// Deliberately OUTSIDE the `auth` group - it renders BEFORE any user
// account exists on the device, gated in front of it by
// MobileEnsureDatabaseReady. The welcome screen's two CTAs lead into
// /signup and /mobile/pair (the auth-gated pairing entry below).
Route::middleware(['web'])->group(static function (): void {
    Route::get('/mobile/welcome', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\MobileWelcomeScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.welcome');

    // The "Import from another device" fresh-device local-identity
    // bootstrap. Deliberately outside the auth group - it runs before any
    // user account exists, exactly like mobile.welcome above.
    Route::get('/mobile/import', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.import');
});

Route::middleware(['web', 'auth'])->group(static function (): void {
    // Sync-status surface; the sidebar/drawer entry links here by
    // name.
    Route::get('/sync', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\SyncScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('sync.index');

    Route::get('/mobile/lock', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\MobileLockScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.lock');

    Route::get('/mobile/pair', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\MobilePairingScan';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.pair');

    // Blocking full-screen resumable initial-sync gate - the post-pairing
    // landing page.
    Route::get('/mobile/setup', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\SetupProgressScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.setup');
});
