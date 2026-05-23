<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal;

use Modules\Desktop\Internal\Native\AppMenuBuilder;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;
use Native\Desktop\Contracts\WindowManager;
use Native\Desktop\Facades\Menu;

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
 * application window that renders the diederik web UI (D-10: size +
 * position persist via `WindowManager::open()`'s `rememberState()`) and
 * installs the app menu (D-11 — File/Edit/View/Window/Help + the
 * diederik-specific File and Help entries).
 *
 * The macOS menu-bar tray icon (D-09) is intentionally NOT installed
 * through NativePHP's `MenuBar` facade. That facade is a wrapper around
 * the npm `menubar` library which produces a popover-style menubar app
 * whose context-menu items couple to the focused BrowserWindow (the
 * `link`-type items in the Electron `compileMenu` helper early-return
 * when no window is focused), so once the user closes the main window
 * via the X button the tray's "Open diederik" item silently does
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
final class NativeAppServiceProvider
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

    public function __construct(
        private readonly WindowManager $windows,
        private readonly AppMenuBuilder $appMenu,
        private readonly FirstLaunchBootstrap $bootstrap,
    ) {}

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

        // Stateful native window (D-10) — width/height are the
        // first-launch defaults; `rememberState()` persists the
        // user's resize/reposition across launches via NativePHP's
        // native window-state-keeper.
        $this->windows->open('main')
            ->width(self::WINDOW_WIDTH)
            ->height(self::WINDOW_HEIGHT)
            ->rememberState();

        // Application menu (D-11). The builder composes the
        // standard top-level set with the diederik-specific File +
        // Help entries; `Menu::create()` installs them as the
        // application menu via Electron's `Menu.setApplicationMenu()`.
        Menu::create(...$this->appMenu->build());
    }
}
