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

/**
 * JSON endpoints for WebAuthn biometric enrollment and assertion.
 *
 * Two routes — both in AppLockMiddleware::ALLOWED_ROUTE_NAMES so a locked
 * session can reach them to complete biometric unlock (T-05-21):
 *
 *   POST /lock/biometric/challenge  → challenge()  [get requestOptions]
 *   POST /lock/biometric/verify     → verify()     [submit assertion]
 *
 * The enroll flow uses separate dedicated query-string routing:
 *   POST /lock/biometric/challenge?enroll=1  → creationOptions
 *
 * Security:
 *   T-05-20: The WebAuthnBiometricService validates rpId + full origin.
 *   T-05-21: Routes are in ALLOWED_ROUTE_NAMES (AppLockMiddleware).
 *   T-05-22: verifyAndRelease increments failure count on any failure.
 *   T-05-23: The browser only fires navigator.credentials.get on button tap.
 *
 * CSRF: these routes sit in the standard 'web' middleware group, so
 * VerifyCsrfToken IS enforced — there is no JSON exemption and no exclusion
 * list entry. The requests succeed because lock.js reads the XSRF-TOKEN
 * cookie and sends its value back as the X-XSRF-TOKEN request header on
 * every fetch; Laravel accepts the token only from a `_token` body field,
 * the X-CSRF-TOKEN header, or the X-XSRF-TOKEN header (the cookie alone is
 * never accepted as the supplied token).
 */
final class WebAuthnBiometricController
{
    /**
     * Return challenge options.
     *
     * With ?enroll=1: returns PublicKeyCredentialCreationOptions (attestation).
     * Without:        returns PublicKeyCredentialRequestOptions (assertion).
     */
    public function challenge(
        Request $request,
        CurrentUser $currentUser,
        WebAuthnBiometricService $service,
        Session $session,
    ): JsonResponse {
        $user = $currentUser->user();

        if ($request->query('enroll') === '1') {
            $options = $service->creationOptions($user->id, $user->username, $session);
        } else {
            $options = $service->requestOptions($user->id, $session);
        }

        return new JsonResponse($options);
    }

    /**
     * Verify an assertion and unlock the session.
     *
     * Returns JSON: { unlocked: bool, redirect: string }.
     * On success: the session is unlocked and the redirect URL is the intended URL.
     * On failure: unlocked is false, redirect is the lock route.
     */
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

    /**
     * Complete enrollment: verify the attestation and store the credential.
     *
     * Expects JSON body with the attestation response from navigator.credentials.create.
     * The caller must be unlocked (session has the data key) for this to succeed.
     */
    public function enroll(
        Request $request,
        CurrentUser $currentUser,
        WebAuthnBiometricService $service,
        BiometricDeviceStore $store,
        PlatformDetector $detector,
        Session $session,
        LockStateManager $lockState,
    ): JsonResponse {
        $user = $currentUser->user();

        /** @var array<string, mixed> $credentialResponse */
        $credentialResponse = $request->json()->all();

        // Retrieve the live data key from the session (must be unlocked).
        // Goes through the custodian so the enrolled biometric wraps the
        // real key bytes, not the opaque custody handle, on native bundles.
        $dataKey = $lockState->heldKey($session);
        if ($dataKey === null) {
            return new JsonResponse(['enrolled' => false, 'error' => 'Session not unlocked.'], 403);
        }

        $ua = $request->userAgent() ?? '';
        $deviceLabel = $detector->detectLabel($ua);
        $platform = $detector->platformKey($ua);

        try {
            $service->completeEnrollment(
                $user->id,
                $user->username,
                $credentialResponse,
                $dataKey,
                $deviceLabel,
                $platform,
                $session,
            );

            return new JsonResponse(['enrolled' => true]);
        } catch (\Throwable) {
            return new JsonResponse(['enrolled' => false, 'error' => 'Enrollment failed.'], 422);
        }
    }
}
