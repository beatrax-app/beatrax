<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Core\Public\Contracts\Clock;

/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
final class MobileLockGateway
{
    // Where an unlocked session should resume, in precedence order. Published
    // here because the mobile lock screen lives in another module and the keys
    // belong to this one; AppLockMiddleware is Internal and must stay so.
    //
    // Two tiers, because a lock arrives by two routes: a middleware redirect
    // leaves `url.intended`, while a lock engaged from the client — the idle
    // timeout and the return-from-background path, which is how a phone locks
    // nearly every time — leaves only the last page the user was on.
    public const SESSION_INTENDED_URL = 'url.intended';

    public const SESSION_LAST_PAGE = AppLockMiddleware::SESSION_LAST_PAGE;

    public function __construct(
        private readonly PinVerificationService $verifier,
        private readonly BiometricDeviceStore $biometricStore,
        private readonly PlatformDetector $detector,
        private readonly DatabaseManager $db,
        private readonly AppLockProvisioner $provisioner,
        private readonly AppLockKeyService $keyService,
        private readonly Clock $clock,
    ) {}

    public function unlockWithRecoveredKey(int $userId, string $dataKey, Session $session): void
    {
        $this->keyService->admitDataKey($session, $dataKey);

        $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['last_activity_at' => $this->clock->now()]);
    }

    // Stored as a plain flag so the lock screen / settings can check
    // enrollment without triggering a biometric prompt just to read it.
    public function markColdStartEnrolled(int $userId, bool $enrolled): void
    {
        $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['cold_start_biometric_enrolled' => $enrolled]);
    }

    public function isColdStartEnrolled(int $userId): bool
    {
        $row = $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['cold_start_biometric_enrolled']);

        return $row !== null && (bool) $row->cold_start_biometric_enrolled === true;
    }

    public const PIN_FLOOR_DAYS = 14;

    public function pinFloorDue(int $userId, int $floorDays = self::PIN_FLOOR_DAYS): bool
    {
        $row = $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['last_pin_unlock_at']);

        $lastRaw = $row?->last_pin_unlock_at;
        if (! is_string($lastRaw)) {
            // Never unlocked, or unparseable -- fail safe by requiring the
            // PIN rather than trusting an unknown state.
            return true;
        }

        $last = CarbonImmutable::parse($lastRaw);

        return $this->clock->now()->diffInDays($last, absolute: true) >= $floorDays;
    }

    // No new trust decision: $accountPassword is the SAME plaintext
    // password the caller just used to create the account in this same
    // request, so no separate re-verification is performed here -- the
    // caller is responsible for having authenticated it beforehand.
    public function enableAppLock(int $userId, string $pin, string $accountPassword, Session $session): void
    {
        $this->provisioner->enable($userId, $pin, $accountPassword, $session);
    }

    public function biometricLabel(string $userAgent): string
    {
        return $this->detector->detectLabel($userAgent);
    }

    public function hasArmedBiometricCredential(int $userId): bool
    {
        return $this->biometricStore->findForUser($userId)
            ->contains(fn (object $cred): bool => $this->biometricStore->isArmed($cred));
    }

    public function verifyPin(int $userId, string $pin, Session $session): ?string
    {
        return $this->verifier->verify($userId, $pin, $session);
    }

    public function pinLockedUntil(int $userId): ?CarbonImmutable
    {
        return $this->verifier->lockedUntil($userId);
    }

    // Relocated here (mirroring LockScreen::remainingAttempts()) so a
    // second module never needs its own raw read of user_app_lock_configs.
    public function remainingPinAttempts(int $userId): ?int
    {
        $row = $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['failed_attempts']);

        if ($row === null) {
            return null;
        }

        $failed = $row->failed_attempts;
        if (! is_int($failed) && ! is_string($failed)) {
            return null;
        }

        return max(0, PinVerificationService::HARD_CAP - (int) $failed);
    }
}
