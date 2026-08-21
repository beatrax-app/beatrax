<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\LockStateManager;

class AppLockKeyService
{
    public function __construct(
        private readonly LockStateManager $lockState,
    ) {}

    public function release(Session $session): ?string
    {
        if ($this->lockState->isLocked($session)) {
            return null;
        }

        return $this->lockState->heldKey($session);
    }

    public function withhold(Session $session): void
    {
        $this->lockState->lock($session);
    }

    // The key's provenance IS the trust gate: only a real secure-enclave
    // recovery may produce $dataKey, never a bare bool prompt.
    public function admitDataKey(Session $session, string $dataKey): void
    {
        $this->lockState->unlock($session, $dataKey);
    }
}
