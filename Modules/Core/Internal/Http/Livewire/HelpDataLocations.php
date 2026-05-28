<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * User-facing "Where is my data?" page reachable at /help/data-locations.
 *
 * Surfaces the three load-bearing on-disk paths the local-only privacy
 * promise depends on (SQLite database, OAuth secrets, framework
 * caches) by reading them from `UserDataPathService` — the single
 * source of truth that respects the `NATIVEPHP_STORAGE_PATH` retarget
 * for packaged builds. Each path renders with a copy-to-clipboard
 * affordance so users can paste the path into a file manager or
 * terminal without retyping.
 *
 * Section 2 ("Export everything") branches on the authenticated
 * user's `is_developer` flag: developers see the Dev Mode export
 * action as a primary CTA; everyone else reads the instructional
 * fallback that walks them through enabling Dev Mode or copying the
 * folders manually.
 *
 * No constructor DI — phpstan-strict-rules bans it on Livewire
 * Component subclasses. Method-parameter DI on `render()` instead, in
 * the same shape as SystemAlertsBanner. The class holds zero
 * properties so the component is stateless across re-renders; every
 * navigation resolves a fresh path snapshot through the injected
 * services.
 *
 * The component reads the developer flag from the authenticated user
 * via `CurrentUser` exclusively — never from a request-supplied user
 * id — so cross-user bleed is structurally impossible.
 */
final class HelpDataLocations extends Component
{
    public function render(
        UserDataPathService $paths,
        CurrentUser $currentUser,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        return $views->make('core::livewire.help.data-locations', [
            'sqlitePath' => $paths->databasePath(),
            'secretsPath' => $paths->secrets(),
            'cachesPath' => $paths->framework(),
            'devModeOn' => $user->is_developer === true,
        ]);
    }
}
