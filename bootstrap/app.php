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
use Modules\Core\Internal\Http\Middleware\SetLocale;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Auto-discover artisan commands living at the project root under
    // `app/Console/Commands/`. Module-local commands are still
    // registered from each module's ServiceProvider via `$this->commands()`;
    // this entry only covers commands that span multiple modules and
    // therefore have no natural module owner (e.g. `demo:seed`).
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
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
            // Appended to `web` (not global) so it runs after StartSession
            // and the auth guard are available — both are signals it reads.
            // Resolves the per-request UI language (user override → session
            // → Accept-Language → English) onto the translator before any
            // route renders.
            SetLocale::class,
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

        // Attach the request method + path to every reported exception.
        // Laravel's default handler logs framework exceptions — notably
        // the NativePHP `PreventRegularBrowserAccess` 403 — as a bare
        // stack trace with no request context, which is exactly what made
        // the first Windows 403 flood impossible to attribute to a route.
        // Method + path ONLY: never the query string or request body, so
        // no financial data reaches the log (the NoStoreFinancialData
        // invariant). The request is resolved through the container
        // statically rather than the `request()` global helper to satisfy
        // the larastan strict no-global-function rule.
        $exceptions->context(function (): array {
            $request = Container::getInstance()->make(Request::class);

            return [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
            ];
        });
    })
    ->booting(function (): void {
        // Ensure the SQLite file exists BEFORE any provider opens the
        // connection. Laravel's SQLite connector THROWS "Database file …
        // does not exist" rather than creating the file, and
        // SqliteOptimizationsProvider applies `PRAGMA journal_mode = WAL`
        // on the first ConnectionEstablished during provider boot — so the
        // empty file must already be on disk. This fires on EVERY boot and
        // is the desktop counterpart of mobile-app/bootstrap/app.php's
        // ->booting hook. Two distinct callers depend on it:
        //   • the `native:build` desktop packaging step runs
        //     `composer install` → `package:discover`, which boots the app
        //     in the copied build tree where `database/*.sqlite` was
        //     stripped by cleanup_exclude_files — without this the build
        //     aborts before electron-builder ever signs.
        //   • a genuine fresh install resolves databaseFile() to the
        //     writable NATIVEPHP_STORAGE_PATH root where no file exists yet;
        //     FirstLaunchBootstrap then migrates into it.
        // Harmless everywhere else: in local dev the file already exists so
        // both branches no-op. NEVER seeds or migrates here — this only
        // guarantees an empty file the migrator can populate.
        $dbFile = UserDataPathService::databaseFile();
        $dbDir = dirname($dbFile);
        if (! is_dir($dbDir)) {
            @mkdir($dbDir, 0775, true);
        }
        if (! file_exists($dbFile)) {
            @touch($dbFile);
        }
    })
    ->create();
