<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Native\Desktop\Contracts\MenuItem;
use Native\Desktop\Facades\Menu;

// Composes the standard App/File/Edit/View/Window/Help menu plus the
// beatrax entries; the Developer submenu is appended only for is_developer
// accounts. Visible labels are the English canonical (mirrored in
// desktop::native.menu.*); build() renders each through Lang::get.
final class AppMenuBuilder
{
    public const FILE_IMPORT = 'Import file…';

    public const FILE_SCAN_EMAIL = 'Scan email now';

    public const HELP_GITHUB_REPO = 'GitHub repo';

    public const HELP_REPORT_ISSUE = 'Report an issue';

    public const HELP_ABOUT = 'About Beatrax';

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
                Menu::route('imports.new', Lang::get('desktop::native.menu.file_import')),
                // "Scan email now" routes to the inboxes page, where
                // the per-inbox "Scan now" button drives the actual
                // sync.
                Menu::route('inboxes.index', Lang::get('desktop::native.menu.file_scan_email')),
            ),
            Menu::edit(),
            Menu::view(),
            Menu::window(),
            Menu::help()->submenu(
                Menu::link(self::GITHUB_REPO_URL, Lang::get('desktop::native.menu.help_github_repo'))->openInBrowser(),
                Menu::link(self::REPORT_ISSUE_URL, Lang::get('desktop::native.menu.help_report_issue'))->openInBrowser(),
                // "About Beatrax" routes to Settings, where app
                // metadata + version already surface; no dedicated
                // /about route exists.
                Menu::route('settings', Lang::get('desktop::native.menu.help_about')),
            ),
        ];

        if ($this->isDeveloper()) {
            // "Run a command" carries the ⌘K visual hint in its label
            // but registers no OS-menu accelerator: an accelerator
            // here would let the OS menu intercept ⌘K before the
            // body-level keybind handler dispatches palette:open.
            $items[] = Menu::label(Lang::get('desktop::native.menu.developer_submenu'))->submenu(
                Menu::route('dev.overview', Lang::get('desktop::native.menu.dev_open_console'))->accelerator('Cmd+.'),
                Menu::route('dev.overview', Lang::get('desktop::native.menu.dev_run_command')),
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
