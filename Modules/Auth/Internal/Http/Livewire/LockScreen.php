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
use Modules\Core\Public\Support\Lang;

/**
 * @link ../../../../../.docs/features/auth/architecture.md
 */
final class LockScreen extends Component
{
    public string $flashMessage = '';

    public bool $biometricAvailable = false;

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

        $this->biometricLabel = $detector->detectLabel($ua);

        $credentials = $biometricStore->findForUser($user->id);
        $this->biometricAvailable = $credentials->contains(
            fn (object $cred): bool => $biometricStore->isArmed($cred)
        );
    }

    // -------------------------------------------------------------------------
    // Primary action
    // -------------------------------------------------------------------------

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
        if (preg_match('/^\d{4,10}$/', $pin) !== 1) {
            $this->flashMessage = Lang::get('auth::lock_screen.error_too_short');

            return;
        }

        $user = $currentUser->user();
        $dataKey = $verifier->verify($user->id, $pin, $session);

        if ($dataKey === null) {
            // During an active locked_until window, verify() returns null
            // BEFORE checking the PIN, so even a correct PIN lands here --
            // this must distinguish "backoff active" from "wrong PIN".
            $lockedUntil = $verifier->lockedUntil($user->id);
            if ($lockedUntil !== null) {
                $seconds = max(1, (int) ceil($clock->now()->diffInMilliseconds($lockedUntil, absolute: true) / 1000));
                $this->flashMessage = Lang::get('auth::lock_screen.error_backoff', ['wait' => $seconds.'s']);

                return;
            }

            $remaining = $this->remainingAttempts($user->id, $db);
            $this->flashMessage = $remaining !== null
                ? Lang::get('auth::lock_screen.error_incorrect_remaining', ['remaining' => $remaining])
                : Lang::get('auth::lock_screen.error_incorrect');

            return;
        }

        $intendedUrl = $session->pull('url.intended', $urls->route('dashboard'));
        if (! is_string($intendedUrl)) {
            $intendedUrl = $urls->route('dashboard');
        }

        $this->redirect($intendedUrl, navigate: false);
    }

    // -------------------------------------------------------------------------
    // Biometric prompt (button-tap-to-prompt, no auto-fire)
    // -------------------------------------------------------------------------

    // lock.js listens for 'beatrax:webauthn-get', calls
    // navigator.credentials.get with the requestOptions fetched from
    // /lock/biometric/challenge, then POSTs the assertion to
    // /lock/biometric/verify.
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
        $view->extends('layouts.lock', ['title' => Lang::get('auth::lock_screen.page_title')]);

        return $view;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    // Returns null when the config row is absent or the failed-attempt
    // count cannot be read.
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
