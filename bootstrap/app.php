<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Modules\Core\Public\Support\SafeExceptionContext;
use Illuminate\Database\QueryException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Core\Internal\Http\Middleware\LoopbackOnly;
use Modules\Core\Internal\Http\Middleware\NoStoreFinancialData;
use Modules\Core\Internal\Http\Middleware\SetLocale;
use Modules\Core\Internal\Http\Middleware\TrustedHostGuard;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Only for commands spanning several modules and so owned by none —
    // a module's own commands register in its ServiceProvider.
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(LoopbackOnly::class);
        // The other half of the loopback boundary: LoopbackOnly gates the
        // interface, this gates the Host — the half DNS rebinding defeats.
        $middleware->prepend(TrustedHostGuard::class);
        // Global, not group-scoped, so the header also covers non-web
        // responses such as /up.
        $middleware->append(NoStoreFinancialData::class);
        // `web`, not global: both read StartSession and the auth guard.
        // SetLocale stays AHEAD of EnsureDatabaseReady — that gate redirects a
        // device with no account, and a redirect short-circuits the stack, so
        // behind it every pre-signup screen rendered in English regardless.
        $middleware->web(append: [
            SetLocale::class,
            EnsureDatabaseReady::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A guest hitting a protected route is the intended redirect, not an
        // error; left reported, a NativePHP cold boot writes one
        // production.ERROR per route the browser loads before the cookie lands.
        $exceptions->dontReport([
            AuthenticationException::class,
        ]);

        // Method and path ONLY — never the query string or body, which in
        // this app carry financial data. Without any context a framework 403
        // logs as a bare stack trace, which is what made the first Windows
        // 403 flood impossible to attribute to a route.
        $exceptions->context(function (): array {
            $request = Container::getInstance()->make(Request::class);

            return [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
            ];
        });

        // A QueryException's message carries the statement AND its bindings,
        // and here the bindings ARE the financial data — the default reporter
        // would log exactly what the context callback above withholds.
        $exceptions->reportable(function (QueryException $e): bool {
            Container::getInstance()->make(LoggerInterface::class)->error(
                'Database query failed.',
                SafeExceptionContext::describe($e),
            );

            return false;
        });
    })
    ->booting(function (): void {
        // The empty file must exist before provider boot opens the
        // connection, and must NEVER be seeded or migrated here.
        // Why, and which two callers depend on it:
        // ../.docs/architecture/sqlite-file-precreation.md
        $dbFile = UserDataPathService::databaseFile();
        $dbDir = dirname($dbFile);
        if (! is_dir($dbDir)) {
            @mkdir($dbDir, 0775, true);
        }
        if (! file_exists($dbFile)) {
            @touch($dbFile);
            // Owner-only from creation, before the migrator writes anything:
            // the umask default is commonly 0644 and this file holds every
            // transaction, balance and account number in plaintext.
            @chmod($dbFile, 0600);
        }
    })
    ->create();
