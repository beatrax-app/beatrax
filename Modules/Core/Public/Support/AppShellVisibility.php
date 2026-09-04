<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Navigation\PreSetupSurface;
use Throwable;

/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-pre-setup-screen-renders-the-application-shell
 */

// Whether the page may draw the navigation menubar and the search affordance.
// Being signed in is not the same question as having an application to
// navigate: the whole of signup, setup and provisioning happens signed in, and
// the layout answered the first question while drawing for the second.
final readonly class AppShellVisibility
{
    public function __construct(
        private CurrentUser $currentUser,
        private Container $container,
    ) {}

    public function visible(): bool
    {
        return $this->currentUser->isAuthenticated() && ! PreSetupSurface::covers($this->routeName());
    }

    private function routeName(): ?string
    {
        if (! $this->container->bound(Request::class)) {
            return null;
        }

        try {
            $route = $this->container->make(Request::class)->route();
        } catch (Throwable) {
            // Console contexts and test doubles hand back something that is not
            // a Request at all. No route name is the safe answer: it leaves the
            // shell visible for a signed-in reader, which is the status quo.
            return null;
        }

        return $route instanceof Route ? $route->getName() : null;
    }
}
