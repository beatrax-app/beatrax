<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
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
        // First-launch DB gate (D-21 / D-22). Appended to the `web` group
        // so every pre-auth request that resolves a web route is funneled
        // through the first-launch surfaces: pending migrations bounce
        // to `desktop.setup`; once migrations are done a zero-user state
        // bounces to `desktop.welcome` (the only reliable post-migration
        // signal that no account has been created on this device). The
        // middleware itself exempts setup / welcome / signup so the
        // redirect cannot loop. Once a user exists it is a pass-through.
        $middleware->web(append: [
            EnsureDatabaseReady::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // AuthenticationException fires every time a guest hits a
        // protected route — the redirect-to-login that follows is the
        // intended UX, not an error condition. Laravel's default
        // handler logs every fire as `production.ERROR`, which on a
        // NativePHP cold boot floods the log with one entry per
        // protected route the browser auto-loads before the session
        // cookie lands. Stop reporting it; the redirect itself is
        // still surfaced to the user.
        $exceptions->dontReport([
            AuthenticationException::class,
        ]);
    })
    ->create();
