<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Livewire\Component;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Services\MobileLockGateway;
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

    // The OS-gated key vault (Touch ID on desktop, the enclave on mobile) as
    // distinct from the WebAuthn credential above: this one hands back the
    // data key, so it can unlock on its own.
    public bool $nativeUnlockAvailable = false;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function mount(
        CurrentUser $currentUser,
        BiometricDeviceStore $biometricStore,
        PlatformDetector $detector,
        Request $request,
        ColdStartVault $vault,
        MobileLockGateway $gateway,
    ): void {
        $user = $currentUser->user();
        $ua = $request->userAgent() ?? '';

        $this->biometricLabel = $detector->detectLabel($ua);

        $credentials = $biometricStore->findForUser($user->id);
        $this->biometricAvailable = $credentials->contains(
            fn (object $cred): bool => $biometricStore->isArmed($cred)
        );

        // The stored flag as well as the vault's own answer. The desktop vault
        // reports enrolment from a file keyed on nothing but the user id, so a
        // database reset behind it offered the next account to take that id an
        // enrolment it never made. The mobile vault already reads this flag.
        $this->nativeUnlockAvailable = $vault->isAvailable()
            && $vault->isEnrolled($user->id)
            && $gateway->isColdStartEnrolled($user->id);
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
        ColdStartVault $vault,
        MobileLockGateway $gateway,
    ): void {
        if (preg_match('/^\d{6,10}$/', $pin) !== 1) {
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

        // Enrol on the way through: this is the only moment the raw data key
        // is in hand, and the vault re-protects it under the OS gate. The flag
        // is written with it, so the enrolment dies with the account row
        // instead of surviving as a file that outlives the user it was for.
        if ($vault->isAvailable() && ! $vault->isEnrolled($user->id) && $vault->enroll($user->id, $dataKey)) {
            $gateway->markColdStartEnrolled($user->id, true);
        }

        $this->redirect($this->intendedUrl($session, $urls), navigate: false);
    }

    // Unlocks from the OS-gated vault. The vault prompts and returns the key
    // only on a successful authentication, so a false/null here is a refusal
    // and never a partial unlock.
    public function nativeUnlock(
        CurrentUser $currentUser,
        ColdStartVault $vault,
        MobileLockGateway $gateway,
        Session $session,
        UrlGenerator $urls,
    ): void {
        $user = $currentUser->user();
        $dataKey = $vault->recover($user->id, Lang::get('auth::lock_screen.native_unlock_reason'));

        if ($dataKey === null) {
            $this->flashMessage = Lang::get('auth::lock_screen.native_unlock_failed');

            return;
        }

        $gateway->unlockWithRecoveredKey($user->id, $dataKey, $session);

        $this->redirect($this->intendedUrl($session, $urls), navigate: false);
    }

    // Shared by both unlock paths so a PIN and a biometric unlock land on
    // the same page.
    private function intendedUrl(Session $session, UrlGenerator $urls): string
    {
        $intended = $session->pull('url.intended');
        $lastPage = $session->pull(AppLockMiddleware::SESSION_LAST_PAGE);

        // `url.intended` only exists when the middleware itself redirected
        // here; an idle lock engaged from the client leaves only the last
        // page the user was on.
        return match (true) {
            is_string($intended) && $intended !== '' => $intended,
            is_string($lastPage) && $lastPage !== '' => $lastPage,
            default => $urls->route('dashboard'),
        };
    }

    // -------------------------------------------------------------------------
    // Biometric prompt (button-tap-to-prompt, no auto-fire)
    // -------------------------------------------------------------------------

    // lock.js listens for 'beatrax:webauthn-get', calls
    // navigator.credentials.get with the requestOptions fetched from
    // /lock/biometric/challenge, then POSTs the assertion to
    // /lock/biometric/verify.
    public function biometricPrompt(ConfigRepository $config): void
    {
        // No WebAuthn platform authenticator exists behind the desktop shell,
        // so the prompt would resolve to nothing and the button would read as
        // broken. The OS-gated vault above is that platform's real path.
        if ($config->get('nativephp-internal.running') === true) {
            $this->flashMessage = Lang::get('auth::lock_screen.native_unlock_failed');

            return;
        }

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
