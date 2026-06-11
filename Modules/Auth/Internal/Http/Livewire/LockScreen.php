<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * The /lock route — calm-slate PIN entry surface.
 *
 * Threat mitigations:
 *   T-05-11: PIN digits are never rendered in an <input>; the DOM only
 *             shows bullet glyphs, so no autocomplete, clipboard, or OS
 *             password-manager capture occurs.
 *   T-05-12: PIN digits never leave the component until submit(); the
 *             raw string is forwarded to PinVerificationService which
 *             sodium_memzero's derived keys after use.
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
    /** Digits collected from the pad (never rendered in <input>). */
    public string $pin = '';

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

    public function mount(CurrentUser $currentUser): void
    {
        // Platform-aware label (best-effort from user agent context).
        // 05-05 will also set $biometricAvailable based on enrolled credentials.
        $this->biometricLabel = 'Use Touch ID';
    }

    // -------------------------------------------------------------------------
    // PIN pad actions
    // -------------------------------------------------------------------------

    /** Append a digit (0-9) — capped at 10 digits. */
    public function pressDigit(string $d): void
    {
        if (strlen($this->pin) < 10 && preg_match('/^[0-9]$/', $d) === 1) {
            $this->pin .= $d;
        }
    }

    /** Remove the last entered digit. */
    public function backspace(): void
    {
        $this->pin = substr($this->pin, 0, -1);
    }

    /** Reset PIN to empty (e.g. after a failed attempt). */
    public function clearPin(): void
    {
        $this->pin = '';
    }

    // -------------------------------------------------------------------------
    // Primary action
    // -------------------------------------------------------------------------

    /**
     * Validate the PIN and unlock the session on success.
     *
     * On success: the session is unlocked, the browser is redirected to the
     * originally-requested page (or dashboard).
     *
     * On failure: $flashMessage is set with attempts-remaining copy and
     * $pin is reset.
     */
    public function submit(
        CurrentUser $currentUser,
        PinVerificationService $verifier,
        LockStateManager $lockState,
        UrlGenerator $urls,
        Session $session,
        DatabaseManager $db,
    ): void {
        if (preg_match('/^[0-9]{4,10}$/', $this->pin) !== 1) {
            $this->flashMessage = 'PIN must be at least 4 digits.';

            return;
        }

        $user = $currentUser->user();
        $dataKey = $verifier->verify($user->id, $this->pin, $session);

        if ($dataKey === null) {
            // Read remaining attempts to surface actionable copy.
            $remaining = $this->remainingAttempts($user->id, $db);
            $this->flashMessage = $remaining !== null
                ? "Incorrect PIN. {$remaining} attempts remaining."
                : 'Incorrect PIN.';
            $this->clearPin();

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
    // Idle timeout signal (from Alpine beatraxLock store — D-17)
    // -------------------------------------------------------------------------

    /**
     * Dispatched by lock.js when the idle window expires.
     *
     * Locks the session server-side and redirects to the lock screen. This
     * keeps the server authoritative (D-17); the client timer is a
     * convenience trigger only.
     */
    #[On('idle-timeout-elapsed')]
    public function idleLock(
        LockStateManager $lockState,
        Session $session,
        UrlGenerator $urls,
    ): void {
        $lockState->lock($session);
        $this->redirect($urls->route('auth.lock'), navigate: false);
    }

    // -------------------------------------------------------------------------
    // Biometric stub (05-05 implements)
    // -------------------------------------------------------------------------

    /**
     * No-op stub — plan 05-05 wires up the WebAuthn assertion flow.
     * The button calls this so it never errors before 05-05 lands.
     */
    public function biometricPrompt(): void
    {
        // Stub — 05-05 implements
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.lock-screen');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Unlock · beatrax']);

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

        $hardCap = 10; // mirrors PinVerificationService::HARD_CAP
        $remaining = $hardCap - (int) $failed;

        return max(0, $remaining);
    }
}
