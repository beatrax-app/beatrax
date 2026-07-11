<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Core\Internal\Http\Middleware\LoopbackOnly;
use Modules\Core\Internal\Http\Middleware\NoStoreFinancialData;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Modules\Mobile\Internal\Spike\SpikeStoragePathCommand;
use Modules\Mobile\Internal\Spike\SpikeSyncDialCommand;
use Psr\Log\LoggerInterface;

/*
 * Mobile-app bootstrap.
 *
 * A REAL file (never a symlink): `dirname(__DIR__)` must resolve to the
 * mobile-app root so `base_path()` — and every UserDataPathService accessor
 * that derives from it (Modules path, database path, storage) — points at this
 * sibling root, not the desktop parent. `__FILE__` is symlink-canonicalised in
 * PHP, so a symlinked bootstrap would silently retarget base_path() to the
 * parent and boot the desktop app instead.
 *
 * DELIBERATE DIVERGENCE FROM THE DESKTOP ROOT: the desktop root appends
 * `Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady` to the web
 * group (its first-launch migration/welcome gate). That middleware is a
 * Desktop-module surface and its collaborator (`FirstLaunchBootstrap`) is
 * desktop-only; the mobile peer's first-launch gate is the `->booted()`
 * hook below, which reconciles the live sqlite connection to the one
 * `UserDataPathService` path authority and then runs
 * `Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap` (Plan 04).
 *
 * MOBILE FIRST-LAUNCH RECONCILE + MIGRATE-ON-LAUNCH (15-04 Task 2):
 * per 15-SPIKE-FINDINGS.md (Spike B, real-device run) the live SQLite
 * connection on-device targets `…/Library/Application Support/…` while
 * `UserDataPathService::databaseFile()` resolves `…/Documents/app/…` —
 * two divergent, both-unmigrated DB files, which is why the app 500s on
 * `no such table: sessions`. The `->booted()` hook is the PROVEN on-device
 * attach point (the entire Spike B run fired from it); it is guarded on
 * `getenv('NATIVEPHP_PLATFORM') !== false` so it is a no-op under the
 * desktop root / host dev environment. Provisioning goes through the
 * framework `Migrator` (via `MobileFirstLaunchBootstrap`) — NEVER a
 * shelled-out `sqlite3` binary, which Spike B confirmed is absent
 * on-device. A migrate failure is logged and swallowed — a boot-time
 * mobile hook must never crash the app shell.
 *
 * `config('nativephp.provider') → NativeMobileAppServiceProvider::boot()`
 * wiring was NOT directly exercised by Spike B (only `->booted()` was), so
 * this hook is the sole authoritative first-launch gate; deliberately not
 * duplicated in `NativeMobileAppServiceProvider` to avoid two independent
 * migrate-on-launch call sites.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
        // Phase 15-02 throwaway topology probes. Registered ONLY here (the
        // mobile-app-owned bootstrap), never from the shared
        // Modules\Mobile\Providers\MobileServiceProvider — 15-01 owns that
        // provider/routes and no downstream plan edits it. Under the desktop
        // root these classes are autoloadable but unregistered, so they are
        // inert there and cannot affect the desktop app.
        SpikeSyncDialCommand::class,
        SpikeStoragePathCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(LoopbackOnly::class);
        $middleware->append(NoStoreFinancialData::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([
            AuthenticationException::class,
        ]);

        $exceptions->context(function (): array {
            $request = Container::getInstance()->make(Request::class);

            return [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
            ];
        });
    })
    ->booted(function (Application $app): void {
        if (getenv('NATIVEPHP_PLATFORM') === false) {
            // Not the mobile runtime (desktop root / host dev / test) —
            // no-op. The single reliable on-device signal per Spike B.
            return;
        }

        // (1) Reconcile the live sqlite connection to the ONE canonical
        // UserDataPathService-resolved path BEFORE any migrate/read/write —
        // this is what closes the divergent-path 500 (T-15-32).
        $app->make('config')->set(
            'database.connections.sqlite.database',
            UserDataPathService::databaseFile(),
        );
        $app->make('db')->purge('sqlite');

        if (! class_exists(MobileFirstLaunchBootstrap::class)) {
            return;
        }

        try {
            // (2) THEN run the framework-migrator first-launch bootstrap,
            // guarded so a caught-up install never re-migrates on every
            // launch — bounded, no daemon, no persistent process.
            $bootstrap = $app->make(MobileFirstLaunchBootstrap::class);
            if ($bootstrap->hasPendingMigrations()) {
                $bootstrap->runPendingMigrations();
            }
        } catch (Throwable $e) {
            // Non-fatal: a boot-time mobile hook failing must never
            // prevent the app shell from opening.
            $app->make(LoggerInterface::class)->error(
                'Mobile first-launch migrate-on-launch failed non-fatally.',
                ['exception' => $e],
            );
        }
    })
    ->create();
