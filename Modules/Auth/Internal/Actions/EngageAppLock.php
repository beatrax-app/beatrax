<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Actions;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\AppLockLiveness;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;

final readonly class EngageAppLock
{
    private const int UNLOCK_GRACE_SECONDS = 10;

    public function __construct(
        private AppLockKeyService $keyService,
        private AppLockClientConfig $lockConfig,
        private AppLockLiveness $liveness,
        private CurrentUser $currentUser,
        private Clock $clock,
    ) {}

    public function __invoke(Session $session): void
    {
        if (! $this->liveness->isArmed()) {
            return;
        }

        // A keepalive engage does not wait, so it can land after the user has
        // already unlocked. Recent activity wins over a request in flight,
        // or they are asked for a second PIN.
        if ($this->unlockedWithinGrace()) {
            return;
        }

        // withhold() on an already-locked session is a no-op, which is what
        // makes the route in front of this safe to race.
        $this->keyService->withhold($session);
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
