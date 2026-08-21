<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Core\Internal\Http\Middleware\LoopbackOnly;
use Modules\Core\Internal\Http\Middleware\NoStoreFinancialData;
use Modules\Core\Internal\Http\Middleware\SetLocale;
use Modules\Core\Internal\Http\Middleware\TrustedHostGuard;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;
use Psr\Log\LoggerInterface;

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
        // SetLocale goes first because EnsureDatabaseReady redirects a device
        // with no account, which left every pre-signup screen in English.
        $middleware->web(append: [
            SetLocale::class,
            EnsureDatabaseReady::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The intended redirect, not an error: reported, a NativePHP cold boot
        // logs one production.ERROR per route loaded before the cookie lands.
        $exceptions->dontReport([
            AuthenticationException::class,
        ]);

        // Method and path ONLY: the query string and body carry financial data
        // here. With no context at all a 403 logs as an unattributable trace.
        $exceptions->context(function (): array {
            $request = Container::getInstance()->make(Request::class);

            return [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
            ];
        });

        // A QueryException's message carries its bindings, which here ARE the
        // financial data the context callback above withholds.
        $exceptions->reportable(function (QueryException $e): bool {
            Container::getInstance()->make(LoggerInterface::class)->error(
                'Database query failed.',
                SafeExceptionContext::describe($e),
            );

            return false;
        });
    })
    ->booting(function (): void {
        /**
         * @link ../.docs/architecture/sqlite-file-precreation.md
         */
        // The empty file must exist before provider boot opens the connection,
        // and must NEVER be seeded or migrated here.
        $dbFile = UserDataPathService::databaseFile();
        $dbDir = dirname($dbFile);
        if (! is_dir($dbDir)) {
            @mkdir($dbDir, 0775, true);
        }
        if (! file_exists($dbFile)) {
            @touch($dbFile);
            // Owner-only before the migrator writes anything: the usual 0644
            // umask would expose every balance and account number in plaintext.
            @chmod($dbFile, 0600);
        }
    })
    ->create();
