<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Native\Desktop\Contracts\MenuItem;
use Native\Desktop\Facades\Menu;

/**
 * Builds the diederik application menu (D-11).
 *
 * Composes the standard top-level set — App (macOS) / File / Edit /
 * View / Window / Help — and appends the diederik-specific entries on
 * the File and Help submenus per UI-SPEC verbatim copy:
 *
 *   File → "Import file…", "Scan email now"
 *   Help → "GitHub repo", "Report an issue", "About diederik"
 *
 * The labels are locked verbatim from the UI-SPEC Copywriting
 * Contract; the internal navigation targets are existing app routes
 * (`imports.new`, `inboxes.index`, `settings`) and the two outbound
 * links open in the external browser via NativePHP's
 * `openInBrowser()` flag so the webview never leaves the app.
 *
 * `build()` returns the top-level `MenuItem`s in display order; the
 * `NativeAppServiceProvider` hands them to `Menu::create(...)` which
 * is the canonical NativePHP installation entry point. The class is
 * on the facade allow-list (BoundaryArchTest + phpstan.neon) because
 * the `Native\Desktop\Facades\Menu` builder is NativePHP's only path
 * to compose menu items.
 */
final class AppMenuBuilder
{
    /**
     * Verbatim D-11 / UI-SPEC labels for the diederik-specific menu
     * entries. Pulled into named constants so a future copy edit
     * lands in one place and the test assertions can reference the
     * same source of truth.
     */
    public const FILE_IMPORT = 'Import file…';

    public const FILE_SCAN_EMAIL = 'Scan email now';

    public const HELP_GITHUB_REPO = 'GitHub repo';

    public const HELP_REPORT_ISSUE = 'Report an issue';

    public const HELP_ABOUT = 'About diederik';

    /**
     * Public repository URL — surfaces from the Help menu. The link
     * opens in the external browser (not inside the Electron
     * webview) via the NativePHP `openInBrowser()` flag.
     */
    public const GITHUB_REPO_URL = 'https://github.com/diederik-app/diederik';

    /**
     * Issue-tracker URL — same external-browser policy as the repo
     * link.
     */
    public const REPORT_ISSUE_URL = 'https://github.com/diederik-app/diederik/issues/new';

    /**
     * @return list<MenuItem>
     */
    public function build(): array
    {
        return [
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
                // "About diederik" routes to the Settings page where
                // app metadata + version surface. A dedicated `/about`
                // route is out of this phase's scope; SC3 routing
                // caveat applies (route to the surface that owns the
                // info today).
                Menu::route('settings', self::HELP_ABOUT),
            ),
        ];
    }
}
