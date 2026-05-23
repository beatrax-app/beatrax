<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal;

use Modules\Desktop\Internal\Native\AppMenuBuilder;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;
use Modules\Desktop\Internal\Native\TrayMenuBuilder;
use Native\Desktop\Contracts\WindowManager;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\MenuBar;

/**
 * The NativePHP-booted application provider.
 *
 * NativePHP resolves this class from the `provider` key in
 * `config/nativephp.php` (via `app(config('nativephp.provider'))`) and
 * calls `boot()` once the native shell has started. Because the class is
 * resolved through the container, its constructor dependencies are
 * autowired — the WindowManager contract + the two menu builders ride
 * through constructor DI so the project's "no facade calls in module
 * code" rule stays intact for the builder construction itself.
 *
 * The provider IS on the facade allow-list (BoundaryArchTest +
 * phpstan.neon) because the `Menu::create()` and `MenuBar::create()`
 * facade calls cannot be re-routed: NativePHP wires the menu / tray
 * via those facades alone, and there is no container-bound
 * alternative path. The crossing stays confined to this one file plus
 * the two builders.
 *
 * `boot()` runs the first-launch DB bootstrap (D-21 / D-22 / D-23)
 * before any window opens so the schema is in place for the very
 * first request the just-opened window makes, then opens the single
 * application window that renders the diederik web UI (D-10: size +
 * position persist via `WindowManager::open()`'s `rememberState()`),
 * installs the app menu (D-11 — File/Edit/View/Window/Help + the
 * diederik-specific File and Help entries), and the system-tray icon
 * (D-09 — left-click toggles window show/hide, right-click shows the
 * three-row context menu). The tray icon is the monochrome/template
 * image at `resources/brand/tray-icon.png` so the OS tints it
 * natively for the active menu-bar theme (D-19).
 */
final class NativeAppServiceProvider
{
    /**
     * Default window dimensions. Pulled into named constants so the
     * sizing decision is reviewable in one place and `rememberState()`
     * uses these as the first-launch fallback before the persisted
     * geometry takes over.
     */
    private const WINDOW_WIDTH = 1100;

    private const WINDOW_HEIGHT = 800;

    /**
     * Relative path under `resources/` for the monochrome system-tray
     * icon. The actual icon is committed by plan 15-05 — the
     * `resource_path()` resolution happens at provider boot, after
     * the brand-assets prebuild hook has staged the file.
     */
    private const TRAY_ICON_PATH = 'brand/tray-icon.png';

    public function __construct(
        private readonly WindowManager $windows,
        private readonly AppMenuBuilder $appMenu,
        private readonly TrayMenuBuilder $trayMenu,
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

        // System-tray icon (D-09). The monochrome template image
        // (D-19) lives at `resources/brand/tray-icon.png`. The
        // tray's right-click context menu is the three-row D-09
        // composition; left-click toggles the main window (default
        // NativePHP tray behavior).
        MenuBar::create()
            ->icon(resource_path(self::TRAY_ICON_PATH))
            ->withContextMenu($this->trayMenu->build());
    }
}
