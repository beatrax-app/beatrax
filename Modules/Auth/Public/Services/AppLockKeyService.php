<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\LockStateManager;

/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
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

    // Callers MUST NOT pass a key derived from a bypassable signal (a bare
    // bool prompt) -- the key's provenance is the trust gate, and only a
    // real secure-enclave recovery may produce $dataKey here.
    public function admitDataKey(Session $session, string $dataKey): void
    {
        $this->lockState->unlock($session, $dataKey);
    }
}
