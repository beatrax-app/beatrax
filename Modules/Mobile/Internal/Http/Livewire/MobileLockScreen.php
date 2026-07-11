<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Livewire\Component;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
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
        Request $request,
    ): void {
        $user = $currentUser->user();
        $ua = $request->userAgent() ?? '';

        // Platform-aware label from the user-agent string — unchanged
        // behavior from the Auth analog (LockScreen lines 63-80), reached
        // via the Public gateway.
        $this->biometricLabel = $gateway->biometricLabel($ua);

        // Show the biometric trigger only when the user has at least one
        // armed enrolled credential AND the native bridge itself reports
        // the mobile runtime signal present right now.
        $this->biometricAvailable = $gateway->hasArmedBiometricCredential($user->id) && $bridge->isAvailable();
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
        Session $session,
        UrlGenerator $urls,
    ): void {
        if (! $bridge->prompt()) {
            // T-15-14/T-15-15: a false/aborted biometric NEVER releases the
            // key — no state change. The PIN pad stays visible underneath
            // as the always-available fallback.
            return;
        }

        if ($keyService->release($session) === null) {
            // The session genuinely has no data key yet — biometric alone
            // cannot derive one (LOCK-04's cryptographic root is the PIN).
            // Fall through silently; the PIN pad completes the unlock.
            return;
        }

        $this->redirectToIntendedUrl($session, $urls);
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
