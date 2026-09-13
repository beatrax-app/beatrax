<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Controllers;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Internal\Actions\EnrollBiometricCredential;
use Modules\Auth\Internal\Lock\BiometricEnrollmentOutcome;
use Modules\Auth\Internal\Lock\WebAuthnBiometricService;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Navigation\Destination;
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
                return $this->enrollmentResponse(BiometricEnrollmentOutcome::Unshielded);
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

        $unlocked = $service->verifyAndRelease($currentUser->user()->id, $assertion, $session);

        if ($unlocked) {
            $redirect = $session->pull('url.intended', Destination::Dashboard->urlFrom($urls));
            if (! is_string($redirect)) {
                $redirect = Destination::Dashboard->urlFrom($urls);
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
        EnrollBiometricCredential $enroll,
        Session $session,
    ): JsonResponse {
        /** @var array<string, mixed> $credentialResponse */
        $credentialResponse = $request->json()->all();

        return $this->enrollmentResponse($enroll($credentialResponse, $request->userAgent() ?? '', $session));
    }

    // The unshielded refusal is answered here rather than in the caller, so
    // both routes into enrolment — the options request and the completion —
    // report the same thing.
    private function enrollmentResponse(BiometricEnrollmentOutcome $outcome): JsonResponse
    {
        return match ($outcome) {
            BiometricEnrollmentOutcome::Enrolled => new JsonResponse(['enrolled' => true]),
            BiometricEnrollmentOutcome::Unshielded => new JsonResponse(
                ['enrolled' => false, 'error' => 'Biometric key material cannot be protected at rest here.'],
                Response::HTTP_FORBIDDEN,
            ),
            BiometricEnrollmentOutcome::SessionLocked => new JsonResponse(
                ['enrolled' => false, 'error' => 'Session not unlocked.'],
                Response::HTTP_FORBIDDEN,
            ),
            // Named apart from the other refusals because it is the only one
            // the reader can answer: type the code again and come back.
            BiometricEnrollmentOutcome::PinNotProved => new JsonResponse(
                ['enrolled' => false, 'error' => 'pin_not_proved'],
                Response::HTTP_FORBIDDEN,
            ),
            BiometricEnrollmentOutcome::Failed => new JsonResponse(
                ['enrolled' => false, 'error' => 'Enrollment failed.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ),
        };
    }
}
