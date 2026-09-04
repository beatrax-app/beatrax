<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Public\Contracts\CurrentUser;

final readonly class AppLockLiveness
{
    public function __construct(
        private AppLockClientConfig $lockConfig,
        private CurrentUser $currentUser,
    ) {}

    // The authoritative half of the never-lock-a-lockless-user gate: lock.js
    // checks too, but a stale tab must not reach past it onto a PIN pad no PIN
    // opens. Both lifecycle endpoints ask the same question, so it is written
    // once — the two answers drifting apart is the bug this prevents.
    public function isArmed(): bool
    {
        return $this->currentUser->isAuthenticated()
            && $this->lockConfig->isEnabled($this->currentUser->user()->id);
    }
}
