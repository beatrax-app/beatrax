<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Modules\Core\Internal\Http\Middleware\LoopbackOnly;
use Modules\Core\Internal\Http\Middleware\NoStoreFinancialData;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(LoopbackOnly::class);
        // Global append (not group-scoped) so the no-store header lands on
        // every response the app emits — including non-web routes such as
        // the /up health endpoint and any future API or CLI HTTP handler.
        $middleware->append(NoStoreFinancialData::class);
        // First-launch DB gate (D-21). Appended to the `web` group so
        // every authenticated / pre-auth request that resolves a web
        // route is intercepted when migrations are still pending and
        // bounced to the `desktop.setup` "Setting up…" screen until the
        // bootstrap finishes its work. The middleware itself exempts the
        // setup route name so the redirect cannot loop. Once the
        // bootstrap reports no pending migrations it is a pass-through.
        $middleware->web(append: [
            EnsureDatabaseReady::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Default exception handling.
    })
    ->create();
