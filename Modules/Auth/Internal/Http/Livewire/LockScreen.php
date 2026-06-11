<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Livewire\Component;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * The /lock route — calm-slate PIN entry surface.
 *
 * Threat mitigations:
 *   T-05-11: PIN digits are never rendered in an <input>; the DOM only
 *             shows bullet glyphs, so no autocomplete, clipboard, or OS
 *             password-manager capture occurs.
 *   T-05-12: PIN digits accumulate CLIENT-SIDE in transient Alpine state
 *             (WR-10 fix) — no per-keypress network round-trip, and the PIN
 *             is never bound to a public Livewire property, so it never
 *             appears in the wire:snapshot DOM attribute or in Livewire
 *             update responses. The full PIN crosses the wire exactly once,
 *             as a submit() method argument, and is forwarded to
 *             PinVerificationService which sodium_memzero's derived keys
 *             after use.
 *   T-05-13: Idle dispatch only requests a server-side lock; the server
 *             last_activity_at is the authoritative source (D-17).
 *
 * Design contract (D-03): the lock screen offers exactly three actions —
 * PIN pad, biometric prompt, Sign out — and nothing else.
 *
 * Constructor-free Livewire component; service collaborators arrive as
 * parameters on action methods and render().
 */
final class LockScreen extends Component
{

    /** Rose-tinted error / status message shown above the pad. */
    public string $flashMessage = '';

    /**
     * Whether the device has a registered biometric credential.
     * Default: false — plan 05-05 sets this from enrolled credentials.
     */
    public bool $biometricAvailable = false;

    /** User-facing label for the biometric button (platform-aware). */
    public string $biometricLabel = 'Use Touch ID';

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function mount(
        CurrentUser $currentUser,
        BiometricDeviceStore $biometricStore,
        PlatformDetector $detector,
        Request $request,
    ): void {
        $user = $currentUser->user();
        $ua = $request->userAgent() ?? '';

        // Platform-aware label from the user-agent string.
        $this->biometricLabel = $detector->detectLabel($ua);

        // Show biometric button only when the user has at least one armed credential.
        $credentials = $biometricStore->findForUser($user->id);
        $this->biometricAvailable = $credentials->contains(
            fn (object $cred): bool => $biometricStore->isArmed($cred)
        );
    }

    // -------------------------------------------------------------------------
    // Primary action
    // -------------------------------------------------------------------------

    /**
     * Validate the submitted PIN and unlock the session on success.
     *
     * $pin arrives as a method argument from the client-side Alpine pad
     * (WR-10): it is never bound to a public property, so it does not enter
     * the component snapshot or subsequent responses. The client clears its
     * local copy immediately on submit.
     *
     * On success: the session is unlocked, the browser is redirected to the
     * originally-requested page (or dashboard).
     *
     * On failure: $flashMessage is set with attempts-remaining (or backoff)
     * copy; the client-side pad is already empty.
     */
    public function submit(
        string $pin,
        CurrentUser $currentUser,
        PinVerificationService $verifier,
        LockStateManager $lockState,
        UrlGenerator $urls,
        Session $session,
        DatabaseManager $db,
        Clock $clock,
    ): void {
        if (preg_match('/^[0-9]{4,10}$/', $pin) !== 1) {
            $this->flashMessage = 'PIN must be at least 4 digits.';

            return;
        }

        $user = $currentUser->user();
        $dataKey = $verifier->verify($user->id, $pin, $session);

        if ($dataKey === null) {
            // WR-05: distinguish "backoff active" from "wrong PIN" — during
            // an active locked_until window verify() returns null BEFORE
            // checking the PIN, so even a correct PIN lands here and must
            // not be reported as incorrect.
            $lockedUntil = $verifier->lockedUntil($user->id);
            if ($lockedUntil !== null) {
                $seconds = max(1, (int) ceil($clock->now()->diffInMilliseconds($lockedUntil, absolute: true) / 1000));
                $this->flashMessage = "Too many attempts — try again in {$seconds}s.";

                return;
            }

            // Read remaining attempts to surface actionable copy.
            $remaining = $this->remainingAttempts($user->id, $db);
            $this->flashMessage = $remaining !== null
                ? "Incorrect PIN. {$remaining} attempts remaining."
                : 'Incorrect PIN.';

            return;
        }

        // Success — redirect to the originally-requested URL or the dashboard.
        $intendedUrl = $session->pull('url.intended', $urls->route('dashboard'));
        if (! is_string($intendedUrl)) {
            $intendedUrl = $urls->route('dashboard');
        }

        $this->redirect($intendedUrl, navigate: false);
    }

    // -------------------------------------------------------------------------
    // Biometric prompt (D-15: button-tap-to-prompt, no auto-fire)
    // -------------------------------------------------------------------------

    /**
     * Dispatch a browser event that tells lock.js to invoke navigator.credentials.get.
     *
     * D-15: This method is only called when the user taps the biometric button.
     * The browser event is NOT dispatched on render — no auto-firing.
     *
     * lock.js listens for 'beatrax:webauthn-get', calls navigator.credentials.get
     * with the requestOptions fetched from /lock/biometric/challenge, then POSTs
     * the assertion to /lock/biometric/verify.  On success, lock.js navigates
     * to the redirect URL returned by the verify endpoint.
     */
    public function biometricPrompt(): void
    {
        $this->dispatch('beatrax:webauthn-get');
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.lock-screen');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.lock', ['title' => 'Unlock · beatrax']);

        return $view;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return the number of PIN attempts remaining before the hard cap,
     * or null when the config row is absent / the count cannot be read.
     */
    private function remainingAttempts(int $userId, DatabaseManager $db): ?int
    {
        $row = $db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['failed_attempts']);

        if ($row === null) {
            return null;
        }

        $failed = $row->failed_attempts;
        if (! is_int($failed) && ! is_string($failed)) {
            return null;
        }

        $remaining = PinVerificationService::HARD_CAP - (int) $failed;

        return max(0, $remaining);
    }
}
