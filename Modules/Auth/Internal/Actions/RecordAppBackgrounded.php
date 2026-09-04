<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Actions;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\AppLockLiveness;
use Modules\Core\Public\Contracts\Clock;

final readonly class RecordAppBackgrounded
{
    public function __construct(
        private AppLockLiveness $liveness,
        private Clock $clock,
    ) {}

    public function __invoke(Session $session): void
    {
        if ($this->liveness->isArmed()) {
            $session->put(AppLockMiddleware::SESSION_BACKGROUNDED_AT, $this->clock->now()->getTimestamp());
        }
    }
}
