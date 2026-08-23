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
use Modules\Core\Internal\Http\Middleware\TrustedHostGuard;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\SqliteDatabase;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Modules\Mobile\Internal\Http\Middleware\ForgetGuardsBetweenRequests;
use Modules\Mobile\Internal\Http\Middleware\ForgetStaleLivewireHeaderBetweenRequests;
use Modules\Mobile\Internal\Http\Middleware\ForgetStaleSessionBetweenRequests;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureDatabaseReady;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureImportCompleted;
use Modules\Mobile\Internal\Http\Middleware\RestoreFrameworkRedirector;
use Modules\Mobile\Internal\NativeMobileAppServiceProvider;
use Modules\Mobile\Internal\Spike\SpikeStoragePathCommand;
use Modules\Mobile\Internal\Spike\SpikeSyncDialCommand;
use Modules\Sync\Internal\Http\Middleware\CarriesPendingPairingFrames;
use Psr\Log\LoggerInterface;

/**
 * @link ../../.docs/features/mobile/architecture.md#the-mobile-roots-own-bootstrap
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
        // Registered from this root only, never the shared MobileServiceProvider.
        // Under the desktop root the two autoload but stay unregistered, so they
        // are inert there and cannot reach the desktop app.
        SpikeSyncDialCommand::class,
        SpikeStoragePathCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Prepended ahead of the guard drop so it RUNS after it: that one reads
        // the session the previous request left in memory to decide, and this is
        // what empties it, so the other order leaves the stale User in place.
        $middleware->prepend(ForgetStaleSessionBetweenRequests::class);

        // Same class of leak as the three above, on a header rather than on a
        // binding. Prepended so it runs before anything Livewire boots.
        $middleware->prepend(ForgetStaleLivewireHeaderBetweenRequests::class);

        // prepend() reverses, so the last call here runs first. This one belongs
        // after the container binding RestoreFrameworkRedirector repairs and
        // before anything reads the authenticated user, whose model a runtime
        // this long-lived would otherwise still hold from sign-in.
        $middleware->prepend(ForgetGuardsBetweenRequests::class);
        $middleware->prepend(RestoreFrameworkRedirector::class);
        $middleware->prepend(LoopbackOnly::class);
        // LoopbackOnly gates the interface the connection arrived on; this gates
        // the Host the client asked for, which is the half a DNS-rebinding site
        // defeats. The mobile webview is loopback-served, so it needs both.
        $middleware->prepend(TrustedHostGuard::class);
        $middleware->append(NoStoreFinancialData::class);
        // Prepended, not appended: SortedMiddleware re-sorts the group against
        // the framework priority list and hoists Authenticate ahead of anything
        // unlisted behind it, which left this gate running after the very
        // middleware it exists to pre-empt.
        $middleware->prependToGroup('web', MobileEnsureDatabaseReady::class);

        // Appended rather than prepended: unlike the gate above, this one needs
        // an authenticated user, so it has to run after Authenticate.
        $middleware->web(append: [
            // This root runs no daemon, no queue worker and no scheduler, so a
            // request is the ONLY thing that ever drives the pairing courier
            // here. Leaving it out is what tied redelivery to one open screen.
            CarriesPendingPairingFrames::class,
            MobileEnsureImportCompleted::class,
            // This root carries its own bootstrap, so leaving it out left the
            // translator on config('app.locale'): the language switcher wrote
            // session('locale') and nothing read it.
            SetLocale::class,
        ]);
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
    ->booting(function (): void {
        // Both calls have to land before any provider opens the connection:
        // SqliteOptimizationsProvider sets PRAGMA journal_mode on
        // ConnectionEstablished, and Laravel's SQLite connector throws on a
        // missing file rather than creating one. No-ops off device.
        $dbFile = UserDataPathService::databaseFile();
        $dbDir = dirname($dbFile);
        if (! is_dir($dbDir)) {
            @mkdir($dbDir, 0775, true);
        }
        if (! file_exists($dbFile)) {
            @touch($dbFile);
        }
    })
    ->booted(function (Application $app): void {
        if (! UserDataPathService::isMobileRuntime()) {
            return;
        }

        // Before any migrate, read or write: the live connection and this
        // accessor otherwise resolve two different unmigrated files, which
        // reaches the screen as "no such table: sessions".
        $canonicalDb = UserDataPathService::databaseFile();
        if (! is_dir(dirname($canonicalDb))) {
            @mkdir(dirname($canonicalDb), 0775, true);
        }
        $app->make('config')->set(
            SqliteDatabase::LIVE_PATH_CONFIG_KEY,
            $canonicalDb,
        );
        $app->make('db')->purge('sqlite');

        // The native app-copy strips storage/framework, so at config-load
        // realpath(storage_path('framework/views')) is false, view.compiled
        // freezes empty, and Blade throws "Please provide a valid cache path."
        // on every render.
        foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs', 'app/public'] as $storageDir) {
            $full = storage_path($storageDir);
            if (! is_dir($full)) {
                @mkdir($full, 0775, true);
            }
        }
        // The build caches config with file-based session and cache paths that
        // do not exist at runtime, so both are driven through the reconciled
        // database instead. Blade genuinely needs a directory, which is why
        // view.compiled is repointed rather than moved off the filesystem.
        $cfg = $app->make('config');
        $cfg->set('view.compiled', storage_path('framework/views'));
        $cfg->set('session.driver', 'database');
        $cfg->set('cache.default', 'database');

        // config('nativephp.provider') reads like the wiring for this, but that
        // key belongs to nativephp/desktop and nativephp/mobile has never read
        // it, so the class it named booted nowhere. boot() try/catches its own
        // steps, so nothing here can reach the shell.
        if (class_exists(NativeMobileAppServiceProvider::class)) {
            $app->make(NativeMobileAppServiceProvider::class)->boot();
        }

        if (! class_exists(MobileFirstLaunchBootstrap::class)) {
            return;
        }

        try {
            $bootstrap = $app->make(MobileFirstLaunchBootstrap::class);

            // Restores the plugin view directories the bundler strips; the
            // first request otherwise fails view discovery outright.
            $bootstrap->ensurePluginViewPaths($app->basePath());

            if ($bootstrap->hasPendingMigrations()) {
                $bootstrap->runPendingMigrations();
            }
        } catch (Throwable $e) {
            // Non-fatal: a boot-time hook that throws takes the whole shell down,
            // and the app has to open before anything can be repaired.
            $app->make(LoggerInterface::class)->error(
                'Mobile first-launch migrate-on-launch failed non-fatally.',
                ['exception' => $e],
            );
        }
    })
    ->create();
