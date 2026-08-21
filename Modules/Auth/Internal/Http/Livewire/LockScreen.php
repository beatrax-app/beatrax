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
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Support\Lang;

final class LockScreen extends Component
{
    use HoldsFlashMessage;

    public bool $biometricAvailable = false;

    public string $biometricLabel = 'Use Touch ID';

    // The OS vault hands back the data key itself, so unlike the WebAuthn
    // credential behind $biometricAvailable it can unlock on its own.
    public bool $nativeUnlockAvailable = false;

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

        // The flag as well as the vault: the desktop vault keys its file on
        // the user id alone, so after a database reset it offered the next
        // account to take that id an enrolment it never made.
        $this->nativeUnlockAvailable = $vault->isAvailable()
            && $vault->isEnrolled($user->id)
            && $gateway->isColdStartEnrolled($user->id);
    }

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
            // verify() returns null before checking the PIN during a backoff
            // window, so a correct PIN lands here too and must be told apart.
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

        // The only moment the raw data key is in hand. The flag is written
        // alongside so the enrolment dies with the account row rather than
        // surviving as a file that outlives the user it was for.
        if ($vault->isAvailable() && ! $vault->isEnrolled($user->id) && $vault->enroll($user->id, $dataKey)) {
            $gateway->markColdStartEnrolled($user->id, true);
        }

        $this->redirect($this->intendedUrl($session, $urls), navigate: false);
    }

    // The vault returns the key only on a successful prompt, so a null here
    // is a refusal and never a partial unlock.
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

    private function intendedUrl(Session $session, UrlGenerator $urls): string
    {
        $intended = $session->pull('url.intended');
        $lastPage = $session->pull(AppLockMiddleware::SESSION_LAST_PAGE);

        // `url.intended` exists only when the middleware redirected here; a
        // client-engaged idle lock leaves nothing but the last page.
        return match (true) {
            is_string($intended) && $intended !== '' => $intended,
            is_string($lastPage) && $lastPage !== '' => $lastPage,
            default => $urls->route('dashboard'),
        };
    }

    // Half of a browser round trip: lock.js answers 'beatrax:webauthn-get' by
    // POSTing an assertion to /lock/biometric/verify.
    public function biometricPrompt(ConfigRepository $config): void
    {
        // navigator.credentials.get() resolves to nothing behind the desktop
        // shell, reading as a dead button; the OS vault is its real path.
        if ($config->get('nativephp-internal.running') === true) {
            $this->flashMessage = Lang::get('auth::lock_screen.native_unlock_failed');

            return;
        }

        $this->dispatch('beatrax:webauthn-get');
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('auth::livewire.lock-screen');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.lock', ['title' => Lang::get('auth::lock_screen.page_title')]);

        return $view;
    }

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
