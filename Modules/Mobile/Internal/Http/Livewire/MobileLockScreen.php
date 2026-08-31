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
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricUnlockBridge;

final class MobileLockScreen extends Component
{
    use HoldsFlashMessage;

    public bool $biometricAvailable = false;

    public string $biometricLabel = 'Use Face ID';

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

        // The trigger shows for either path: warm re-lock (armed credential
        // plus a reachable bridge) or a ready cold start.
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

    // Mirrors the Auth module's LockScreen::submit(): same validation,
    // verification call, backoff copy and redirect, via the Public gateway.
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
                ? Lang::choice('mobile::lock.errors.incorrect_pin_remaining', $remaining)
                : Lang::get('mobile::lock.errors.incorrect_pin');

            return;
        }

        $this->redirectToIntendedUrl($session, $urls);
    }

    // Auto-invoked from the view's x-init at mount, and again on an explicit
    // tap: biometric-primary means fired automatically, not merely listed
    // first. Success routes through the same release() gate submit() uses.
    public function biometricPrompt(
        BiometricUnlockBridge $bridge,
        AppLockKeyService $keyService,
        BiometricKeyVault $vault,
        MobileLockGateway $gateway,
        CurrentUser $currentUser,
        Session $session,
        UrlGenerator $urls,
    ): void {
        // Warm re-lock: the session still holds the data key, so no enclave
        // read happens and the bridge's bool prompt is the right gate — it
        // confirms the holder rather than producing a key.
        if ($keyService->release($session) !== null) {
            if ($bridge->prompt()) {
                $this->redirectToIntendedUrl($session, $urls);
            }

            return;
        }

        // Cold start. The gates are re-checked HERE at the unlock boundary
        // because every Livewire method is client-invokable regardless of
        // what mount() rendered.
        if (! $gateway->isColdStartEnrolled($currentUser->id()) || $gateway->pinFloorDue($currentUser->id())) {
            return;
        }

        // The enclave-gated vault IS the biometric gate: recover() yields a
        // key only after the OS releases the entry for a live biometric.
        // Firing the bridge prompt too would add a bypassable second one.
        $result = $vault->recover($currentUser->id());
        if ($result->isRecovered() && $result->dataKey !== null) {
            // Also stamps last_activity_at, or the idle-timeout middleware
            // re-locks this usually long-idle cold start immediately.
            $gateway->unlockWithRecoveredKey($currentUser->id(), $result->dataKey, $session);
            $this->redirectToIntendedUrl($session, $urls);
        }

        // Every other outcome changes no state: the PIN pad completes the
        // unlock. Android returns pending_async and answers via the events.
    }

    // Android async completion: the native prompt has already authenticated
    // and stashed the decrypted blob in a transient native slot.
    #[On('cold-start-recovered')]
    public function onColdStartRecovered(
        BiometricKeyVault $vault,
        MobileLockGateway $gateway,
        CurrentUser $currentUser,
        Session $session,
        UrlGenerator $urls,
    ): void {
        // The same boundary gates as the synchronous path: a stale enrollment
        // or overdue PIN floor must not admit, prompt or no prompt.
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
     * @link ../../../../../.docs/design/cold-start-biometric-unlock.md
     */
    #[On('cold-start-failed')]
    public function onColdStartFailed(): void
    {
        // Registered so the native prompt's failure lands somewhere, and
        // deliberately changing nothing: the PIN pad is already on screen, and
        // a failed authentication stashed no blob, so any write here would be
        // state the failure did not earn.
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.mobile-lock-screen');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.lock', ['title' => Lang::get('mobile::lock.page_title').' · Beatrax']);

        return $view;
    }

    // Two tiers, as on the desktop: `url.intended` exists only when the
    // middleware redirected here, while a client-engaged lock leaves only the
    // last page. Reading the first alone sent every unlock to the dashboard.
    private function redirectToIntendedUrl(Session $session, UrlGenerator $urls): void
    {
        $intended = $session->pull(MobileLockGateway::SESSION_INTENDED_URL);
        $lastPage = $session->pull(MobileLockGateway::SESSION_LAST_PAGE);

        $target = match (true) {
            is_string($intended) && $intended !== '' => $intended,
            is_string($lastPage) && $lastPage !== '' => $lastPage,
            default => Destination::Dashboard->urlFrom($urls),
        };

        $this->redirect($target, navigate: false);
    }
}
