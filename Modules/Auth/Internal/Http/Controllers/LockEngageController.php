<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Response;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;

// lock.js posts here with keepalive, not sendBeacon: a beacon cannot set
// headers, so VerifyCsrfToken would 419 it every time.
final readonly class LockEngageController
{
    private const int UNLOCK_GRACE_SECONDS = 10;

    public function __construct(
        private AppLockKeyService $keyService,
        private AppLockClientConfig $lockConfig,
        private CurrentUser $currentUser,
        private Clock $clock,
    ) {}

    public function __invoke(Session $session): Response
    {
        // The authoritative half of the never-lock-a-lockless-user gate:
        // lock.js checks too, but a stale tab must not reach past it onto a
        // PIN pad no PIN opens.
        if (! $this->currentUser->isAuthenticated()
            || ! $this->lockConfig->isEnabled($this->currentUser->user()->id)) {
            return new Response('', 204);
        }

        // A keepalive engage does not wait, so it can land after the user has
        // already unlocked. Recent activity wins over a request in flight,
        // or they are asked for a second PIN.
        if ($this->unlockedWithinGrace()) {
            return new Response('', 204);
        }

        // withhold() on an already-locked session is a no-op, which is what
        // makes this allow-listed route safe to race.
        $this->keyService->withhold($session);

        return new Response('', 204);
    }

    // Covers an in-flight request plus the unlock round trip, and stays far
    // short of the shortest configurable idle timeout.
    private function unlockedWithinGrace(): bool
    {
        $lastActivity = $this->lockConfig->lastActivityAt($this->currentUser->user()->id);

        if ($lastActivity === null) {
            return false;
        }

        return $lastActivity->greaterThan($this->clock->now()->subSeconds(self::UNLOCK_GRACE_SECONDS));
    }
}
