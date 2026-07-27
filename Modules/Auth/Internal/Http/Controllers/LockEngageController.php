<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Response;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\CurrentUser;

// lock.js posts here via fetch with keepalive:true, not
// navigator.sendBeacon: sendBeacon cannot set headers, and
// VerifyCsrfToken only accepts the token from a _token body field or
// the X-CSRF-TOKEN/X-XSRF-TOKEN headers -- a beacon would always 419.
/**
 * @link ../../../../../.docs/features/auth/architecture.md
 */
final readonly class LockEngageController
{
    public function __construct(
        private AppLockKeyService $keyService,
        private AppLockClientConfig $lockConfig,
        private CurrentUser $currentUser,
    ) {}

    public function __invoke(Session $session): Response
    {
        // Authoritative half of the never-lock-a-lockless-user gate:
        // withhold() here would strand the session on a PIN pad that no
        // PIN opens. lock.js is gated too, but a stale tab or replayed
        // request must not be able to reach past it.
        if (! $this->currentUser->isAuthenticated()
            || ! $this->lockConfig->isEnabled($this->currentUser->user()->id)) {
            return new Response('', 204);
        }

        // Idempotent: withhold() on an already-locked session is a
        // harmless no-op, so a request racing an already-locked session
        // (this route is allow-listed in AppLockMiddleware) is safe.
        $this->keyService->withhold($session);

        return new Response('', 204);
    }
}
