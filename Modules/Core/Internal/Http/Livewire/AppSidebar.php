<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Persistent left sidebar (Phase 16 D-05). Rendered from
 * `layouts/app.blade.php` within an `@auth` guard so unauthenticated
 * pages (login, error pages) do not see it.
 *
 * Replaces the previous TopNav horizontal row with a sectioned,
 * fixed-width sidebar (248px in the main app). Section labels group
 * sibling nav items into THIS MONTH / MONEY / INGESTION / SETTINGS so
 * navigation is scannable at a glance.
 *
 * The Dev block at the foot is gated server-side on
 * `users.is_developer` — non-developers do NOT receive the dashed
 * container in the rendered HTML. The block carries a pulsing emerald
 * `.dot-live` dot, an "Open Dev Console" link with a `⌘.` kbd hint,
 * and a "Queue 0 · Worker —" placeholder pulse row. The live numbers
 * are intentionally static in this plan; 16-04 + 16-06 wire the real
 * `cache('dev_mode.queue_worker_heartbeat')` reads when those surfaces
 * land.
 *
 * The account caption reads "developer · local" for developers and
 * "local" otherwise — the only place in the chrome that reveals the
 * caller's developer status to themselves.
 *
 * Active-link highlighting reads the current path from the injected
 * `Request` rather than the `request()` helper so the component stays
 * clean under the project's DI-only invariant.
 */
final class AppSidebar extends Component
{
    public function render(
        CurrentUser $currentUser,
        Request $request,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $isDeveloper = $user->is_developer === true;

        return $views->make('core::livewire.app-sidebar', [
            'currentPath' => '/'.ltrim($request->path(), '/'),
            'username' => $user->username,
            'userInitial' => $this->initialFor($user->username),
            'isDeveloper' => $isDeveloper,
            'accountCaption' => $isDeveloper ? 'developer · local' : 'local',
        ]);
    }

    /**
     * First alphanumeric character of the username, uppercased. Falls
     * back to "?" when the username is empty (defensive — schema
     * forbids empty usernames). Drives the gradient `.avatar` initial.
     */
    private function initialFor(string $username): string
    {
        $trimmed = ltrim($username);

        if ($trimmed === '') {
            return '?';
        }

        return mb_strtoupper(mb_substr($trimmed, 0, 1));
    }
}
