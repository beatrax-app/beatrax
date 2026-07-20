<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Actions\LogoutAction;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;

/**
 * Verifies a PIN against the stored Argon2id hash with escalating backoff.
 *
 * On success: unwraps the data key from pin_wrapped_key via AppLockKeyWrap,
 * calls LockStateManager->unlock(), resets the failed-attempt counter, and
 * returns the raw data-key bytes.
 *
 * On failure: increments failed_attempts; if the count crosses the backoff
 * threshold it sets locked_until (short-circuits further attempts without
 * hashing); at the hard cap it signs the session out and emits a SystemAlert.
 *
 * Threat mitigations:
 *   - T-05-07: escalating backoff prevents online PIN brute force.
 *   - T-05-09: sodium_memzero zeroes the derived wrap key after use.
 *   - T-05-10: unwrap()===false is handled as a non-counting failure with
 *     a SystemAlert; the app does not crash on a corrupted blob.
 *
 * The lockForUpdate() + transaction() pattern from RecoveryCodeAuthenticator
 * is used to prevent race conditions on concurrent PIN attempts.
 */
final class PinVerificationService
{
    /** Number of failures before a time-based backoff begins. */
    private const BACKOFF_THRESHOLD = 5;

    /**
     * Total failures before the session is signed out permanently.
     * Public so UI surfaces (LockScreen attempts-remaining copy) reference
     * the single source of truth instead of duplicating the number (IN-05).
     */
    public const HARD_CAP = 10;

    /**
     * Escalating backoff delays in seconds, indexed by the number of threshold
     * breaches (0-based). The first breach at BACKOFF_THRESHOLD gives 30s,
     * subsequent breaches double up to 300s.
     *
     * Index 0 = first breach (attempts == BACKOFF_THRESHOLD): 30s
     * Index 1 = second breach (BACKOFF_THRESHOLD + 1):         60s
     * Index 2 = third breach and beyond:                      300s
     *
     * @var array<int, int>
     */
    private const BACKOFF_SECONDS = [
        0 => 30,
        1 => 60,
        2 => 300,
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PinHasher $pinHasher,
        private readonly AppLockKdf $kdf,
        private readonly AppLockKeyWrap $keyWrap,
        private readonly LockStateManager $lockState,
        private readonly Clock $clock,
        private readonly LogoutAction $logout,
        private readonly BiometricDeviceStore $biometricStore,
    ) {}

    /**
     * Verify a PIN and return the unwrapped data key on success.
     *
     * Returns the raw data-key bytes (string) when:
     *   - The PIN is correct.
     *   - The pin_wrapped_key blob decrypts successfully.
     *
     * Returns null when:
     *   - The backoff window is active (locked_until is in the future).
     *   - The PIN is wrong (and increments failed_attempts).
     *   - The hard cap is reached (session signed out, SystemAlert emitted).
     *   - The blob is corrupted (non-counting failure, SystemAlert emitted).
     *
     * On success the returned key bytes are ALSO stored in the session via
     * LockStateManager::unlock(). The caller should sodium_memzero() any
     * local copy after use.
     */
    public function verify(int $userId, string $pin, Session $session): ?string
    {
        /** @var string|null $result */
        $result = $this->db->connection()->transaction(function () use ($userId, $pin, $session): ?string {
            $row = $this->db->connection()
                ->table('user_app_lock_configs')
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }

            // Short-circuit if a backoff window is active.
            if ($row->locked_until !== null) {
                $lockedUntilRaw = $row->locked_until;
                if (is_string($lockedUntilRaw) || is_int($lockedUntilRaw)) {
                    $lockedUntil = CarbonImmutable::parse($lockedUntilRaw);
                    if ($this->clock->now() < $lockedUntil) {
                        return null;
                    }
                }
            }

            // Wrong PIN path.
            $failedAttempts = $row->failed_attempts;
            if (! is_int($failedAttempts) && ! is_string($failedAttempts)) {
                $failedAttempts = 0;
            }

            if (! is_string($row->pin_hash) || ! $this->pinHasher->verify($pin, $row->pin_hash)) {
                return $this->handleFailure($userId, (int) $failedAttempts, $session);
            }

            // Correct PIN — unwrap the data key.
            if (! is_string($row->kdf_salt) || ! is_string($row->pin_wrapped_key)) {
                $this->emitAlert($userId, 'auth.lock.corrupted_key', 'critical', 'PIN wrap key blob is missing or not a string.');

                return null;
            }

            $wrapKey = $this->kdf->deriveWrapKey($pin, $row->kdf_salt);
            $dataKey = $this->keyWrap->unwrap($row->pin_wrapped_key, $wrapKey);
            sodium_memzero($wrapKey);

            if ($dataKey === false) {
                // Corrupted blob: non-counting failure + alert.
                $this->emitAlert($userId, 'auth.lock.corrupted_key', 'critical', 'PIN-wrapped key unwrap failed (corrupted blob or wrong key).');

                return null;
            }

            // Success: reset counters and unlock the session.
            $this->db->connection()
                ->table('user_app_lock_configs')
                ->where('user_id', $userId)
                ->update([
                    'failed_attempts' => 0,
                    'locked_until' => null,
                    'last_activity_at' => $this->clock->now(),
                    // Anchor the cold-start biometric PIN floor: a genuine PIN
                    // unlock refreshes the "must re-enter PIN every N days"
                    // clock (see MobileLockGateway::pinFloorDue()).
                    'last_pin_unlock_at' => $this->clock->now(),
                ]);

            // D-16: a successful PIN unlock re-arms ALL of the user's
            // biometric credentials (resets biometric_failed_count so a
            // disarmed credential is usable again).
            $this->biometricStore->resetAllForUser($userId);

            $this->lockState->unlock($session, $dataKey);

            return $dataKey;
        });

        return $result;
    }

    /**
     * The active backoff deadline for this user, or null when no backoff
     * window is in effect right now.
     *
     * Lets the lock screen distinguish "wrong PIN" from "backoff active"
     * (WR-05) so a user entering their CORRECT PIN during the window is told
     * to wait instead of being told the PIN is wrong.
     */
    public function lockedUntil(int $userId): ?CarbonImmutable
    {
        $row = $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['locked_until']);

        if ($row === null) {
            return null;
        }

        $raw = $row->locked_until;
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $until = CarbonImmutable::parse($raw);

        return $this->clock->now() < $until ? $until : null;
    }

    /**
     * Handle a wrong-PIN failure: increment counter, set backoff or sign out.
     */
    private function handleFailure(int $userId, int $currentAttempts, Session $session): null
    {
        $newAttempts = $currentAttempts + 1;

        // Hard cap: sign the session out.
        if ($newAttempts >= self::HARD_CAP) {
            $this->db->connection()
                ->table('user_app_lock_configs')
                ->where('user_id', $userId)
                ->update([
                    'failed_attempts' => $newAttempts,
                    'locked_until' => null,
                ]);

            $this->emitAlert($userId, 'auth.lock.hard_cap_reached', 'critical', 'Hard cap of '.self::HARD_CAP.' PIN failures reached; session signed out.');
            ($this->logout)();

            return null;
        }

        // Compute backoff if at or above the threshold.
        $lockedUntil = null;
        if ($newAttempts >= self::BACKOFF_THRESHOLD) {
            $breachIndex = $newAttempts - self::BACKOFF_THRESHOLD;
            $seconds = self::BACKOFF_SECONDS[min($breachIndex, count(self::BACKOFF_SECONDS) - 1)];
            $lockedUntil = $this->clock->now()->addSeconds($seconds)->toDateTimeString();
        }

        $this->db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update([
                'failed_attempts' => $newAttempts,
                'locked_until' => $lockedUntil,
            ]);

        return null;
    }

    /**
     * Emit a SystemAlert audit row.
     */
    private function emitAlert(int $userId, string $kind, string $severity, string $message): void
    {
        SystemAlert::query()->create([
            'user_id' => $userId,
            'kind' => $kind,
            'severity' => $severity,
            'message' => $message,
        ]);
    }
}
