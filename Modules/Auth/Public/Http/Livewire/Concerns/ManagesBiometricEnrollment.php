<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Http\Livewire\Concerns;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Session\Session;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Auth\Internal\Lock\AppLockCredentialRejections;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\BrowserEnrollmentAuthorizer;
use Modules\Auth\Internal\Lock\ColdStartEnroller;
use Modules\Auth\Internal\Lock\ColdStartEnrollmentResult;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Support\Lang;

// Arming and disarming a durable wrap of the data key: one concern, and not the
// one that provisions the code doing the wrapping. It moved out whole when the
// browser road grew its own PIN gate, which is three methods more than the
// settings component had room for.
/**
 * @phpstan-require-extends Component
 */
trait ManagesBiometricEnrollment
{
    // Half of a browser round trip: lock.js answers 'beatrax:webauthn-create'
    // by POSTing an attestation to /lock/biometric/enroll, then dispatching
    // 'biometric-enrolled' back here.
    public function startEnroll(
        ColdStartVault $vault,
        ConfigRepository $config,
        SecretShield $shield,
    ): void {
        if (! $this->lockEnabled) {
            $this->flashMessage = Lang::get('auth::app_lock.error_enable_first');

            return;
        }

        // Both roads ask for the PIN, and the browser one is asked whether it
        // exists first: a reader on a dead road should be told so rather than
        // handed a code box that leads nowhere.
        $refusal = $vault->isAvailable() ? null : $this->browserEnrollmentRefusal($config, $shield);

        if ($refusal !== null) {
            $this->flashMessage = $refusal;

            return;
        }

        $this->confirmingEnroll = true;
        $this->flashMessage = '';
    }

    // Reached only once the OS vault above turned out to be unavailable: both
    // answers here are about the browser road specifically, and a device with
    // its own vault never travels it.
    private function browserEnrollmentRefusal(ConfigRepository $config, SecretShield $shield): ?string
    {
        return match (true) {
            // Same dead-button case as an unavailable vault, with nothing left
            // to fall back on: say so rather than dispatching into nothing.
            $config->get('nativephp-internal.running') === true => Lang::get('auth::app_lock.error_enroll_unsupported'),
            // The browser path persists the unwrapping key beside the key it
            // unwraps, in the same file as the ledger. Only a shield that really
            // makes those bytes unreadable earns that; a self-hosted web install
            // binds the pass-through, and the enrolment routes refuse there too.
            ! $shield->protectsAtRest() => Lang::get('auth::app_lock.error_enroll_unprotected'),
            default => null,
        };
    }

    // Either entry is a durable way back to the data key that a biometric alone
    // opens, and both outlive the session that made them, so both cost what
    // removing one costs. The box in front of the reader is a gate rather than
    // a formality because the PIN is what produces the key being wrapped.
    public function enrollWithPin(
        string $pin,
        CurrentUser $currentUser,
        ColdStartEnroller $enroller,
        BrowserEnrollmentAuthorizer $browser,
        ColdStartVault $vault,
        AppLockCredentialRejections $rejections,
        Session $session,
    ): void {
        $rejection = $rejections->pinRequired($pin);

        if ($rejection !== null) {
            $this->flashMessage = $rejection;

            return;
        }

        $vault->isAvailable()
            ? $this->armTheOsVault($enroller->enroll($currentUser->user()->id, $pin, $session))
            : $this->armTheBrowser($browser->authorize($currentUser->user()->id, $pin, $session));
    }

    // The panel stays open on a refusal for the same reason the disable one
    // does: another PIN is an answer the reader can still give.
    private function armTheOsVault(ColdStartEnrollmentResult $result): void
    {
        if ($result !== ColdStartEnrollmentResult::Enrolled) {
            $this->flashMessage = $result === ColdStartEnrollmentResult::PinRejected
                ? Lang::get('auth::app_lock.error_pin_incorrect')
                : Lang::get('auth::app_lock.error_enroll_failed');

            return;
        }

        $this->biometricEnrolled = true;
        $this->confirmingEnroll = false;
        $this->flashMessage = '';
    }

    // The ceremony leaves for the browser here and answers on its own route, so
    // this closes the panel on a PIN it accepted rather than on an enrolment it
    // has seen. onBiometricEnrolled() and onBiometricEnrollmentFailed() are the
    // two ways it comes back.
    private function armTheBrowser(bool $authorized): void
    {
        if (! $authorized) {
            $this->flashMessage = Lang::get('auth::app_lock.error_pin_incorrect');

            return;
        }

        $this->confirmingEnroll = false;
        $this->flashMessage = '';

        $this->dispatch('beatrax:webauthn-create');
    }

    #[On('biometric-enrolled')]
    public function onBiometricEnrolled(): void
    {
        $this->biometricEnrolled = true;
        $this->flashMessage = '';
    }

    // The browser road used to answer a refusal with nothing at all: the fetch
    // read `enrolled`, found it false, and returned. A proof that ran out while
    // the reader was reaching for the sensor is the one refusal they can act
    // on, so it is the one that gets its own line.
    #[On('biometric-enrol-failed')]
    public function onBiometricEnrollmentFailed(string $reason = ''): void
    {
        $this->flashMessage = Lang::get($reason === 'pin_not_proved'
            ? 'auth::app_lock.error_enroll_pin_expired'
            : 'auth::app_lock.error_enroll_failed');
    }

    public function confirmDeenroll(): void
    {
        $this->confirmingDeenroll = true;
    }

    public function deenroll(
        string $pin,
        CurrentUser $currentUser,
        BiometricDeviceStore $biometricStore,
        AppLockProvisioner $provisioner,
        ColdStartVault $vault,
        AppLockCredentialRejections $rejections,
    ): void {
        $rejection = $rejections->pinRequired($pin);

        if ($rejection !== null) {
            $this->flashMessage = $rejection;

            return;
        }

        $user = $currentUser->user();

        if (! $provisioner->verifyPin($user->id, $pin)) {
            $this->flashMessage = Lang::get('auth::app_lock.error_pin_incorrect');

            return;
        }

        $biometricStore->deleteForUser($user->id);
        $cleared = $vault->forget($user->id);

        // Read back rather than assumed: the vault answers isEnrolled() from
        // its own storage, so a refused removal leaves an enrolment this
        // screen would otherwise show as gone until the next full render.
        $this->biometricEnrolled = $vault->isEnrolled($user->id);
        $this->confirmingDeenroll = false;

        // Saying nothing about a key the OS would not release tells the reader
        // it was destroyed.
        $this->flashMessage = $cleared
            ? ''
            : Lang::get('auth::app_lock.error_vault_kept_key');
    }
}
