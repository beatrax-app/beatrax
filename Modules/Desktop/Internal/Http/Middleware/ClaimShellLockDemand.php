<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Desktop\Internal\Native\ShellHandoff;
use Symfony\Component\HttpFoundation\Response;

// The other half of ShellHandoff, and the only place a lock demanded by a
// hidden or closed window can actually be engaged: this runs on the window's
// own request, so the session it withholds against is the one the reader holds.
final readonly class ClaimShellLockDemand
{
    public function __construct(
        private ShellHandoff $handoff,
        private CurrentUser $currentUser,
        private AppLockClientConfig $lockConfig,
        private AppLockKeyService $keyService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->claim($request);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    // Left waiting while nobody is signed in: spending the demand on the login
    // screen's session, which holds no key, would let the reader who signs in
    // next inherit the window that closed without ever being asked.
    private function claim(Request $request): void
    {
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        if ($this->handoff->take(ShellHandoff::LOCK_DEMANDED) === null) {
            return;
        }

        // Only where a lock exists to engage: withholding on an account with no
        // app lock drops the data key and leaves nothing that can hand it back.
        if ($this->lockConfig->isEnabled($this->currentUser->user()->id)) {
            $this->keyService->withhold($request->session());
        }
    }
}
