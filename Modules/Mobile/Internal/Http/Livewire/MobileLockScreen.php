<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricUnlockBridge;

/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class MobileLockScreen extends Component
{
    public string $flashMessage = '';

    // Whether the device has a registered biometric credential AND the
    // native mobile-biometrics bridge is reachable right now.
    public bool $biometricAvailable = false;

    public string $biometricLabel = 'Use Face ID';

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function mount(
        CurrentUser $currentUser,
        MobileLockGateway $gateway,
        BiometricUnlockBridge $bridge,
        BiometricKeyVault $vault,
        Request $request,
        Session $session,
    ): void {
        $user = $currentUser->user();
        $ua = $request->userAgent() ?? '';

        $this->biometricLabel = $gateway->biometricLabel($ua);

        // The biometric trigger shows when EITHER the warm re-lock path
        // is available (an armed credential + the native bridge), OR
        // cold-start unlock is ready (enclave available, enrolled, and
        // the PIN floor is not yet due).
        $coldStartReady = $vault->isAvailable()
            && $gateway->isColdStartEnrolled($user->id)
            && ! $gateway->pinFloorDue($user->id);

        $this->biometricAvailable = ($gateway->hasArmedBiometricCredential($user->id) && $bridge->isAvailable())
            || $coldStartReady;

        // Why pairing sent them here. Without it the redirect landed on a PIN
        // pad that explained nothing, which is the dead end it exists to fix.
        $flashed = $session->get(MobilePairingScan::LOCKED_IDENTITY_FLASH);

        if (is_string($flashed) && $flashed !== '') {
            $this->flashMessage = $flashed;
        }
    }

    // -------------------------------------------------------------------------
    // Primary action — PIN fallback (reused UNCHANGED from the Auth analog)
    // -------------------------------------------------------------------------

    // Mirrors the Auth module's LockScreen::submit() exactly - same
    // validation, PIN-verification call, backoff/attempts-remaining copy,
    // and intendedUrl redirect - reached via the Public gateway.
    public function submit(
        string $pin,
        CurrentUser $currentUser,
        MobileLockGateway $gateway,
        UrlGenerator $urls,
        Session $session,
        Clock $clock,
    ): void {
        if (preg_match('/^\d{6,10}$/', $pin) !== 1) {
            $this->flashMessage = Lang::get('mobile::lock.errors.pin_length');

            return;
        }

        $user = $currentUser->user();
        $dataKey = $gateway->verifyPin($user->id, $pin, $session);

        if ($dataKey === null) {
            $lockedUntil = $gateway->pinLockedUntil($user->id);
            if ($lockedUntil !== null) {
                $seconds = max(1, (int) ceil($clock->now()->diffInMilliseconds($lockedUntil, absolute: true) / 1000));
                $this->flashMessage = Lang::get('mobile::lock.errors.too_many_attempts', ['seconds' => $seconds]);

                return;
            }

            $remaining = $gateway->remainingPinAttempts($user->id);
            $this->flashMessage = $remaining !== null
                ? Lang::get('mobile::lock.errors.incorrect_pin_remaining', ['count' => $remaining])
                : Lang::get('mobile::lock.errors.incorrect_pin');

            return;
        }

        $this->redirectToIntendedUrl($session, $urls);
    }

    // -------------------------------------------------------------------------
    // Biometric prompt (mount-time auto-invoke - biometric-primary means
    // auto-invoked, not just visually-first)
    // -------------------------------------------------------------------------

    // Fires the native biometric prompt and, on success, routes through
    // the same AppLockKeyService::release() read + intendedUrl redirect
    // submit() uses. Called from the Blade view's x-init the moment the
    // component mounts, and again on an explicit tap for a manual retry.
    public function biometricPrompt(
        BiometricUnlockBridge $bridge,
        AppLockKeyService $keyService,
        BiometricKeyVault $vault,
        MobileLockGateway $gateway,
        CurrentUser $currentUser,
        Session $session,
        UrlGenerator $urls,
    ): void {
        // Warm re-lock: the session still holds the data key. No enclave
        // read happens, so the bypassable bridge bool prompt is the right
        // gate - it confirms the holder before letting them back through.
        // A false/aborted bridge prompt releases nothing.
        if ($keyService->release($session) !== null) {
            if ($bridge->prompt()) {
                $this->redirectToIntendedUrl($session, $urls);
            }

            return;
        }

        // Cold start (no session key). Re-enforce the enrollment +
        // PIN-floor gates HERE at the unlock boundary - every Livewire
        // method is client-invokable regardless of what rendered, even
        // when biometricAvailable was true via the warm-credential clause.
        if (! $gateway->isColdStartEnrolled($currentUser->id()) || $gateway->pinFloorDue($currentUser->id())) {
            return;
        }

        // The enclave-gated vault IS the biometric gate - do not also fire
        // the bridge prompt, which would be a second, redundant, bypassable
        // prompt. recover() yields a key only after the OS releases the
        // enclave entry for a live biometric.
        $result = $vault->recover();
        if ($result->isRecovered() && $result->dataKey !== null) {
            // Unlock AND stamp last_activity_at so the idle-timeout middleware
            // does not immediately re-lock this (usually long-idle) cold start.
            $gateway->unlockWithRecoveredKey($currentUser->id(), $result->dataKey, $session);
            $this->redirectToIntendedUrl($session, $urls);
        }

        // canceled / failed / missing / unavailable / pending_async: no state
        // change — the always-visible PIN pad completes the unlock. On Android,
        // recover() returns pending_async and the outcome arrives via the
        // onColdStartRecovered / onColdStartFailed event handlers below.
    }

    // Android async completion: the native BiometricPrompt has already
    // authenticated and stashed the decrypted blob in a transient native
    // slot. Collects the blob PHP-side and admits on success; nothing to
    // admit falls through to the PIN pad.
    #[On('cold-start-recovered')]
    public function onColdStartRecovered(
        BiometricKeyVault $vault,
        MobileLockGateway $gateway,
        CurrentUser $currentUser,
        Session $session,
        UrlGenerator $urls,
    ): void {
        // Same unlock-boundary gates as the synchronous cold-start path: a
        // stale (flag-reset) enrollment or an overdue PIN floor must not admit,
        // even though the native prompt already ran.
        if (! $gateway->isColdStartEnrolled($currentUser->id()) || $gateway->pinFloorDue($currentUser->id())) {
            return;
        }

        $result = $vault->completePendingRecover();
        if ($result->isRecovered() && $result->dataKey !== null) {
            $gateway->unlockWithRecoveredKey($currentUser->id(), $result->dataKey, $session);
            $this->redirectToIntendedUrl($session, $urls);
        }
    }

    // Android async biometric failed/aborted - no state change; the
    // always-visible PIN pad remains the fallback.
    #[On('cold-start-failed')]
    public function onColdStartFailed(): void {}

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.mobile-lock-screen');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.lock', ['title' => Lang::get('mobile::lock.page_title').' · Beatrax']);

        return $view;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    // Two tiers, matching the desktop LockScreen: `url.intended` exists only
    // when the middleware redirected here, while a client-engaged lock leaves
    // only the last page. Reading the first tier alone sent every mobile
    // unlock to the dashboard, which on a first-run device bounces onward.
    private function redirectToIntendedUrl(Session $session, UrlGenerator $urls): void
    {
        $intended = $session->pull(MobileLockGateway::SESSION_INTENDED_URL);
        $lastPage = $session->pull(MobileLockGateway::SESSION_LAST_PAGE);

        $target = match (true) {
            is_string($intended) && $intended !== '' => $intended,
            is_string($lastPage) && $lastPage !== '' => $lastPage,
            default => $urls->route('dashboard'),
        };

        $this->redirect($target, navigate: false);
    }
}
