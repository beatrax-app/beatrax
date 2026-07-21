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

// These routes sit in the standard 'web' middleware group, so
// VerifyCsrfToken IS enforced -- there is no JSON exemption. lock.js reads
// the XSRF-TOKEN cookie and sends it back as the X-XSRF-TOKEN request
// header on every fetch, which Laravel accepts as the supplied token.
/**
 * @link ../../../../../.docs/features/auth/architecture.md
 */
final class WebAuthnBiometricController
{
    public function challenge(
        Request $request,
        CurrentUser $currentUser,
        WebAuthnBiometricService $service,
        Session $session,
    ): JsonResponse {
        $user = $currentUser->user();

        // ?enroll=1 requests attestation options (new credential); without
        // it, this returns assertion options (unlock an existing one).
        if ($request->query('enroll') === '1') {
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
