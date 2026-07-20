<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Core\Public\Contracts\Clock;

/**
 * Narrow Public read/write surface letting a non-Auth module's OWN
 * lock-screen implementation reuse the SAME PIN-verification and
 * biometric-enrollment-check collaborators the Auth `/lock` screen uses —
 * without duplicating that logic into a second module-owned copy (a
 * crypto-logic drift risk) and without reaching into
 * `Modules\Auth\Internal\*` directly (CLAUDE.md modular-architecture
 * constraint; enforced at the PHPStan level by `App\PhpStan\Rules\
 * BoundaryRule`, which — unlike the native-facade carve-outs in
 * `phpstan.neon` — has no per-file ignore mechanism at all).
 *
 * Added for Phase 15 Plan 06 (R6/MOBILE-01): `Modules\Mobile\Internal\Http\
 * Livewire\MobileLockScreen` is structurally identical to
 * `Modules\Auth\Internal\Http\Livewire\LockScreen` (15-PATTERNS.md
 * §MobileLockScreen — "reuse verbatim") and needs the exact same
 * PIN-verification + biometric-enrollment-check behavior. This gateway is
 * that one seam — every method here is a thin pass-through to the existing
 * Internal collaborator. No new crypto/trust primitive is introduced: the
 * LOCK-04 key-release path stays exclusively in `AppLockKeyService`, which
 * this gateway does not touch.
 */
final class MobileLockGateway
{
    public function __construct(
        private readonly PinVerificationService $verifier,
        private readonly BiometricDeviceStore $biometricStore,
        private readonly PlatformDetector $detector,
        private readonly DatabaseManager $db,
        private readonly AppLockProvisioner $provisioner,
        private readonly AppLockKeyService $keyService,
        private readonly Clock $clock,
    ) {}

    /**
     * Unlock the session with a data key recovered from the mobile cold-start
     * biometric vault, and stamp `last_activity_at` so the idle-timeout
     * middleware does not immediately re-lock the unlock.
     *
     * A genuine cold start (app killed / rebooted) almost always exceeds the
     * idle window, so without this stamp the very next request would see a
     * stale `last_activity_at`, treat the session as idle-expired, and bounce
     * the user back to the lock screen — making cold-start unlock useless
     * whenever idle timeout is enabled. The PIN path is immune because
     * PinVerificationService::verify() stamps activity on success; this is the
     * biometric equivalent. Key provenance (a real enclave recover) is the
     * caller's contract — see {@see AppLockKeyService::admitDataKey()}.
     */
    public function unlockWithRecoveredKey(int $userId, string $dataKey, Session $session): void
    {
        $this->keyService->admitDataKey($session, $dataKey);

        $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['last_activity_at' => $this->clock->now()]);
    }

    /**
     * Record whether the user has a cold-start biometric enrollment. Stored as
     * a plain flag on user_app_lock_configs so the lock screen / settings can
     * check enrollment without triggering a biometric prompt just to read it.
     */
    public function markColdStartEnrolled(int $userId, bool $enrolled): void
    {
        $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['cold_start_biometric_enrolled' => $enrolled]);
    }

    /**
     * True when the user has an active cold-start biometric enrollment.
     */
    public function isColdStartEnrolled(int $userId): bool
    {
        $row = $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['cold_start_biometric_enrolled']);

        return $row !== null && (bool) $row->cold_start_biometric_enrolled === true;
    }

    /** Default cold-start PIN-floor cadence (days) — tunable per install later. */
    public const PIN_FLOOR_DAYS = 14;

    /**
     * True when the cold-start biometric PIN floor is overdue: the user must
     * re-enter their PIN instead of using biometric. Due when there has never
     * been a PIN unlock (fresh install / just enrolled without a later PIN
     * unlock counts as fresh) OR the last PIN unlock is older than $floorDays.
     * A successful PIN unlock refreshes the anchor (last_pin_unlock_at) and
     * clears the floor. This keeps the PIN as a periodic re-auth even though
     * biometric is the everyday unlock root (Decision: PIN floor).
     */
    public function pinFloorDue(int $userId, int $floorDays = self::PIN_FLOOR_DAYS): bool
    {
        $row = $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['last_pin_unlock_at']);

        $lastRaw = $row?->last_pin_unlock_at;
        if (! is_string($lastRaw)) {
            // Never unlocked / unparseable → floor is due (fail safe: require PIN).
            return true;
        }

        $last = CarbonImmutable::parse($lastRaw);

        return $this->clock->now()->diffInDays($last, absolute: true) >= $floorDays;
    }

    /**
     * Enable the app lock for a fresh device-local identity bootstrap
     * (Phase 15 import-join, LOCK-04 precondition) — thin pass-through to
     * `AppLockProvisioner::enable()` unchanged. Added for the mobile
     * "Import from another device" onboarding screen
     * (`MobileImportBootstrap`), which needs to provision the app-lock KEK
     * immediately after `SignupAction` creates the local user, BEFORE any
     * sync-identity/GDK keyring write — every one of those writes hard-throws
     * without an unlocked KEK. No new trust decision: $accountPassword is the
     * SAME plaintext password the caller just used to create the account in
     * this same request, so no separate re-verification against the stored
     * hash is performed here (mirrors `AppLockProvisioner::enable()`'s own
     * contract — the caller is responsible for having authenticated
     * $accountPassword before calling this).
     */
    public function enableAppLock(int $userId, string $pin, string $accountPassword, Session $session): void
    {
        $this->provisioner->enable($userId, $pin, $accountPassword, $session);
    }

    /**
     * Platform-aware biometric button label, derived from the User-Agent
     * string. Delegates to `PlatformDetector::detectLabel()` unchanged.
     */
    public function biometricLabel(string $userAgent): string
    {
        return $this->detector->detectLabel($userAgent);
    }

    /**
     * True when the user has at least one ARMED enrolled biometric
     * credential (D-16: a credential disarmed by repeated failures does not
     * count). Delegates to `BiometricDeviceStore::findForUser()`/
     * `isArmed()` unchanged.
     */
    public function hasArmedBiometricCredential(int $userId): bool
    {
        return $this->biometricStore->findForUser($userId)
            ->contains(fn (object $cred): bool => $this->biometricStore->isArmed($cred));
    }

    /**
     * Verify a PIN and return the unwrapped data key on success — the key
     * is ALSO stored in the session by `PinVerificationService::verify()`
     * itself (via `LockStateManager::unlock()`, unchanged). Returns null on
     * a wrong PIN, an active backoff window, or the hard cap being reached
     * (session signed out) — see `PinVerificationService::verify()`'s own
     * docblock for the full contract.
     */
    public function verifyPin(int $userId, string $pin, Session $session): ?string
    {
        return $this->verifier->verify($userId, $pin, $session);
    }

    /**
     * The active PIN backoff deadline for this user, or null when no
     * backoff window is in effect right now. Delegates to
     * `PinVerificationService::lockedUntil()` unchanged.
     */
    public function pinLockedUntil(int $userId): ?CarbonImmutable
    {
        return $this->verifier->lockedUntil($userId);
    }

    /**
     * PIN attempts remaining before the hard cap, or null when the config
     * row is absent / the count cannot be read. Mirrors
     * `Modules\Auth\Internal\Http\Livewire\LockScreen::remainingAttempts()`
     * exactly (relocated here so a second module never needs its own raw
     * read of `user_app_lock_configs`).
     */
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
