<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Native\Desktop\Facades\Menu;
use Native\Desktop\Menu\Menu as NativeMenu;

/**
 * Builds the system-tray right-click context menu (D-09).
 *
 * The plan locks the verbatim three rows in exact order:
 *
 *   "Open diederik"   — focuses the main window (navigates to /)
 *   "Scan email now"  — navigates to the inboxes page where the
 *                       per-inbox "Scan now" button drives the sync
 *                       (SC3 routing caveat — route to the user-
 *                       facing flow that owns the action)
 *   "Quit"            — quits the application via the NativePHP Quit
 *                       role (drives `app.quit()` on the Electron
 *                       side, gracefully tearing down the supervised
 *                       child processes)
 *
 * The class is on the facade allow-list (BoundaryArchTest +
 * phpstan.neon) because the `Native\Desktop\Facades\Menu` builder is
 * NativePHP's only path to compose menu items. The MenuBar facade
 * call itself lives in `NativeAppServiceProvider::boot()` —
 * `Menu::make(...)` here returns the bare context menu so the
 * provider can hand it to `MenuBar::create()->...->withContextMenu(...)`.
 */
final class TrayMenuBuilder
{
    /**
     * Verbatim D-09 / UI-SPEC labels for the tray context menu.
     * Pulled into named constants so a future copy edit lands in
     * one place and the test assertions can reference the same
     * source of truth.
     */
    public const OPEN_DIEDERIK = 'Open diederik';

    public const SCAN_EMAIL_NOW = 'Scan email now';

    public const QUIT = 'Quit';

    public function build(): NativeMenu
    {
        return Menu::make(
            // Left-click on the tray icon toggles the window
            // show/hide natively. This row gives an explicit "Open"
            // affordance from the right-click menu — it navigates
            // the main window back to the dashboard.
            Menu::route('dashboard', self::OPEN_DIEDERIK),
            Menu::route('inboxes.index', self::SCAN_EMAIL_NOW),
            // The Quit role uses the NativePHP Quit primitive
            // directly with the verbatim "Quit" label (the default
            // role label is "Quit {app name}", so an explicit label
            // overrides it to the locked D-09 copy).
            Menu::quit(self::QUIT),
        );
    }
}
