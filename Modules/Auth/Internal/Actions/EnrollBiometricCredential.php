<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Actions;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\BiometricEnrollmentOutcome;
use Modules\Auth\Internal\Lock\FreshPinProof;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Auth\Internal\Lock\WebAuthnBiometricService;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SecretShield;
use Throwable;

final readonly class EnrollBiometricCredential
{
    public function __construct(
        private WebAuthnBiometricService $service,
        private PlatformDetector $detector,
        private LockStateManager $lockState,
        private SecretShield $shield,
        private CurrentUser $currentUser,
        private FreshPinProof $pinProof,
    ) {}

    /**
     * @param  array<string, mixed>  $credentialResponse
     */
    public function __invoke(array $credentialResponse, string $userAgent, Session $session): BiometricEnrollmentOutcome
    {
        $refusal = $this->refusal($session);

        if ($refusal !== null) {
            return $refusal;
        }

        // Through the custodian, so the enrolled biometric wraps the real key
        // bytes rather than the opaque handle on native bundles.
        $dataKey = $this->lockState->heldKey($session);

        return $dataKey === null
            ? BiometricEnrollmentOutcome::SessionLocked
            : $this->record($credentialResponse, $userAgent, $dataKey, $session);
    }

    // Both answers are reached before a key is read and before a byte is
    // written, so a ceremony either refusal turns away leaves nothing behind
    // and cannot be replayed on the same proof.
    private function refusal(Session $session): ?BiometricEnrollmentOutcome
    {
        // The enrolled row is `secret || wrapped_key` in the same SQLite file
        // as the ledger, so a shield that leaves those bytes readable turns
        // enrolment into a plaintext copy of the app-lock data key.
        if (! $this->shield->protectsAtRest()) {
            return BiometricEnrollmentOutcome::Unshielded;
        }

        return $this->pinProof->consume($session, $this->currentUser->user()->id)
            ? null
            : BiometricEnrollmentOutcome::PinNotProved;
    }

    /**
     * @param  array<string, mixed>  $credentialResponse
     */
    private function record(
        array $credentialResponse,
        string $userAgent,
        string $dataKey,
        Session $session,
    ): BiometricEnrollmentOutcome {
        $user = $this->currentUser->user();

        try {
            $this->service->completeEnrollment(
                $user->id,
                $user->username,
                $credentialResponse,
                $dataKey,
                $this->detector->detectLabel($userAgent),
                BiometricDeviceStore::PLATFORM_WEBAUTHN,
                $session,
            );
        } catch (Throwable) {
            return BiometricEnrollmentOutcome::Failed;
        }

        return BiometricEnrollmentOutcome::Enrolled;
    }
}
