<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Services\MobileLockGateway;

/**
 * Orchestrates cold-start biometric enrollment / disable on mobile.
 *
 * Enrollment is opt-in and PIN-rooted (Decision: the PIN remains the
 * enrollment root even though biometric becomes the unlock root): the user
 * must re-enter their PIN, which both proves knowledge AND yields the live
 * data key to wrap into the enclave-gated vault. Disable clears the enclave
 * entry so a stale blob can never recover a key.
 *
 * Uses only Public seams: {@see MobileLockGateway} (Auth Public) for the PIN
 * re-verification + the enrollment flag, and {@see BiometricKeyVault} (same
 * module) for the enclave. It never touches Auth internals or the DB directly.
 */
final class ColdStartEnrollmentService
{
    public function __construct(
        private readonly BiometricKeyVault $vault,
        private readonly MobileLockGateway $gateway,
    ) {}

    /**
     * Enable cold-start biometric unlock: re-verify the PIN to obtain the live
     * data key, store its biometric-wrapped copy in the enclave, and record the
     * enrollment. Returns false when the vault is unavailable (not a
     * biometric-capable build), the PIN is wrong, or the native store fails —
     * in every failure case NOTHING is enrolled.
     */
    public function enroll(int $userId, string $pin, Session $session): bool
    {
        if (! $this->vault->isAvailable()) {
            return false;
        }

        // Re-verify the PIN NOW; verifyPin returns the unwrapped data key on
        // success (and null on a wrong PIN / active backoff).
        $dataKey = $this->gateway->verifyPin($userId, $pin, $session);
        if ($dataKey === null) {
            return false;
        }

        $ok = $this->vault->enroll($dataKey);
        // Zero the live data-key copy once it has been wrapped into the enclave
        // (the caller contract on PinVerificationService::verify / the
        // AppLockPassphraseChanged SECURITY note).
        sodium_memzero($dataKey);

        if (! $ok) {
            return false;
        }

        $this->gateway->markColdStartEnrolled($userId, true);

        return true;
    }

    /**
     * Disable cold-start biometric unlock: clear the enclave entry and reset
     * the enrollment flag. Idempotent and safe to call when not enrolled.
     */
    public function disable(int $userId): void
    {
        $this->vault->clear();
        $this->gateway->markColdStartEnrolled($userId, false);
    }

    /**
     * True when the user currently has a cold-start biometric enrollment.
     */
    public function isEnrolled(int $userId): bool
    {
        return $this->gateway->isColdStartEnrolled($userId);
    }
}
