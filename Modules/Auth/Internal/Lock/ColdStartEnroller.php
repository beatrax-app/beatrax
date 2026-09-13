<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Services\ColdStartEnrollmentFlag;

// The only way to arm the OS vault. What it stores is a durable wrap of the
// data key that biometrics alone can open afterwards, so the price of creating
// one is the same proof removing one costs: the PIN, typed now.
/**
 * @link ../../../../.docs/design/cold-start-biometric-unlock.md
 */
final readonly class ColdStartEnroller
{
    public function __construct(
        private PinVerificationService $verifier,
        private ColdStartVault $vault,
        private ColdStartEnrollmentFlag $enrollment,
    ) {}

    // The key comes out of the PIN rather than out of the session, so there is
    // no arrangement of callers that arms the vault without one.
    public function enroll(int $userId, string $pin, Session $session): ColdStartEnrollmentResult
    {
        if (! $this->vault->isAvailable()) {
            return ColdStartEnrollmentResult::VaultRefused;
        }

        // The empty box is refused here rather than at the hasher, which raises
        // on one instead of answering. It earns the same refusal a wrong PIN
        // does, so the two share the answer as well as the reason.
        $dataKey = $pin === '' ? null : $this->verifier->verify($userId, $pin, $session)->dataKey;

        if ($dataKey === null) {
            return ColdStartEnrollmentResult::PinRejected;
        }

        $stored = $this->vault->enroll($userId, $dataKey);
        sodium_memzero($dataKey);

        // Marked here rather than left to the vault: the lock screen offers the
        // unlock on the flag as well as the entry, and a desktop enrolment that
        // set only the entry was an unlock nothing ever offered.
        if ($stored) {
            $this->enrollment->mark($userId, true);
        }

        return $stored ? ColdStartEnrollmentResult::Enrolled : ColdStartEnrollmentResult::VaultRefused;
    }
}
