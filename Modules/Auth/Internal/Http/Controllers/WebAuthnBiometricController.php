<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Controllers;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Auth\Internal\Lock\WebAuthnBiometricService;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SecretShield;
use Symfony\Component\HttpFoundation\Response;

// In the standard 'web' group, so VerifyCsrfToken is enforced with no JSON
// exemption: lock.js echoes the XSRF-TOKEN cookie back as X-XSRF-TOKEN.
final class WebAuthnBiometricController
{
    public function challenge(
        Request $request,
        CurrentUser $currentUser,
        WebAuthnBiometricService $service,
        Session $session,
        SecretShield $shield,
    ): JsonResponse {
        $user = $currentUser->user();

        // ?enroll=1 requests attestation options (new credential); without
        // it, this returns assertion options (unlock an existing one).
        if ($request->query('enroll') === '1') {
            if (! $shield->protectsAtRest()) {
                return $this->refuseUnshieldedEnrolment();
            }

            $options = $service->creationOptions($user->id, $user->username, $session);
        } else {
            $options = $service->requestOptions($user->id, $session);
        }

        return new JsonResponse($options);
    }

    public function verify(
        Request $request,
        CurrentUser $currentUser,
        WebAuthnBiometricService $service,
        Session $session,
        UrlGenerator $urls,
    ): JsonResponse {
        /** @var array<string, mixed> $assertion */
        $assertion = $request->json()->all();

        $user = $currentUser->user();

        $unlocked = $service->verifyAndRelease($user->id, $assertion, $session);

        if ($unlocked) {
            $redirect = $session->pull('url.intended', $urls->route('dashboard'));
            if (! is_string($redirect)) {
                $redirect = $urls->route('dashboard');
            }
        } else {
            $redirect = $urls->route('auth.lock');
        }

        return new JsonResponse([
            'unlocked' => $unlocked,
            'redirect' => $redirect,
        ]);
    }

    public function enroll(
        Request $request,
        CurrentUser $currentUser,
        WebAuthnBiometricService $service,
        PlatformDetector $detector,
        Session $session,
        LockStateManager $lockState,
        SecretShield $shield,
    ): JsonResponse {
        if (! $shield->protectsAtRest()) {
            return $this->refuseUnshieldedEnrolment();
        }

        $user = $currentUser->user();

        /** @var array<string, mixed> $credentialResponse */
        $credentialResponse = $request->json()->all();

        // Through the custodian, so the enrolled biometric wraps the real key
        // bytes rather than the opaque handle on native bundles.
        $dataKey = $lockState->heldKey($session);
        if ($dataKey === null) {
            return new JsonResponse(['enrolled' => false, 'error' => 'Session not unlocked.'], Response::HTTP_FORBIDDEN);
        }

        $ua = $request->userAgent() ?? '';
        $deviceLabel = $detector->detectLabel($ua);

        try {
            $service->completeEnrollment(
                $user->id,
                $user->username,
                $credentialResponse,
                $dataKey,
                $deviceLabel,
                BiometricDeviceStore::PLATFORM_WEBAUTHN,
                $session,
            );

            return new JsonResponse(['enrolled' => true]);
        } catch (\Throwable) {
            return new JsonResponse(['enrolled' => false, 'error' => 'Enrollment failed.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    // The enrolled row is `secret || wrapped_key` in the same SQLite file as
    // the ledger, so a shield that leaves those bytes readable turns enrolment
    // into a plaintext copy of the app-lock data key. Refused here rather than
    // in the caller, so every route into enrolment is covered.
    private function refuseUnshieldedEnrolment(): JsonResponse
    {
        return new JsonResponse(
            ['enrolled' => false, 'error' => 'Biometric key material cannot be protected at rest here.'],
            Response::HTTP_FORBIDDEN,
        );
    }
}
