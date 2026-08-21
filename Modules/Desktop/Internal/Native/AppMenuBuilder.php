<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Native\Desktop\Contracts\MenuItem;
use Native\Desktop\Facades\Menu;

// The const labels are the English canonical, mirrored in desktop::native.menu.*.
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
                // No scan-now route exists; the inboxes page hosts the per-inbox button.
                Menu::route('inboxes.index', Lang::get('desktop::native.menu.file_scan_email')),
            ),
            Menu::edit(),
            Menu::view(),
            Menu::window(),
            Menu::help()->submenu(
                Menu::link(self::GITHUB_REPO_URL, Lang::get('desktop::native.menu.help_github_repo'))->openInBrowser(),
                Menu::link(self::REPORT_ISSUE_URL, Lang::get('desktop::native.menu.help_report_issue'))->openInBrowser(),
                // No /about route exists; Settings already surfaces version + metadata.
                Menu::route('settings', Lang::get('desktop::native.menu.help_about')),
            ),
        ];

        if ($this->isDeveloper()) {
            // No accelerator on "Run a command": one would let the OS menu swallow ⌘K
            // before the body-level keybind handler dispatches palette:open.
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
