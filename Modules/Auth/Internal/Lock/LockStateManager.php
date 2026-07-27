<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Contracts\KeyCustodian;

/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
final class LockStateManager
{
    public const SESSION_KEY = 'beatrax_locked';

    public const DATA_KEY_SESSION = 'beatrax_data_key';

    /**
     * @param  KeyCustodian  $custodian  Defaults to the pass-through
     *                                   NullKeyCustodian so `new LockStateManager` (used widely in tests)
     *                                   keeps its pre-custody behaviour; the container binds the
     *                                   platform-appropriate custodian for production resolution.
     */
    public function __construct(
        private readonly KeyCustodian $custodian = new NullKeyCustodian,
    ) {}

    // An absent SESSION_KEY is treated as unlocked, which covers both the
    // "no PIN set up" and "fresh session" cases.
    public function isLocked(Session $session): bool
    {
        return (bool) $session->get(self::SESSION_KEY, false);
    }

    public function lock(Session $session): void
    {
        // Ask the custodian to release any external state the held handle
        // refers to (a no-op on web/desktop; a Keychain delete on mobile)
        // before forgetting the handle.
        $handle = $session->get(self::DATA_KEY_SESSION);
        if (is_string($handle)) {
            $this->custodian->forget($handle);
        }

        $session->put(self::SESSION_KEY, true);
        $session->forget(self::DATA_KEY_SESSION);
    }

    // Releases a lock flag stranded on a session whose user has no enabled
    // lock, which no PIN or biometric could ever clear. Stores no data key:
    // such a user never had one, so the session is left in exactly the shape
    // a fresh sign-in produces.
    public function clearStaleLock(Session $session): void
    {
        $session->put(self::SESSION_KEY, false);
        $session->forget(self::DATA_KEY_SESSION);
    }

    // The caller (typically PinVerificationService) is responsible for
    // sodium_memzero()-ing its local copy of $dataKey after calling this.
    public function unlock(Session $session, string $dataKey): void
    {
        $session->put(self::SESSION_KEY, false);
        $session->put(self::DATA_KEY_SESSION, $this->custodian->store($dataKey));
    }

    // The single sanctioned reader every consumer of the held key must go
    // through, so the custodian's read() is always applied -- reading
    // DATA_KEY_SESSION directly would yield the opaque handle, not the key,
    // on the desktop / mobile bundles.
    public function heldKey(Session $session): ?string
    {
        $handle = $session->get(self::DATA_KEY_SESSION);

        return is_string($handle) ? $this->custodian->read($handle) : null;
    }
}
