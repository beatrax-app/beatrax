<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\ColdStartEnrollmentService;

/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class ColdStartBiometricSettingsSection extends Component
{
    public bool $enrolled = false;

    public bool $available = false;

    #[Validate('nullable|regex:/^[0-9]{4,10}$/')]
    public string $pin = '';

    public string $flashMessage = '';

    public function mount(
        CurrentUser $currentUser,
        BiometricKeyVault $vault,
        ColdStartEnrollmentService $enrollment,
    ): void {
        $this->available = $vault->isAvailable();
        $this->enrolled = $this->available && $enrollment->isEnrolled($currentUser->id());
    }

    public function enable(
        CurrentUser $currentUser,
        ColdStartEnrollmentService $enrollment,
        Session $session,
    ): void {
        $this->flashMessage = '';

        if (! $this->available) {
            $this->flashMessage = Lang::get('mobile::biometric.errors.unavailable');

            return;
        }

        // Capture + clear the PIN property BEFORE any work so an exception in
        // enroll() (DB error, backoff) can never leave the entered PIN in the
        // component's public state / Livewire snapshot.
        $pin = $this->pin;
        $this->pin = '';

        if (preg_match('/^\d{4,10}$/', $pin) !== 1) {
            $this->flashMessage = Lang::get('mobile::biometric.errors.pin_required');

            return;
        }

        if (! $enrollment->enroll($currentUser->id(), $pin, $session)) {
            $this->flashMessage = Lang::get('mobile::biometric.errors.enroll_failed');

            return;
        }

        $this->enrolled = true;
    }

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
