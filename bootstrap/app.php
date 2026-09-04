<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Auth\Internal\Http\Middleware\ForgetsSpentRecoveryCodes;
use Modules\Core\Internal\Http\Middleware\LoopbackOnly;
use Modules\Core\Internal\Http\Middleware\NoStoreFinancialData;
use Modules\Core\Internal\Http\Middleware\SetLocale;
use Modules\Core\Internal\Http\Middleware\TrustedHostGuard;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\AppChromeResolver;
use Modules\Core\Public\Support\LivewireClientRefusal;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;
use Modules\Desktop\Internal\Http\Middleware\RecoverSealedLedger;
use Modules\Notifications\Internal\Http\Middleware\RunDeferredNotificationPasses;
use Modules\Sync\Internal\Http\Middleware\CarriesPendingPairingFrames;
use Modules\Sync\Internal\Http\Middleware\DeliversOwedEpochs;
use Modules\Sync\Internal\Http\Middleware\DrainsDeferredOpCaptures;
use Modules\Sync\Internal\Http\Middleware\ResumesPreSyncCapture;
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
        // The client writes this one from matchMedia, so it arrives in
        // plaintext; decrypting it fails and the request reaches the resolver
        // with no scheme at all, rendering light on a dark device. It carries
        // the two words `dark` and `light` and nothing else.
        $middleware->encryptCookies(except: [
            AppChromeResolver::SCHEME_COOKIE,
        ]);
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
        // This root only. The mobile root drives its own re-projection from
        // the import cursor, and a second one firing per poll would rebuild
        // the whole history on every tick of a running import.
        $middleware->web(append: [
            SetLocale::class,
            EnsureDatabaseReady::class,
            RecoverSealedLedger::class,
            // Same seam, one layer up: that one re-seals rows a keyless writer
            // left readable, this one re-derives the notification content a
            // keyless writer was refused outright.
            RunDeferredNotificationPasses::class,
            // The recovery-codes ceremonies have no exit the server sees: the
            // mobile one leaves by a plain link and either can be walked away
            // from. This ends them from the other side instead.
            ForgetsSpentRecoveryCodes::class,
            // The recovery-codes ceremonies have no exit the server sees: the
            // mobile one leaves by a plain link and either can be walked away
            // from. This ends them from the other side instead.
            // Terminate-time, and last: a pairing ceremony must not depend on
            // one screen staying open, and this root's other driver — the
            // sync:serve timer — is only running while the daemon is up.
            CarriesPendingPairingFrames::class,
            // Also terminate-time, and for the same reason: signing needs the
            // app-lock key, so a capture too large for one request can only be
            // finished by another one.
            ResumesPreSyncCapture::class,
            // And again for the mutations a scheduler, the daemon or a locked
            // screen raised: the capture sink held their coordinates because no
            // process outside a request holds the key that signs them.
            DrainsDeferredOpCaptures::class,
            DeliversOwedEpochs::class,
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

        // Mapped rather than rendered -- the locked-property exception renders
        // itself, differently in debug than in production -- and through ONE
        // seam, keyed on Throwable because the family spans a bare \Exception
        // and a TypeError, and only Throwable is `is_a` to both.
        $exceptions->map(fn (Throwable $e): Throwable => LivewireClientRefusal::refusal($e) ?? $e);

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
