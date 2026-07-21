<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Contracts\CurrentUser;
use Native\Desktop\Contracts\MenuItem;
use Native\Desktop\Facades\Menu;

// Composes the standard App/File/Edit/View/Window/Help menu plus the
// beatrax-specific entries. The Developer submenu is appended only for
// is_developer accounts and omitted from the tree entirely otherwise —
// defence-in-depth on top of the route-level developer-mode gate.
final class AppMenuBuilder
{
    public const FILE_IMPORT = 'Import file…';

    public const FILE_SCAN_EMAIL = 'Scan email now';

    public const HELP_GITHUB_REPO = 'GitHub repo';

    public const HELP_REPORT_ISSUE = 'Report an issue';

    public const HELP_ABOUT = 'About beatrax';

    public const DEVELOPER_SUBMENU = 'Developer';

    public const DEV_OPEN_CONSOLE = 'Open Dev Console';

    public const DEV_RUN_COMMAND = '⌘K Run a command';

    public const GITHUB_REPO_URL = 'https://github.com/beatrax-app/beatrax';

    public const REPORT_ISSUE_URL = 'https://github.com/beatrax-app/beatrax/issues/new';

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
                // "Scan email now" routes to the inboxes page, where
                // the per-inbox "Scan now" button drives the actual
                // sync.
                Menu::route('inboxes.index', self::FILE_SCAN_EMAIL),
            ),
            Menu::edit(),
            Menu::view(),
            Menu::window(),
            Menu::help()->submenu(
                Menu::link(self::GITHUB_REPO_URL, self::HELP_GITHUB_REPO)->openInBrowser(),
                Menu::link(self::REPORT_ISSUE_URL, self::HELP_REPORT_ISSUE)->openInBrowser(),
                // "About beatrax" routes to Settings, where app
                // metadata + version already surface; no dedicated
                // /about route exists.
                Menu::route('settings', self::HELP_ABOUT),
            ),
        ];

        if ($this->isDeveloper()) {
            // "Run a command" carries the ⌘K visual hint in its label
            // but registers no OS-menu accelerator: an accelerator
            // here would let the OS menu intercept ⌘K before the
            // body-level keybind handler dispatches palette:open.
            $items[] = Menu::label(self::DEVELOPER_SUBMENU)->submenu(
                Menu::route('dev.overview', self::DEV_OPEN_CONSOLE)->accelerator('Cmd+.'),
                Menu::route('dev.overview', self::DEV_RUN_COMMAND),
            );
        }

        return $items;
    }

    private function isDeveloper(): bool
    {
        if (! $this->currentUser->isAuthenticated()) {
            return false;
        }

        return $this->currentUser->user()->is_developer === true;
    }
}
