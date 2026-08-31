<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Mobile\Internal\Http\Middleware\RequireUnlockedIdentityForPairing;

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

    // The route back from a wipe, and the reason it is safe outside the auth
    // group: the component refuses unless `users` is empty, on the action as
    // well as on mount. A device with an account never reaches it, so the
    // most it can replace is a database with nobody in it.
    Route::get('/mobile/restore', static function () {
        $component = 'Modules\\Mobile\\Internal\\Http\\Livewire\\MobileRestoreFromBackup';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.restore');

    // Sits beside /mobile/import rather than inside the auth group: the step
    // that shows the codes runs during signup, and the session holding them is
    // the only thing that can reach them.
    Route::get('/mobile/recovery-codes/export', static function () {
        $controller = 'Modules\\Mobile\\Internal\\Http\\Controllers\\RecoveryCodesExportController';
        abort_unless(class_exists($controller), 404);

        return app()->call(app($controller));
    })->name('mobile.recovery-codes.export');
});

Route::middleware(['web', 'auth'])->group(static function (): void {
    // Data & Devices: everything about where this user's data comes from,
    // where it goes, and which devices hold it — bank connections, folder
    // auto-import, backup and restore, pairing, app lock, network.

    // It began as a sync-status surface, which is why the component is still
    // named SyncScreen; sync is one section of it now, not the whole page.
    Route::get('/data-devices', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\SyncScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('data-devices.index');

    // The one screen a half-built database can reach. Named outside the
    // mobile.setup prefix so the initial-sync gate's exemptions do not also
    // exempt it — a device that never finished migrating has no sync to gate.
    Route::get('/mobile/database-incomplete', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\SchemaIncompleteScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.database-incomplete');

    Route::get('/mobile/lock', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\MobileLockScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.lock');

    // On the page load only, never on the Livewire endpoint behind it: the
    // app-lock allow-list exempts this screen so a live ceremony's poll is not
    // interrupted, and that same exemption is what let a locked reader type and
    // spend a one-shot code before being shown the PIN pad.
    Route::get('/mobile/pair', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\MobilePairingScan';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->middleware(RequireUnlockedIdentityForPairing::class)->name('mobile.pair');

    // Blocking full-screen resumable initial-sync gate - the post-pairing
    // landing page.
    Route::get('/mobile/setup', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\SetupProgressScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.setup');

    // Where the setup gate hands off once parity is reached. Named under the
    // `mobile.setup` prefix on purpose: MobileEnsureImportCompleted exempts
    // that prefix, so the confirmation cannot be bounced back into the gate
    // it was just released from.
    Route::get('/mobile/setup/done', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\SyncCompleteScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.setup.done');
});

// Native screens (SuperNative / EDGE). Guarded on the macro rather than
// class_exists(): `Route::native()` is installed by nativephp/mobile, and
// the desktop root loads this same file from a tree where that package
// cannot exist. A class-string target is safe here — the macro stores it.
if (Route::hasMacro('native')) {
    Route::native('/shell', 'Modules\Mobile\Internal\Native\AppShellScreen');
}
