<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Session\Session;

// The browser arm of what ColdStartEnroller does for the OS vault. Both wrap
// the data key somewhere a biometric alone opens afterwards, so both cost the
// PIN; they differ only in that a WebAuthn ceremony cannot finish inside the
// request that takes it.
/**
 * @link ../../../../.docs/design/cold-start-biometric-unlock.md
 */
final readonly class BrowserEnrollmentAuthorizer
{
    public function __construct(
        private PinVerificationService $verifier,
        private FreshPinProof $proof,
    ) {}

    // The key the verification produces is dropped here rather than carried:
    // the wrap happens a round trip later, and a copy parked in the session
    // meanwhile would be a second durable one of exactly the thing being
    // protected. What survives the gap is the proof, which is not a secret.
    public function authorize(int $userId, string $pin, Session $session): bool
    {
        $dataKey = $pin === '' ? null : $this->verifier->verify($userId, $pin, $session)->dataKey;

        if ($dataKey === null) {
            return false;
        }

        sodium_memzero($dataKey);

        $this->proof->mint($session, $userId);

        return true;
    }
}
