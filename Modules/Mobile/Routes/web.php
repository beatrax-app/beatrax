<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Mobile module web routes.
 *
 * This file is the single-owner route registration for the whole phase —
 * no downstream plan edits it (mirrors Modules\Sync\Providers\
 * SyncServiceProvider's own single-owner precedent). Plans 06/07/08/10
 * create the four Livewire classes named below;
 * MobileServiceProvider::boot() registers the matching Livewire component
 * tag once each class exists.
 *
 * Every target is dispatched through a Closure — deliberately NOT a bare
 * class-string / `@__invoke` action — because a string action is unsafe
 * in TWO independent ways when the target class does not exist yet:
 *   1. Illuminate\Routing\RouteAction::makeInvokable() eagerly reflects
 *      the class at route-registration time (application boot) for a
 *      bare class-string action, fataling with "Invalid route action".
 *   2. `php artisan route:list` ALWAYS reflects the controller class via
 *      RouteListCommand::isVendorRoute() for ANY string-action route,
 *      regardless of a class_exists() guard placed around the
 *      Route::get() call itself — the guard only controls whether the
 *      route registers, not what route:list does with an already-
 *      registered string action.
 * A Closure sidesteps both: RouteAction::parse() takes the "already a
 * Closure" branch (no eager reflection of the target class), and
 * route:list reflects the Closure itself (always safe, no target-class
 * dependency). The target FQCN is resolved via the container and invoked
 * only when a real request reaches the route — by then Plans 06/07/08/10
 * will have shipped the class. Calling the resolved instance as
 * `$component()` reproduces Livewire's own
 * HandlesPageComponents::__invoke() dispatch path exactly (mount + layout
 * + render); none of these four routes carry route parameters, so
 * Livewire's implicit-binding pre-hook (which keys off a string action)
 * is not needed here.
 *
 * Loaded by MobileServiceProvider via loadRoutesFrom(__DIR__.'/../Routes/web.php').
 */
/*
 * 15-onboarding: mobile first-run welcome surface (D-21/D-22 mobile mirror).
 *
 * Deliberately OUTSIDE the `auth` group — it renders BEFORE any user
 * account exists on the device, gated in front of it by
 * `MobileEnsureDatabaseReady` (registered on the mobile-app root's `web`
 * middleware group in `mobile-app/bootstrap/app.php`). The welcome
 * screen's two CTAs lead into `/signup` (open while `User::count() === 0`,
 * Phase 12 D-03) and `/mobile/pair` (the auth-gated pairing entry below).
 */
Route::middleware(['web'])->group(static function (): void {
    Route::get('/mobile/welcome', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\MobileWelcomeScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.welcome');

    // Phase 15 import-join (Task 5): the "Import from another device"
    // fresh-device local-identity bootstrap. Deliberately OUTSIDE the
    // `auth` group — it runs BEFORE any user account exists, exactly like
    // mobile.welcome above (MobileEnsureDatabaseReady exempts this route
    // name the same way).
    Route::get('/mobile/import', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.import');
});

Route::middleware(['web', 'auth'])->group(static function (): void {
    // Plan 10: sync-status surface. `/sync` — the sidebar/drawer entry
    // (15-PATTERNS.md) links here by name.
    Route::get('/sync', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\SyncScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('sync.index');

    // Plan 06: biometric-primary unlock screen (PIN pad fallback).
    Route::get('/mobile/lock', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\MobileLockScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.lock');

    // Plan 07: camera-first pairing entry (word-code fallback).
    Route::get('/mobile/pair', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\MobilePairingScan';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.pair');

    // Plan 08: blocking full-screen resumable initial-sync gate — the
    // post-pairing landing page (D-03/D-04).
    Route::get('/mobile/setup', static function () {
        $component = 'Modules\Mobile\Internal\Http\Livewire\SetupProgressScreen';
        abort_unless(class_exists($component), 404);

        return app($component)();
    })->name('mobile.setup');
});
