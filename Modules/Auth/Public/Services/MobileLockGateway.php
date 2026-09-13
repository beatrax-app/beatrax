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
use Modules\Auth\Public\Enums\PinUnlockOutcome;
use Modules\Core\Public\Contracts\Clock;

final readonly class MobileLockGateway
{
    // Two tiers because a lock arrives two ways: a middleware redirect leaves
    // `url.intended`, a client-engaged one leaves only the last page.
    // Published here because AppLockMiddleware is Internal to this module.
    public const string SESSION_INTENDED_URL = 'url.intended';

    public const string SESSION_LAST_PAGE = AppLockMiddleware::SESSION_LAST_PAGE;

    public function __construct(
        private PinVerificationService $verifier,
        private BiometricDeviceStore $biometricStore,
        private PlatformDetector $detector,
        private DatabaseManager $db,
        private AppLockProvisioner $provisioner,
        private AppLockKeyService $keyService,
        private Clock $clock,
        private ColdStartEnrollmentFlag $coldStartFlag,
    ) {}

    public function unlockWithRecoveredKey(int $userId, string $dataKey, Session $session): void
    {
        $this->keyService->admitDataKey($session, $dataKey);

        $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['last_activity_at' => $this->clock->now()]);
    }

    public function markColdStartEnrolled(int $userId, bool $enrolled): void
    {
        $this->coldStartFlag->mark($userId, $enrolled);
    }

    public function isColdStartEnrolled(int $userId): bool
    {
        return $this->coldStartFlag->isEnrolled($userId);
    }

    public const int PIN_FLOOR_DAYS = 14;

    public function pinFloorDue(int $userId, int $floorDays = self::PIN_FLOOR_DAYS): bool
    {
        $row = $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['last_pin_unlock_at']);

        $lastRaw = $row?->last_pin_unlock_at;
        if (! is_string($lastRaw)) {
            return true;
        }

        $last = CarbonImmutable::parse($lastRaw);

        return $this->clock->now()->diffInDays($last, absolute: true) >= $floorDays;
    }

    // No new trust decision: $accountPassword is the same plaintext the caller
    // just created the account with in this request, and authenticating it
    // remains the caller's job.
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

    // The key itself, for the cold-start enrolment that wraps the live one
    // into the enclave. A screen wants unlockWithPin(): the key alone cannot
    // tell a refusal from an attempt nothing recorded.
    public function verifyPin(int $userId, string $pin, Session $session): ?string
    {
        return $this->verifier->verify($userId, $pin, $session)->dataKey;
    }

    public function unlockWithPin(int $userId, string $pin, Session $session): PinUnlockOutcome
    {
        $attempt = $this->verifier->verify($userId, $pin, $session);

        return match (true) {
            $attempt->dataKey !== null => PinUnlockOutcome::Unlocked,
            $attempt->pinChangedMidAttempt => PinUnlockOutcome::OutracedByAPinChange,
            default => PinUnlockOutcome::Refused,
        };
    }

    public function pinLockedUntil(int $userId): ?CarbonImmutable
    {
        return $this->verifier->lockedUntil($userId);
    }

    // One wrong PIN is the moment the reader has a question about the PIN
    // rather than an answer, and it is the whole of what the forgotten-code
    // explanation waits for. Read off the meter the pad already keeps, so a
    // reload or a re-lock cannot hand back a screen that has forgotten.
    public const int FORGOTTEN_PIN_HELP_AFTER_FAILURES = 1;

    public function forgottenPinHelpDue(int $userId): bool
    {
        $remaining = $this->remainingPinAttempts($userId);

        return $remaining !== null
            && $remaining <= PinVerificationService::HARD_CAP - self::FORGOTTEN_PIN_HELP_AFTER_FAILURES;
    }

    // Here, mirroring LockScreen::remainingAttempts(), so no second module
    // needs its own raw read of user_app_lock_configs.
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
