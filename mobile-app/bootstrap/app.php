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
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureDatabaseReady;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureImportCompleted;
use Modules\Mobile\Internal\NativeMobileAppServiceProvider;
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
 * DIVERGENCE FROM THE DESKTOP ROOT, NARROWED (15-onboarding): the desktop
 * root appends `Modules\Desktop\Internal\Http\Middleware\
 * EnsureDatabaseReady` to the web group, which handles BOTH the
 * pending-migrations redirect AND the fresh-install → welcome redirect.
 * On mobile, migrations are reconciled + run by the `->booted()` hook
 * below (which runs BEFORE any HTTP request reaches the router, per
 * 15-04-PLAN.md) — so by request time migrations are always caught up and
 * this root does NOT need a pending-migrations gate. What mobile DID lack
 * until this plan is the fresh-install → welcome redirect: without it, a
 * genuine 0-user mobile device hit `auth` middleware on any protected
 * route, threw `AuthenticationException`, and landed on the desktop-shaped
 * `/login` (Sign in) screen instead of a mobile first-run welcome surface.
 * `MobileEnsureDatabaseReady` (mobile-module-owned, collaborating with the
 * mobile-only `MobileFirstLaunchBootstrap`) closes that gap the same way
 * the desktop gate's fresh-install branch does — prepended to the `web`
 * group below so it genuinely runs before route `auth` middleware; see the
 * note at that call site for why appending did not.
 *
 * MOBILE FIRST-LAUNCH RECONCILE + MIGRATE-ON-LAUNCH (15-04 Task 2):
 * per 15-SPIKE-FINDINGS.md (Spike B, real-device run) the live SQLite
 * connection on-device targets `…/Library/Application Support/…` while
 * `UserDataPathService::databaseFile()` resolves `…/Documents/app/…` —
 * two divergent, both-unmigrated DB files, which is why the app 500s on
 * `no such table: sessions`. The `->booted()` hook is the PROVEN on-device
 * attach point (the entire Spike B run fired from it); it is guarded on
 * `UserDataPathService::isMobileRuntime()` so it is a no-op under the
 * desktop root / host dev environment.
 *
 * That guard used to be a bare `getenv('NATIVEPHP_PLATFORM')`, which is
 * false on a real device — the runtime injects the value as a server/env
 * const, not through putenv(). The whole hook silently no-opped on
 * Android: `storage/framework` stayed empty and none of the session /
 * cache / view reconciliation below ever ran. Provisioning goes through the
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
        // First-launch fresh-install gate (the mobile mirror of the desktop
        // EnsureDatabaseReady welcome branch): a 0-user device is redirected
        // to `mobile.welcome` instead of throwing AuthenticationException →
        // `/login`. The middleware itself exempts welcome/signup/pair/setup
        // so the redirect cannot loop.
        //
        // PREPENDED, not appended. Appending puts it last in the group, but
        // list order is not run order: SortedMiddleware re-sorts against the
        // framework priority list, and Authenticate implements
        // AuthenticatesRequests, so it gets hoisted ahead of anything
        // unlisted that sits after it — which left this gate running after
        // the very middleware it exists to pre-empt. Prepending is stable
        // because non-priority middleware are never moved by that sort, and
        // the gate reads only the matched route name plus the database, so
        // it needs nothing StartSession sets up.
        $middleware->prependToGroup('web', MobileEnsureDatabaseReady::class);

        // Unfinished-import gate. Appended, not prepended: unlike the gate
        // above it needs an authenticated user, so it must run AFTER
        // Authenticate rather than before it. A device that created its
        // account through the import bootstrap and then quit had no route
        // back into pairing - isFreshInstall() is false forever once a user
        // exists, so it simply landed on an empty dashboard.
        $middleware->web(append: [
            MobileEnsureImportCompleted::class,
            // Resolves the per-request UI language (user override → session
            // → Accept-Language → English). This root has its own bootstrap,
            // so omitting it left the translator on `config('app.locale')`:
            // the switcher wrote `session('locale')` and nothing read it.
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
        // Ensure the on-device (persisted-store) DB directory exists BEFORE any
        // provider opens the sqlite connection. SqliteOptimizationsProvider
        // applies `PRAGMA journal_mode = WAL` on the ConnectionEstablished
        // event during provider boot — EARLIER than the ->booted() migrate hook
        // below — so without the directory the very first cold-boot connection
        // throws "Database file … does not exist" (a one-time, self-healing but
        // noisy error). Harmless off-device: dirname() of the desktop/host DB
        // already exists, so the mkdir is a no-op there.
        $dbFile = UserDataPathService::databaseFile();
        $dbDir = dirname($dbFile);
        if (! is_dir($dbDir)) {
            @mkdir($dbDir, 0775, true);
        }
        // Laravel's SQLite connector THROWS "Database file … does not exist"
        // instead of creating the file, so the empty file must exist before the
        // first ConnectionEstablished (SqliteOptimizationsProvider's PRAGMA WAL)
        // — the migrator populates its schema afterwards in ->booted().
        if (! file_exists($dbFile)) {
            @touch($dbFile);
        }
    })
    ->booted(function (Application $app): void {
        if (! UserDataPathService::isMobileRuntime()) {
            // Not the mobile runtime (desktop root / host dev / test) —
            // no-op.
            return;
        }

        // (1) Reconcile the live sqlite connection to the ONE canonical
        // UserDataPathService-resolved path BEFORE any migrate/read/write —
        // this is what closes the divergent-path 500 (T-15-32). On mobile the
        // authority now resolves to the persisted store (see
        // UserDataPathService::databaseFile()); ensure its directory exists on
        // a genuine fresh install before the migrator opens the sqlite file.
        $canonicalDb = UserDataPathService::databaseFile();
        if (! is_dir(dirname($canonicalDb))) {
            @mkdir(dirname($canonicalDb), 0775, true);
        }
        $app->make('config')->set(
            'database.connections.sqlite.database',
            $canonicalDb,
        );
        $app->make('db')->purge('sqlite');

        // (1b) Recreate the writable storage/framework tree on-device. The
        // native app-copy rsync strips `/storage/framework` (views/cache/
        // sessions), so at config-load `realpath(storage_path('framework/
        // views'))` returns false → an empty `view.compiled` → the Blade
        // compiler throws "Please provide a valid cache path." on EVERY
        // render, 500ing every surface. Recreate the stripped dirs and
        // re-point the compiled-view path at the now-existing dir (the
        // config value was frozen empty before these dirs existed). Same
        // divergence class as the DB reconcile above, for the file-based
        // storage tree instead of the sqlite path.
        foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs', 'app/public'] as $storageDir) {
            $full = storage_path($storageDir);
            if (! is_dir($full)) {
                @mkdir($full, 0775, true);
            }
        }
        // Reconcile the storage-dependent config for the on-device runtime.
        // The native app-copy strips /storage/framework and the build caches
        // config with file-based session/cache paths that do not exist (and
        // whose runtime storage_path resolves differently than at build time),
        // so a file-backed session/cache 500s every request. The on-device
        // sqlite DB (reconciled above, fully migrated — sessions/cache/
        // cache_locks tables all present) is the reliable source of truth, so
        // drive session + cache through it instead of the filesystem. Blade
        // genuinely needs a file path; view.compiled must be non-empty (else
        // "Please provide a valid cache path.") and Blade auto-creates the dir.
        $cfg = $app->make('config');
        $cfg->set('view.compiled', storage_path('framework/views'));
        $cfg->set('session.driver', 'database');
        $cfg->set('cache.default', 'database');

        // (1c) Boot the mobile-native application provider. `config('nativephp.
        // provider')` reads like the wiring for this, but that key belongs to
        // nativephp/desktop — nativephp/mobile has never consulted it, in v3 or
        // v4 — so the class it named booted nowhere and the mobile notification
        // listener it subscribes was registered nowhere. Same proven on-device
        // attach point as the reconciliation above; boot() try/catches its own
        // steps, so a failure here cannot reach the shell.
        if (class_exists(NativeMobileAppServiceProvider::class)) {
            $app->make(NativeMobileAppServiceProvider::class)->boot();
        }

        if (! class_exists(MobileFirstLaunchBootstrap::class)) {
            return;
        }

        try {
            // (2) THEN run the framework-migrator first-launch bootstrap,
            // guarded so a caught-up install never re-migrates on every
            // launch — bounded, no daemon, no persistent process.
            $bootstrap = $app->make(MobileFirstLaunchBootstrap::class);

            // Restores the plugin view directories the bundler strips; the
            // first request otherwise fails view discovery outright.
            $bootstrap->ensurePluginViewPaths($app->basePath());

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
