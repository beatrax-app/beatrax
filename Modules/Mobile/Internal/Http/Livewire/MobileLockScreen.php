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
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricUnlockBridge;

/**
 * The `/mobile/lock` route (R6, MOBILE-01) — biometric-primary on-device
 * unlock screen gating the LOCK-04 at-rest key, with the PIN pad as the
 * always-available fallback.
 *
 * Structurally identical to `Modules\Auth\Internal\Http\Livewire\LockScreen`
 * (15-PATTERNS.md §MobileLockScreen — exact structural-reuse analog):
 * `mount()`'s biometric-enrollment-check derivation and `submit()`'s PIN
 * path reuse the SAME PIN-verification / biometric-enrollment-check
 * behavior the Auth `/lock` screen uses, via the narrow
 * `Modules\Auth\Public\Services\MobileLockGateway` seam (added alongside
 * this plan — there was no existing Public surface for that behavior, only
 * `Modules\Auth\Internal\*`, which this module may never import; see the
 * gateway's own docblock). This module otherwise consumes only
 * `Modules\Auth\Public\Services\{AppLockKeyService,MobileLockGateway}` —
 * never `Modules\Auth\Internal\*` directly.
 *
 * The ONLY behavioral difference from the desktop/web lock screen is the
 * biometric TRIGGER: instead of dispatching the browser WebAuthn round-trip
 * event the desktop/web screen uses, `biometricPrompt()` calls
 * `BiometricUnlockBridge::prompt()` directly (native, bool-only) and, on
 * `true`, routes into the SAME success path `submit()` uses (the
 * intendedUrl redirect). The bridge itself NEVER derives, wraps, or
 * unwraps any key material (Purpose: no new crypto/trust primitive) — a
 * `true` result is only ever used to READ through the existing
 * `AppLockKeyService::release()` gate the whole app already uses:
 *
 *   - If the session already carries a data key (T-15-14/T-15-15): the
 *     biometric confirms the device holder and the user is let through.
 *   - If the session genuinely has no data key yet (LOCK-04's cryptographic
 *     root is the PIN — a fresh cold start or a real idle-timeout re-lock):
 *     biometric success alone cannot conjure one. This falls through
 *     silently and the PIN pad — rendered underneath, unconditionally
 *     visible — completes the real unlock exactly as it always has.
 *
 * A false/aborted biometric NEVER reaches `AppLockKeyService::release()`
 * (T-15-14: spoofed/aborted biometric must not release the key; T-15-15:
 * no plaintext leak on a locked device). This bridge is never imported
 * anywhere else in this module (T-15-16).
 *
 * Constructor-free Livewire component (phpstan-strict-rules forbids a
 * constructor on a `Component` subclass) — collaborators arrive as
 * mount()/submit()/biometricPrompt()/render() method-DI parameters, exactly
 * like the Auth analog.
 */
final class MobileLockScreen extends Component
{
    /** Rose-tinted error / status message shown above the pad. */
    public string $flashMessage = '';

    /**
     * Whether the device has a registered biometric credential AND the
     * native mobile-biometrics bridge is reachable right now.
     */
    public bool $biometricAvailable = false;

    /** User-facing label for the biometric button (platform-aware). */
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
    ): void {
        $user = $currentUser->user();
        $ua = $request->userAgent() ?? '';

        // Platform-aware label from the user-agent string — unchanged
        // behavior from the Auth analog (LockScreen lines 63-80), reached
        // via the Public gateway.
        $this->biometricLabel = $gateway->biometricLabel($ua);

        // The biometric trigger shows when EITHER:
        //  - the warm re-lock path is available: an armed WebAuthn-style
        //    credential + the native bridge (existing behaviour), OR
        //  - cold-start unlock is ready: the enclave vault is available, the
        //    user has enrolled a cold-start blob, AND the PIN floor is not due
        //    (biometric alone may unlock only inside the floor window; when the
        //    floor is overdue the PIN pad is the only option and using it
        //    refreshes the floor).
        $coldStartReady = $vault->isAvailable()
            && $gateway->isColdStartEnrolled($user->id)
            && ! $gateway->pinFloorDue($user->id);

        $this->biometricAvailable = ($gateway->hasArmedBiometricCredential($user->id) && $bridge->isAvailable())
            || $coldStartReady;
    }

    // -------------------------------------------------------------------------
    // Primary action — PIN fallback (reused UNCHANGED from the Auth analog)
    // -------------------------------------------------------------------------

    /**
     * Validate the submitted PIN and unlock the session on success.
     *
     * Mirrors `Modules\Auth\Internal\Http\Livewire\LockScreen::submit()`
     * (lines 100-148) exactly — same validation, same PIN-verification
     * call, same backoff/attempts-remaining copy, same `intendedUrl`
     * redirect — via the Public gateway. The PIN pad is the
     * always-available fallback (UI-SPEC §4): "no layout change" means no
     * behavioral change here either.
     */
    public function submit(
        string $pin,
        CurrentUser $currentUser,
        MobileLockGateway $gateway,
        UrlGenerator $urls,
        Session $session,
        Clock $clock,
    ): void {
        if (preg_match('/^[0-9]{4,10}$/', $pin) !== 1) {
            $this->flashMessage = 'PIN must be at least 4 digits.';

            return;
        }

        $user = $currentUser->user();
        $dataKey = $gateway->verifyPin($user->id, $pin, $session);

        if ($dataKey === null) {
            $lockedUntil = $gateway->pinLockedUntil($user->id);
            if ($lockedUntil !== null) {
                $seconds = max(1, (int) ceil($clock->now()->diffInMilliseconds($lockedUntil, absolute: true) / 1000));
                $this->flashMessage = "Too many attempts — try again in {$seconds}s.";

                return;
            }

            $remaining = $gateway->remainingPinAttempts($user->id);
            $this->flashMessage = $remaining !== null
                ? "Incorrect PIN. {$remaining} attempts remaining."
                : 'Incorrect PIN.';

            return;
        }

        $this->redirectToIntendedUrl($session, $urls);
    }

    // -------------------------------------------------------------------------
    // Biometric prompt (mount-time auto-invoke — R6/D-03 Claude's Discretion:
    // biometric-primary means AUTO-INVOKED, not just visually-first)
    // -------------------------------------------------------------------------

    /**
     * Fire the native biometric prompt and, on success, route through the
     * SAME `AppLockKeyService::release()` read + `intendedUrl` redirect
     * `submit()` uses.
     *
     * Called from the Blade view's `x-init` the moment the component mounts
     * (when `$biometricAvailable` is true) — no tap required — and again on
     * an explicit tap of the biometric button for a manual retry.
     */
    public function biometricPrompt(
        BiometricUnlockBridge $bridge,
        AppLockKeyService $keyService,
        BiometricKeyVault $vault,
        MobileLockGateway $gateway,
        CurrentUser $currentUser,
        Session $session,
        UrlGenerator $urls,
    ): void {
        // Warm re-lock: the session still holds the data key. No enclave read
        // happens, so the (bypassable) bridge bool prompt is the right gate —
        // it confirms the holder before letting them back through. A false /
        // aborted bridge prompt releases nothing (T-15-14/T-15-15).
        if ($keyService->release($session) !== null) {
            if ($bridge->prompt()) {
                $this->redirectToIntendedUrl($session, $urls);
            }

            return;
        }

        // Cold start (no session key). Enforce the enrollment + PIN-floor gates
        // AT THE UNLOCK BOUNDARY, not just in mount()'s visibility flag — every
        // Livewire method is client-invokable regardless of what rendered, and
        // biometricAvailable is an OR that can be true via the warm-credential
        // clause even when cold-start is stale/floor-overdue. Refusing here:
        //  - not enrolled (or stale after an app-lock re-provision that reset
        //    the flag) → never admit a dead enclave blob wrapping an old key;
        //  - PIN floor overdue → force the periodic PIN re-auth.
        if (! $gateway->isColdStartEnrolled($currentUser->id()) || $gateway->pinFloorDue($currentUser->id())) {
            return;
        }

        // The enclave-gated vault IS the biometric gate. Do NOT also fire the
        // bridge prompt — that would be a second, redundant, bypassable prompt
        // that could falsely block a recover the enclave itself would allow.
        // recover() yields a key only after the OS releases the enclave entry
        // for a live biometric.
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

    /**
     * Android async completion: the native BiometricPrompt has already
     * authenticated and stashed the decrypted blob in a transient native slot,
     * then emitted a bare `cold-start-recovered` signal (NO key over the JS
     * bridge). Collect the blob PHP-side via completePendingRecover() and admit
     * on success. Nothing to admit → fall through to the PIN pad.
     */
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

    /**
     * Android async biometric failed / aborted — no state change; the
     * always-visible PIN pad remains the fallback (T-15-14/T-15-15).
     */
    #[On('cold-start-failed')]
    public function onColdStartFailed(): void
    {
        // Intentionally no-op: a failed/aborted biometric never releases a key.
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.mobile-lock-screen');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.lock', ['title' => 'Unlock · beatrax']);

        return $view;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Redirect to the originally-requested URL or the dashboard — the
     * shared success tail both `submit()` and `biometricPrompt()` use.
     */
    private function redirectToIntendedUrl(Session $session, UrlGenerator $urls): void
    {
        $intendedUrl = $session->pull('url.intended', $urls->route('dashboard'));
        if (! is_string($intendedUrl)) {
            $intendedUrl = $urls->route('dashboard');
        }

        $this->redirect($intendedUrl, navigate: false);
    }
}
