<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Services\DevConsoleBuildGate;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\ProjectLinks;
use Native\Desktop\Contracts\MenuItem;
use Native\Desktop\Facades\Menu;

final readonly class AppMenuBuilder
{
    public function __construct(
        private CurrentUser $currentUser,
        private DevConsoleBuildGate $console,
    ) {}

    // Menu::create() replaces the whole menu, and the boot-time call runs from
    // POST /_native/api/booted — a route with no session, so the entries that
    // depend on who is signed in have to be re-applied when that changes.
    public function install(): void
    {
        Menu::create(...$this->build());
    }

    /**
     * @return list<MenuItem>
     */
    public function build(): array
    {
        // Every menu that owns its entries is a SubmenuItem, never Menu::file(),
        // Menu::help() or Menu::label(): the shell strips a role's submenu and
        // types a label `normal`, and both render as no menu at all.
        // See .docs/features/desktop/architecture.md — "Submenus never hang off a role".
        $items = [
            Menu::app(),
            new SubmenuItem(
                Lang::get('desktop::native.menu.file'),
                Menu::route(Destination::Imports->routeName(), Lang::get('desktop::native.menu.file_import')),
                Menu::route(Destination::Email->routeName(), Lang::get('desktop::native.menu.file_scan_email')),
            ),
            Menu::edit(),
            Menu::view(),
            Menu::window(),
            new SubmenuItem(
                Lang::get('desktop::native.menu.help'),
                Menu::link(ProjectLinks::REPO_URL, Lang::get('desktop::native.menu.help_github_repo'))->openInBrowser(),
                Menu::link(ProjectLinks::NEW_ISSUE_URL, Lang::get('desktop::native.menu.help_report_issue'))->openInBrowser(),
                Menu::route(Destination::Settings->routeName(), Lang::get('desktop::native.menu.help_about')),
            ),
        ];

        if ($this->isDeveloper()) {
            // No accelerator on "Run a command": one would let the OS menu swallow ⌘K
            // before the body-level keybind handler dispatches palette:open.
            $items[] = new SubmenuItem(
                Lang::get('desktop::native.menu.developer_submenu'),
                Menu::route('dev.overview', Lang::get('desktop::native.menu.dev_open_console'))->accelerator('Cmd+.'),
                Menu::route('dev.overview', Lang::get('desktop::native.menu.dev_run_command')),
            );
        }

        return $items;
    }

    // Cmd+. is an OS accelerator: on a shipped build it would carry the user
    // to an address that answers 404, which is worse than an absent menu.
    private function isDeveloper(): bool
    {
        if (! $this->console->permits() || ! $this->currentUser->isAuthenticated()) {
            return false;
        }

        return $this->currentUser->user()->is_developer === true;
    }
}
