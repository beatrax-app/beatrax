<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Contracts\CurrentUser;
use Native\Desktop\Contracts\MenuItem;
use Native\Desktop\Facades\Menu;

/**
 * Builds the beatrax application menu.
 *
 * Composes the standard top-level set — App (macOS) / File / Edit /
 * View / Window / Help — and appends the beatrax-specific entries on
 * the File and Help submenus per UI-SPEC verbatim copy:
 *
 *   File → "Import file…", "Scan email now"
 *   Help → "GitHub repo", "Report an issue", "About beatrax"
 *
 * 16-08 adds a conditional Developer submenu for `is_developer` users:
 *
 *   Developer → "Open Dev Console" (⌘.) + "⌘K Run a command" (⌘K)
 *
 * The submenu is omitted from the menu tree entirely for non-developer
 * accounts — defence-in-depth on top of the EnsureDeveloperMode gate
 * on the underlying routes (T-16-36).
 *
 * The labels are locked verbatim from the UI-SPEC Copywriting
 * Contract; the internal navigation targets are existing app routes
 * (`imports.new`, `inboxes.index`, `settings`, `dev.overview`) and the
 * two outbound links open in the external browser via NativePHP's
 * `openInBrowser()` flag so the webview never leaves the app.
 *
 * `build()` returns the top-level `MenuItem`s in display order; the
 * `NativeAppServiceProvider` hands them to `Menu::create(...)` which
 * is the canonical NativePHP installation entry point. The class is
 * on the facade allow-list (BoundaryArchTest + phpstan.neon) because
 * the `Native\Desktop\Facades\Menu` builder is NativePHP's only path
 * to compose menu items.
 *
 * RESEARCH Pitfall 9 reminder: NativePHP app-menu changes do NOT
 * appear in a running Electron build until the user fully quits and
 * relaunches the app. PHP autoreload does not propagate to the
 * already-compiled native menu. Documented in 16-08-SUMMARY.md.
 */
final class AppMenuBuilder
{
    /**
     * Verbatim UI-SPEC labels for the beatrax-specific menu
     * entries. Pulled into named constants so a future copy edit
     * lands in one place and the test assertions can reference the
     * same source of truth.
     */
    public const FILE_IMPORT = 'Import file…';

    public const FILE_SCAN_EMAIL = 'Scan email now';

    public const HELP_GITHUB_REPO = 'GitHub repo';

    public const HELP_REPORT_ISSUE = 'Report an issue';

    public const HELP_ABOUT = 'About beatrax';

    public const DEVELOPER_SUBMENU = 'Developer';

    public const DEV_OPEN_CONSOLE = 'Open Dev Console';

    public const DEV_RUN_COMMAND = '⌘K Run a command';

    /**
     * Public repository URL — surfaces from the Help menu. The link
     * opens in the external browser (not inside the Electron
     * webview) via the NativePHP `openInBrowser()` flag.
     */
    public const GITHUB_REPO_URL = 'https://github.com/beatrax-app/beatrax';

    /**
     * Issue-tracker URL — same external-browser policy as the repo
     * link.
     */
    public const REPORT_ISSUE_URL = 'https://github.com/beatrax-app/beatrax/issues/new';

    /**
     * Constructor-DI on CurrentUser (16-08). The container resolves
     * this builder as a singleton in DesktopServiceProvider; on every
     * call to build() the CurrentUser collaborator reads the live
     * auth session so a partner account that signs in after the
     * shipped Electron process started would correctly see no
     * Developer submenu on next menu rebuild.
     *
     * Note: NativePHP composes the menu once at boot — there is no
     * built-in rebuild trigger on auth state change. The constructor
     * still takes CurrentUser through DI so build() reflects whoever
     * is authenticated when the menu IS rebuilt (full app relaunch
     * per RESEARCH Pitfall 9).
     */
    public function __construct(
        private readonly CurrentUser $currentUser,
    ) {}

    /**
     * @return list<MenuItem>
     */
    public function build(): array
    {
        $items = [
            Menu::app(),
            Menu::file()->submenu(
                Menu::route('imports.new', self::FILE_IMPORT),
                // "Scan email now" navigates to the inboxes page where
                // the per-inbox "Scan now" button drives the actual
                // sync. SC3 routing caveat: route to the user-facing
                // flow that owns the action, not the verb literally.
                Menu::route('inboxes.index', self::FILE_SCAN_EMAIL),
            ),
            Menu::edit(),
            Menu::view(),
            Menu::window(),
            Menu::help()->submenu(
                Menu::link(self::GITHUB_REPO_URL, self::HELP_GITHUB_REPO)->openInBrowser(),
                Menu::link(self::REPORT_ISSUE_URL, self::HELP_REPORT_ISSUE)->openInBrowser(),
                // "About beatrax" routes to the Settings page where
                // app metadata + version surface. A dedicated `/about`
                // route is out of this phase's scope; SC3 routing
                // caveat applies (route to the surface that owns the
                // info today).
                Menu::route('settings', self::HELP_ABOUT),
            ),
        ];

        if ($this->isDeveloper()) {
            // Route-name based (NOT hardcoded URL host) so the menu
            // works in both Herd dev (https://beatrax.test) AND shipped
            // Electron bundles (http://127.0.0.1:{port}). Menu::route
            // resolves the URL through the live UrlGenerator at boot
            // time. The accelerator strings (Cmd+.) are interpreted by
            // Electron verbatim on macOS and Windows/Linux.
            //
            // "Run a command" carries the ⌘K visual hint in its label
            // but does NOT register an OS-menu accelerator: an
            // accelerator on this item would let the OS menu intercept
            // ⌘K before the body-level keybind handler dispatches
            // palette:open, navigating to /dev instead of opening the
            // palette. The body-level handler in the Blade layouts
            // owns the actual keybind.
            $items[] = Menu::label(self::DEVELOPER_SUBMENU)->submenu(
                Menu::route('dev.overview', self::DEV_OPEN_CONSOLE)->accelerator('Cmd+.'),
                Menu::route('dev.overview', self::DEV_RUN_COMMAND),
            );
        }

        return $items;
    }

    /**
     * Resolve the current user's developer flag. Returns false when
     * no user is authenticated — defence in depth so a partial
     * NativePHP boot (menu composed before the auth guard is wired)
     * still renders a safe non-developer menu.
     */
    private function isDeveloper(): bool
    {
        if (! $this->currentUser->isAuthenticated()) {
            return false;
        }

        return $this->currentUser->user()->is_developer === true;
    }
}
