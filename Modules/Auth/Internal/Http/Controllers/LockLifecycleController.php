<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Response;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;

// The two ends of a backgrounding, reported by lock.js as they happen. Its
// own 30s timer cannot be trusted on mobile — a suspended WebView never fires
// it, and the return handler then cancelled it outright, so the phone came
// back unlocked. AppLockMiddleware judges the elapsed time from these instead.
/**
 * @link ../../../../../.docs/features/auth/architecture.md
 */
final readonly class LockLifecycleController
{
    public function __construct(
        private AppLockClientConfig $lockConfig,
        private CurrentUser $currentUser,
        private Clock $clock,
    ) {}

    // Stamps the moment the app left the foreground. Gated on the lock being
    // enabled for the same reason LockEngageController is: a marker written
    // for a user with no lock would strand them on a PIN pad no PIN opens.
    public function background(Session $session): Response
    {
        if ($this->currentUser->isAuthenticated()
            && $this->lockConfig->isEnabled($this->currentUser->user()->id)) {
            $session->put(AppLockMiddleware::SESSION_BACKGROUNDED_AT, $this->clock->now()->getTimestamp());
        }

        return new Response('', 204);
    }

    // Reaching this body at all means the middleware judged the return to be
    // within grace; an expired one is redirected to the lock screen before it
    // gets here, which is the signal lock.js reloads on.
    public function resume(): Response
    {
        return new Response('', 204);
    }
}
