<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\LockStateManager;

/**
 * Public LOCK-04 boundary: the single authorized release point for the
 * session-held data key across the module boundary.
 *
 * Phase 14 (FX encryption) calls release() to obtain the data key needed
 * to decrypt at-rest transaction fields. If the session is locked — either
 * because the user set a PIN that has expired, or because the desktop idle
 * trigger called withhold() — release() returns null and the caller must
 * surface the lock screen.
 *
 * This class is the ONLY authorized path through which the data key leaves
 * Modules\Auth. No other code should reach into LockStateManager or read
 * DATA_KEY_SESSION directly (T-05-04: no bypass path).
 *
 * Design for zero rework (D-19): the signature here matches exactly what
 * Phase 14 will consume. Do not change it without coordinating with Phase 14.
 *
 * NOTE: intentionally NOT `final` — the Sync device-identity tests
 * (Phase 12) substitute a release()-returns-null subclass to exercise the
 * D-02 weak-key-window guard. The class has no invariants that subclassing
 * could violate; it is a thin session-key accessor.
 */
class AppLockKeyService
{
    public function __construct(
        private readonly LockStateManager $lockState,
    ) {}

    /**
     * Release the data key held in the session, or null if locked.
     *
     * Returns null when:
     *   - The session is locked (lockState->isLocked() is true).
     *   - The session has no data key (e.g. no PIN enrolled, fresh boot).
     *
     * Returns the raw data-key bytes when:
     *   - The session is unlocked and a key has been stored by unlock().
     */
    public function release(Session $session): ?string
    {
        if ($this->lockState->isLocked($session)) {
            return null;
        }

        return $this->lockState->heldKey($session);
    }

    /**
     * Withhold the data key: transition the session to the locked state.
     *
     * Delegates to LockStateManager->lock() which sets the lock flag and
     * forgets the data key from the session. Called by:
     *   - The desktop idle trigger (NativePHP WindowHidden event, Plan 05-06)
     *   - The idle-timeout middleware heartbeat
     */
    public function withhold(Session $session): void
    {
        $this->lockState->lock($session);
    }

    /**
     * Admit a data key into the session, transitioning it to unlocked.
     *
     * The authorized inverse of withhold(): used by the mobile cold-start
     * biometric unlock (Phase: cold-start-biometric-unlock) to unlock the
     * session with a data key recovered from the secure enclave. The KEY'S
     * PROVENANCE IS THE TRUST GATE — the only sanctioned caller obtains
     * $dataKey from BiometricKeyVault::recover(), which the OS enclave releases
     * only after a fresh, live biometric (Decision 1: biometric is a full
     * cryptographic root on mobile). Callers MUST NOT pass a key derived from a
     * bypassable signal (a bare bool prompt); a spoofed/aborted biometric never
     * yields a key here because recover() returns null in that case.
     */
    public function admitDataKey(Session $session, string $dataKey): void
    {
        $this->lockState->unlock($session, $dataKey);
    }
}
