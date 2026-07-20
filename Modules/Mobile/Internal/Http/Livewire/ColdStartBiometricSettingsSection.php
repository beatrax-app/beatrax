<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\ColdStartEnrollmentService;

/**
 * Settings section (mobile) for cold-start biometric unlock — enable/disable.
 *
 * Enrollment is PIN-rooted (Decision: PIN floor): enabling requires the user
 * to re-enter their PIN NOW, which both confirms intent and yields the live
 * data key that gets wrapped into the enclave. Disabling clears the enclave
 * entry. The section only functions on a biometric-capable build (the vault is
 * unavailable on web/desktop → the row shows an empty-state and no toggle).
 *
 * Mirrors `Modules\Auth\Internal\Http\Livewire\AppLockSettingsSection`'s
 * structure (Validate attrs, method-DI actions, flash message) and the
 * calm-slate visual contract.
 */
final class ColdStartBiometricSettingsSection extends Component
{
    /** Whether cold-start biometric unlock is currently enrolled. */
    public bool $enrolled = false;

    /** Whether the enclave-backed vault is available on this build/device. */
    public bool $available = false;

    /** PIN entered to authorize enable (4–10 digits). */
    #[Validate('nullable|regex:/^[0-9]{4,10}$/')]
    public string $pin = '';

    /** Rose-tinted status/error line shown above the row. */
    public string $flashMessage = '';

    public function mount(
        CurrentUser $currentUser,
        BiometricKeyVault $vault,
        ColdStartEnrollmentService $enrollment,
    ): void {
        $this->available = $vault->isAvailable();
        $this->enrolled = $this->available && $enrollment->isEnrolled($currentUser->id());
    }

    /**
     * Enable: re-verify the PIN and wrap the live data key into the enclave.
     */
    public function enable(
        CurrentUser $currentUser,
        ColdStartEnrollmentService $enrollment,
        Session $session,
    ): void {
        $this->flashMessage = '';

        if (! $this->available) {
            $this->flashMessage = 'Biometric unlock is not available on this device.';

            return;
        }

        // Capture + clear the PIN property BEFORE any work so an exception in
        // enroll() (DB error, backoff) can never leave the entered PIN in the
        // component's public state / Livewire snapshot.
        $pin = $this->pin;
        $this->pin = '';

        if (preg_match('/^[0-9]{4,10}$/', $pin) !== 1) {
            $this->flashMessage = 'Enter your PIN (4–10 digits) to enable biometric unlock.';

            return;
        }

        if (! $enrollment->enroll($currentUser->id(), $pin, $session)) {
            $this->flashMessage = 'Could not enable biometric unlock — check your PIN and try again.';

            return;
        }

        $this->enrolled = true;
    }

    /**
     * Disable: clear the enclave entry and reset the enrollment flag.
     */
    public function disable(
        CurrentUser $currentUser,
        ColdStartEnrollmentService $enrollment,
    ): void {
        $enrollment->disable($currentUser->id());
        $this->enrolled = false;
        $this->flashMessage = '';
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('mobile::livewire.cold-start-biometric-settings-section');
    }
}
