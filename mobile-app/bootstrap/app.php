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
use Modules\Mobile\Internal\Spike\SpikeStoragePathCommand;
use Modules\Mobile\Internal\Spike\SpikeSyncDialCommand;

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
 * desktop-only; the mobile peer's first-launch gate is the (not-yet-built)
 * `Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap` (Plan 04). Until
 * that ships, the mobile root simply omits the desktop gate so the mobile
 * shell stays fully decoupled from `Modules\Desktop`.
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
    ->create();
