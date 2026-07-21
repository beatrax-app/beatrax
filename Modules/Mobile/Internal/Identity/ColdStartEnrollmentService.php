<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Services\MobileLockGateway;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class ColdStartEnrollmentService
{
    public function __construct(
        private readonly BiometricKeyVault $vault,
        private readonly MobileLockGateway $gateway,
    ) {}

    // Re-verifies the PIN to obtain the live data key, stores its
    // biometric-wrapped copy in the enclave, and records the enrollment.
    // Returns false when the vault is unavailable, the PIN is wrong, or
    // the native store fails - in every failure case nothing is enrolled.
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
        // Zero the live data-key copy once it has been wrapped into the
        // enclave.
        sodium_memzero($dataKey);

        if (! $ok) {
            return false;
        }

        $this->gateway->markColdStartEnrolled($userId, true);

        return true;
    }

    public function disable(int $userId): void
    {
        $this->vault->clear();
        $this->gateway->markColdStartEnrolled($userId, false);
    }

    public function isEnrolled(int $userId): bool
    {
        return $this->gateway->isColdStartEnrolled($userId);
    }
}
