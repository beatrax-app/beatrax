<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Modules\Desktop\Internal\Native\AppMenuBuilder;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Contracts\WindowManager;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Menu;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The NativePHP-booted application provider.
 *
 * NativePHP resolves this class from the `provider` key in
 * `config/nativephp.php` (via `app(config('nativephp.provider'))`) and
 * calls `boot()` once the native shell has started. Because the class is
 * resolved through the container, its constructor dependencies are
 * autowired — the WindowManager contract + the AppMenuBuilder ride
 * through constructor DI so the project's "no facade calls in module
 * code" rule stays intact for the builder construction itself.
 *
 * The provider IS on the facade allow-list (BoundaryArchTest +
 * phpstan.neon) because the `Menu::create()` facade call cannot be
 * re-routed: NativePHP wires the application menu via that facade alone,
 * and there is no container-bound alternative path. The crossing stays
 * confined to this one file plus the AppMenuBuilder.
 *
 * `boot()` runs the first-launch DB bootstrap (D-21 / D-22 / D-23)
 * before any window opens so the schema is in place for the very
 * first request the just-opened window makes, then opens the single
 * application window that renders the beatrax web UI (D-10: size +
 * position persist via `WindowManager::open()`'s `rememberState()`) and
 * installs the app menu (D-11 — File/Edit/View/Window/Help + the
 * beatrax-specific File and Help entries).
 *
 * The macOS menu-bar tray icon (D-09) is intentionally NOT installed
 * through NativePHP's `MenuBar` facade. That facade is a wrapper around
 * the npm `menubar` library which produces a popover-style menubar app
 * whose context-menu items couple to the focused BrowserWindow (the
 * `link`-type items in the Electron `compileMenu` helper early-return
 * when no window is focused), so once the user closes the main window
 * via the X button the tray's "Open beatrax" item silently does
 * nothing — the wrong paradigm for D-09 (the tray must outlive any
 * single window so it can re-open the main window from any state).
 *
 * Instead, the persistent tray is created directly in the Electron
 * main process via a durable prebuild patch
 * (`scripts/nativephp_inject_persistent_tray.php`) that wires a native
 * Electron `Tray` instance with a template-flagged icon and a context
 * menu whose handlers show + focus the main window, or re-construct it
 * via the NativePHP `/api/window/open` endpoint if it was closed. The
 * tray asset (monochrome black-on-transparent silhouette) lives at
 * `resources/brand/tray-icon.png` (with a `@2x` sibling) and is staged
 * into the build by `scripts/nativephp_stage_build_resources.php`.
 */
final class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Default window dimensions. Pulled into named constants so the
     * sizing decision is reviewable in one place and `rememberState()`
     * uses these as the first-launch fallback before the persisted
     * geometry takes over. The Electron-side persistent tray's
     * `TRAY_MAIN_WINDOW_PAYLOAD` mirrors these dimensions so a re-opened
     * window matches the originally-opened one.
     */
    private const WINDOW_WIDTH = 1100;

    private const WINDOW_HEIGHT = 800;

    /**
     * Upload-related php.ini ceiling for the bundled NativePHP runtime.
     *
     * The bundled PHP ships with the stock `upload_max_filesize = 2M` /
     * `post_max_size = 8M` defaults — well below the wizard's own
     * server-side `max:10240` (10 MB) Livewire validator ceiling. A
     * larger statement upload (multi-page ICS PDF, an `.eml` archive,
     * an MT940 export) fails at the PHP layer BEFORE Livewire's
     * validator sees the file, and Livewire surfaces the failure as
     * "The files.0 failed to upload." with no actionable context.
     *
     * The 20 MB ceiling here matches the wizard's largest single-file
     * upload (`.eml` capped at 20 MB inside UploadWizard::rules()) plus
     * a multipart-envelope headroom. The mbox arm allows up to 1 GB
     * — that path is rare and the user opts into it by selecting the
     * mbox issuer, so the standard 20 MB ceiling is the sensible
     * default; mbox uploads are intentionally a deferred-improvement
     * surface (the bundled-PHP `post_max_size` would need to grow in
     * lockstep with any mbox-size ceiling increase, but the 20 MB
     * default is enough for every other upload path).
     */
    private const UPLOAD_MAX_FILESIZE = '20M';

    private const POST_MAX_SIZE = '20M';

    /**
     * Maximum wall-clock seconds a single PHP request may spend before
     * the SAPI aborts it with a FatalError.
     *
     * The stock `php.ini` ships `max_execution_time = 30`, which is too
     * tight for two ordinary code paths the desktop runtime hits at
     * boot and on a slow network:
     *
     *   - The NativePHP auto-updater's GitHub feed check rides through
     *     Guzzle (`vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php`)
     *     with the default 30-second curl timeout; a slow DNS resolve
     *     or a high-latency hop fatals the request before the manifest
     *     fetch even starts.
     *   - Heavy ingestion paths (multi-page CAMT.053, `.eml` archive
     *     scans) can exceed 30 s on a cold cache while still
     *     completing successfully on a warm one.
     *
     * 120 s is the lowest ceiling that absorbs both paths without
     * disappearing the safety net entirely.
     */
    private const MAX_EXECUTION_TIME = '120';

    public function __construct(
        private readonly WindowManager $windows,
        private readonly AppMenuBuilder $appMenu,
        private readonly FirstLaunchBootstrap $bootstrap,
        private readonly ConsoleKernel $console,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * php.ini overrides applied to every PHP subprocess the NativePHP
     * shell spawns (artisan, queue:work, schedule:run, the embedded
     * web server). NativePHP merges the returned array on top of the
     * Electron-side defaults defined in
     * `nativephp/electron/electron-plugin/src/server/php.ts`'s
     * `getDefaultPhpIniSettings()` (memory_limit + curl/openssl
     * cainfo), then converts each entry into a `-d key=value` flag at
     * spawn time.
     *
     * Returns key/value pairs as strings — the NativePHP loader does
     * not coerce types, so a numeric `512` would land as
     * `-d upload_max_filesize=512` (bytes, not megabytes) and silently
     * shrink the limit instead of growing it.
     *
     * @return array<string, string>
     */
    public function phpIni(): array
    {
        return [
            'upload_max_filesize' => self::UPLOAD_MAX_FILESIZE,
            'post_max_size' => self::POST_MAX_SIZE,
            'max_execution_time' => self::MAX_EXECUTION_TIME,
        ];
    }

    /**
     * Default sync WebSocket listen port. Mirrors config/sync.php 'port' key.
     *
     * The config() global helper is not available in this provider (phpstan L10
     * noGlobalLaravelFunction rule). The hardcoded constant matches the default
     * value in config/sync.php and can be overridden by setting SYNC_PORT in the
     * environment before the NativePHP app starts.
     */
    private const SYNC_PORT = 51337;

    public function boot(): void
    {
        // First-launch DB bootstrap (D-21 / D-22 / D-23). Runs the
        // framework migration runner BEFORE the main window opens so
        // every subsequent request — including the very first one
        // resolved by the just-opened window — sees a fully migrated
        // schema. Idempotent: when nothing is pending the migrator's
        // own `run()` is a no-op, so re-launches after a clean install
        // stay quiet.
        $this->bootstrap->runPendingMigrations();

        // Start the Noise/WebSocket sync listener as a persistent child process (D-03).
        //
        // Primary host: NativePHP ChildProcess — the packaged desktop app auto-starts
        // and auto-restarts the sync:serve daemon on every launch (D-03/D-04). The
        // persistent: true flag tells NativePHP to restart the process when it exits
        // (crash recovery, T-13-15). STDOUT routes to the application log; control
        // is via SIGTERM (the command's \Amp\trapSignal handles clean shutdown).
        //
        // Headless-safe guard: when the NativePHP runtime is not available
        // (artisan CLI, tests, plain web server) the ChildProcess facade is not
        // bound in the container and the call silently no-ops. The class_exists
        // guard prevents a fatal BindingResolutionException in non-NativePHP contexts.
        //
        // relay:serve is NOT started automatically — the relay is opt-in (D-01).
        // Self-hosters run `php artisan relay:serve` manually or via a separate plist.
        $this->startSyncListener();

        // Pre-compile every Blade view into the runtime view cache
        // before the embedded webserver accepts its first request.
        //
        // On Windows two simultaneous Livewire requests that race to
        // compile the same uncached view both land in
        // `Illuminate\Filesystem\Filesystem::replace()`, which writes a
        // `.tmp` and then `rename()`s it atop the target cached path.
        // Windows fails the loser's rename with WinError 5 ("Access is
        // denied (code: 5)") when AV or the sibling compile holds the
        // destination open, and Laravel surfaces it as an
        // `ErrorException` from the BladeCompiler — observed in the
        // first-shipped Windows installer's production log under
        // `C:\Users\<user>\AppData\Roaming\beatrax\storage\framework\views`.
        //
        // Pre-compiling here removes the race entirely: every cached
        // `.php` is on disk before any request lands, the per-request
        // BladeCompiler short-circuits on the up-to-date mtime check,
        // and the rename-write path never runs again on the embedded
        // server. The call goes through the injected console Kernel
        // rather than a global `Artisan::call()` helper to keep the
        // facade-free DI rule intact.
        //
        // The try/catch is opportunistic: if `view:cache` fails (a
        // Blade syntax error in a single view aborts the loop and
        // throws), aborting NativePHP boot here leaves the user with
        // no app at all — surface the failure to the application log
        // and fall through to on-demand compilation, which only
        // re-exposes the race for whichever specific view the loop
        // failed on.
        $this->precompileViews();

        // Stateful native window (D-10) — width/height are the
        // first-launch defaults; `rememberState()` persists the
        // user's resize/reposition across launches via NativePHP's
        // native window-state-keeper.
        $this->windows->open('main')
            ->width(self::WINDOW_WIDTH)
            ->height(self::WINDOW_HEIGHT)
            ->rememberState();

        // Application menu (D-11). The builder composes the
        // standard top-level set with the beatrax-specific File +
        // Help entries; `Menu::create()` installs them as the
        // application menu via Electron's `Menu.setApplicationMenu()`.
        Menu::create(...$this->appMenu->build());
    }

    /**
     * Start the sync:serve artisan daemon as a persistent NativePHP ChildProcess.
     *
     * Guarded by a class_exists check on Native\Desktop\Facades\ChildProcess so
     * the call is a no-op in headless/test contexts where the NativePHP runtime
     * is not present (D-03 headless-safe invariant).
     *
     * The relay is intentionally NOT started here — relay:serve is opt-in (D-01).
     */
    private function startSyncListener(): void
    {
        if (! class_exists(ChildProcess::class)) {
            // No NativePHP runtime — skip (headless, tests, plain web server).
            return;
        }

        try {
            // persistent: true = NativePHP auto-restarts the process on crash (T-13-15).
            // The command handles SIGTERM/SIGINT via \Amp\trapSignal for clean shutdown.
            ChildProcess::artisan(
                'sync:serve --port='.self::SYNC_PORT,
                'sync-listener',
                null,
                true, // persistent
            );
        } catch (Throwable $e) {
            // Non-fatal: the sync listener failing to start does not prevent the
            // main window from opening. Log and continue — the user can still use
            // the app; sync will be unavailable until the next launch.
            $this->logger->warning('NativePHP boot: failed to start sync:serve child process.', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Warm the Blade view cache before the embedded webserver takes its
     * first request, retrying once on a transient failure.
     *
     * The Windows WinError 5 ("Access is denied (code: 5)") that aborts
     * `Filesystem::replace()`'s `rename()` is almost always transient: AV
     * (or a sibling compile) holds the destination open for a few
     * milliseconds and releases it. A single immediate retry recompiles
     * only the views the first pass left uncached — every view that did
     * land short-circuits on its up-to-date mtime check — so the retry is
     * cheap and usually clears a transient lock. After two failed passes
     * the build falls through to on-demand compilation rather than
     * stranding NativePHP boot with no app at all.
     */
    private function precompileViews(): void
    {
        foreach ([1, 2] as $attempt) {
            try {
                $this->console->call('view:cache');

                return;
            } catch (Throwable $e) {
                $this->logger->warning(
                    "NativePHP boot: view:cache attempt {$attempt} failed",
                    ['exception' => $e],
                );
            }
        }

        $this->logger->warning(
            'NativePHP boot: view:cache exhausted retries; views will compile on demand',
        );
    }
}
