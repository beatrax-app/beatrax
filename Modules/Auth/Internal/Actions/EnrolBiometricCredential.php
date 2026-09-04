<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Actions;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\BiometricEnrolmentOutcome;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Auth\Internal\Lock\WebAuthnBiometricService;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SecretShield;
use Throwable;

final readonly class EnrolBiometricCredential
{
    public function __construct(
        private WebAuthnBiometricService $service,
        private PlatformDetector $detector,
        private LockStateManager $lockState,
        private SecretShield $shield,
        private CurrentUser $currentUser,
    ) {}

    /**
     * @param  array<string, mixed>  $credentialResponse
     */
    public function __invoke(array $credentialResponse, string $userAgent, Session $session): BiometricEnrolmentOutcome
    {
        // The enrolled row is `secret || wrapped_key` in the same SQLite file
        // as the ledger, so a shield that leaves those bytes readable turns
        // enrolment into a plaintext copy of the app-lock data key.
        if (! $this->shield->protectsAtRest()) {
            return BiometricEnrolmentOutcome::Unshielded;
        }

        // Through the custodian, so the enrolled biometric wraps the real key
        // bytes rather than the opaque handle on native bundles.
        $dataKey = $this->lockState->heldKey($session);
        if ($dataKey === null) {
            return BiometricEnrolmentOutcome::SessionLocked;
        }

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
            return BiometricEnrolmentOutcome::Failed;
        }

        return BiometricEnrolmentOutcome::Enrolled;
    }
}
