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
 * @link ../../../../.docs/features/auth/architecture.md
 */
final class PinVerificationService
{
    private const BACKOFF_THRESHOLD = 5;

    // Total failures before the session is signed out permanently. Public so
    // UI surfaces (LockScreen attempts-remaining copy) reference this single
    // source of truth instead of duplicating the number.
    public const HARD_CAP = 10;

    // Escalating backoff delays in seconds, indexed by the number of
    // threshold breaches (0-based): the first breach at BACKOFF_THRESHOLD
    // gives 30s, subsequent breaches double up to a 300s ceiling.
    /**
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

            if ($row->locked_until !== null) {
                $lockedUntilRaw = $row->locked_until;
                if (is_string($lockedUntilRaw) || is_int($lockedUntilRaw)) {
                    $lockedUntil = CarbonImmutable::parse($lockedUntilRaw);
                    if ($this->clock->now() < $lockedUntil) {
                        return null;
                    }
                }
            }

            $failedAttempts = $row->failed_attempts;
            if (! is_int($failedAttempts) && ! is_string($failedAttempts)) {
                $failedAttempts = 0;
            }

            if (! is_string($row->pin_hash) || ! $this->pinHasher->verify($pin, $row->pin_hash)) {
                return $this->handleFailure($userId, (int) $failedAttempts, $session);
            }

            if (! is_string($row->kdf_salt) || ! is_string($row->pin_wrapped_key)) {
                $this->emitAlert($userId, 'auth.lock.corrupted_key', 'critical', 'PIN wrap key blob is missing or not a string.');

                return null;
            }

            $wrapKey = $this->kdf->deriveWrapKey($pin, $row->kdf_salt);
            $dataKey = $this->keyWrap->unwrap($row->pin_wrapped_key, $wrapKey);
            sodium_memzero($wrapKey);

            if ($dataKey === false) {
                $this->emitAlert($userId, 'auth.lock.corrupted_key', 'critical', 'PIN-wrapped key unwrap failed (corrupted blob or wrong key).');

                return null;
            }

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

            // A successful PIN unlock re-arms ALL of the user's biometric
            // credentials (resets biometric_failed_count so a disarmed
            // credential is usable again).
            $this->biometricStore->resetAllForUser($userId);

            $this->lockState->unlock($session, $dataKey);

            return $dataKey;
        });

        return $result;
    }

    // Lets the lock screen distinguish "wrong PIN" from "backoff active" so
    // a user entering their correct PIN during the window is told to wait
    // instead of being told the PIN is wrong.
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

    private function handleFailure(int $userId, int $currentAttempts, Session $session): null
    {
        $newAttempts = $currentAttempts + 1;

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
