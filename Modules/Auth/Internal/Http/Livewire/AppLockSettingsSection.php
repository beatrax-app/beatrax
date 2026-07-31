<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\EncryptionMigrationService;

/**
 * @link ../../../../../.docs/features/auth/architecture.md
 */
final class AppLockSettingsSection extends Component
{
    private const PIN_TOO_SHORT = 'PIN must be at least 4 digits.';

    private const PIN_MISMATCH = 'PINs don\'t match. Try again.';

    private const PIN_RULES = 'nullable|regex:/^[0-9]{4,10}$/';

    private const PIN_INCORRECT = 'Incorrect PIN.';

    public bool $lockEnabled = false;

    public bool $biometricEnrolled = false;

    // Server-side cannot know WebAuthn capability; JS overwrites this to
    // true on mount when the browser reports it is capable.
    public bool $biometricCapable = false;

    public string $biometricLabel = 'biometric unlock';

    public bool $confirmingDeenroll = false;

    #[Validate(self::PIN_RULES)]
    public string $deenrollPin = '';

    // Applied without PIN confirmation -- unlike every other mutation on
    // this component, narrowing the auto-lock window never touches key
    // material, so it is exempt from the confirmation requirement.
    #[Validate('required|integer|in:1,5,15,30')]
    public int $idleTimeoutMinutes = 5;

    #[Validate(self::PIN_RULES)]
    public string $newPin = '';

    #[Validate(self::PIN_RULES)]
    public string $confirmPin = '';

    #[Validate(self::PIN_RULES)]
    public string $currentPin = '';

    // Used transiently to build the password recovery wrap; never stored
    // beyond the request that consumes it.
    #[Validate('nullable|string')]
    public string $accountPassword = '';

    public string $flashMessage = '';

    public bool $confirmingDisable = false;

    public bool $confirmingChangePin = false;

    public bool $confirmingForgotPin = false;

    // Distinct from $flashMessage (reserved for error/alert copy) -- set
    // only after a successful changePin(), gated on an encrypted keyring
    // already existing for this user. Empty string renders nothing.
    public string $changePinSuccessMessage = '';

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function mount(
        CurrentUser $currentUser,
        DatabaseManager $db,
        BiometricDeviceStore $biometricStore,
        PlatformDetector $detector,
        Request $request,
        Clock $clock,
    ): void {
        $user = $currentUser->user();

        $row = $db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $user->id)
            ->first(['lock_enabled', 'idle_timeout_minutes']);

        if ($row === null) {
            // Bootstrap a default row so subsequent reads never need
            // null-guarding. updateOrInsert does not manage timestamps, so
            // they are set explicitly.
            $now = $clock->now()->toDateTimeString();
            $db->connection()->table('user_app_lock_configs')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'lock_enabled' => false,
                    'idle_timeout_minutes' => 5,
                    'failed_attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            $this->lockEnabled = false;
            $this->idleTimeoutMinutes = 5;
        } else {
            $this->lockEnabled = (bool) $row->lock_enabled;
            $idleRaw = $row->idle_timeout_minutes;
            $idle = is_numeric($idleRaw) ? (int) $idleRaw : 5;
            $this->idleTimeoutMinutes = in_array($idle, [1, 5, 15, 30], true) ? $idle : 5;
        }

        $credentials = $biometricStore->findForUser($user->id);
        $this->biometricEnrolled = $credentials->contains(
            fn (object $cred): bool => $biometricStore->isArmed($cred)
        );

        $ua = $request->userAgent() ?? '';
        $this->biometricLabel = $detector->detectLabel($ua);

        // $biometricCapable is set client-side via JS (window.PublicKeyCredential
        // check); the server default of false is overwritten by lock.js.
    }

    // -------------------------------------------------------------------------
    // Action: enable lock / set PIN
    // -------------------------------------------------------------------------

    public function setPin(CurrentUser $currentUser, AppLockProvisioner $provisioner, Hasher $hasher, Session $session): void
    {
        // enable() generates a NEW data key (and clears biometric
        // enrollments). Re-running it on an already-enabled lock would
        // silently rotate the key; PIN changes must go through changePin()
        // instead.
        if ($this->lockEnabled) {
            return;
        }

        $error = $this->newPinValidationError();
        if ($error !== null) {
            $this->flashMessage = $error;

            return;
        }

        $user = $currentUser->user();

        if (! $hasher->check($this->accountPassword, $user->password)) {
            $this->flashMessage = 'Incorrect account password.';

            return;
        }

        // Passing the session lets enable() store the data key immediately:
        // the user just authenticated, so the session should be unlocked
        // with the key available, not key-less/locked.
        $provisioner->enable($user->id, $this->newPin, $this->accountPassword, $session);

        $this->lockEnabled = true;
        $this->flashMessage = '';

        $this->newPin = '';
        $this->confirmPin = '';
        $this->accountPassword = '';

        // Plain browser-event name (no cross-module PHP dependency) so
        // sibling sections update their app-lock-gated UI live, without a
        // reload, the moment a lock now exists.
        $this->dispatch('app-lock-configured');
    }

    // -------------------------------------------------------------------------
    // Action: idle timeout (instant-apply, no PIN confirmation)
    // -------------------------------------------------------------------------

    public function setIdleTimeout(CurrentUser $currentUser, DatabaseManager $db, Clock $clock): void
    {
        $this->validateOnly('idleTimeoutMinutes');

        $user = $currentUser->user();
        $db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $user->id)
            ->update([
                'idle_timeout_minutes' => $this->idleTimeoutMinutes,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);
    }

    // -------------------------------------------------------------------------
    // Action: disable lock (requires current PIN)
    // -------------------------------------------------------------------------

    public function confirmDisable(): void
    {
        $this->confirmingDisable = true;
        $this->currentPin = '';
    }

    public function disable(CurrentUser $currentUser, AppLockProvisioner $provisioner): void
    {
        $user = $currentUser->user();
        $result = $provisioner->disable($user->id, $this->currentPin);

        if ($result === false) {
            $this->flashMessage = self::PIN_INCORRECT;

            return;
        }

        $this->lockEnabled = false;
        // provisioner->disable() deleted all biometric credentials (their
        // wraps held the now-destroyed data key) -- mirror that in the UI.
        $this->biometricEnrolled = false;
        $this->confirmingDisable = false;
        $this->currentPin = '';
        $this->flashMessage = '';
    }

    // -------------------------------------------------------------------------
    // Action: change PIN (requires current PIN + new PIN)
    // -------------------------------------------------------------------------

    public function confirmChangePin(): void
    {
        $this->confirmingChangePin = true;
        $this->currentPin = '';
        $this->newPin = '';
        $this->confirmPin = '';
        $this->changePinSuccessMessage = '';
    }

    // On success, $changePinSuccessMessage surfaces the re-secured-encryption
    // note purely for the user-visible confirmation copy -- the keyring
    // re-wrap itself happens invisibly via the AppLockPassphraseChanged
    // event, dispatched inside AppLockProvisioner::changePin().
    public function changePin(CurrentUser $currentUser, AppLockProvisioner $provisioner, EncryptionMigrationService $migrationService): void
    {
        $error = $this->newPinValidationError();
        if ($error !== null) {
            $this->flashMessage = $error;

            return;
        }

        $user = $currentUser->user();
        $result = $provisioner->changePin($user->id, $this->currentPin, $this->newPin);

        if ($result === false) {
            $this->flashMessage = self::PIN_INCORRECT;

            return;
        }

        $this->confirmingChangePin = false;
        $this->currentPin = '';
        $this->newPin = '';
        $this->confirmPin = '';
        $this->flashMessage = '';

        $this->changePinSuccessMessage = $migrationService->isEnabled($user->id)
            ? 'Your encryption key has been re-secured with your new PIN.'
            : '';
    }

    // -------------------------------------------------------------------------
    // Action: forgot PIN — password-based recovery
    // -------------------------------------------------------------------------

    public function confirmForgotPin(): void
    {
        $this->confirmingForgotPin = true;
        $this->accountPassword = '';
        $this->newPin = '';
        $this->confirmPin = '';
    }

    // Reachable via: sign out from the lock screen -> password login
    // (which primes the session) -> Settings -> "Forgot PIN?".
    public function resetForgottenPin(CurrentUser $currentUser, AppLockProvisioner $provisioner, Hasher $hasher): void
    {
        $error = $this->newPinValidationError();
        if ($error !== null) {
            $this->flashMessage = $error;

            return;
        }

        $user = $currentUser->user();

        if (! $hasher->check($this->accountPassword, $user->password)) {
            $this->flashMessage = 'Incorrect account password.';

            return;
        }

        $result = $provisioner->rewrapForNewPin($user->id, $this->accountPassword, $this->newPin);

        $this->accountPassword = '';
        $this->newPin = '';
        $this->confirmPin = '';

        if ($result === false) {
            // The recovery wrap is missing or corrupted — recovery impossible
            // without the old PIN; the user must disable and re-enable the lock.
            $this->flashMessage = 'PIN reset failed — the recovery key is unavailable.';

            return;
        }

        $this->confirmingForgotPin = false;
        $this->flashMessage = '';
    }

    // -------------------------------------------------------------------------
    // Biometric enrollment (only available when lock is enabled)
    // -------------------------------------------------------------------------

    // Dispatches 'beatrax:webauthn-create', which lock.js answers by
    // fetching creationOptions, calling navigator.credentials.create(),
    // POSTing the attestation to /lock/biometric/enroll, then dispatching
    // 'biometric-enrolled' back to this component on success.
    public function startEnroll(): void
    {
        if (! $this->lockEnabled) {
            $this->flashMessage = 'Enable the PIN lock first before enrolling biometrics.';

            return;
        }

        $this->dispatch('beatrax:webauthn-create');
    }

    #[On('biometric-enrolled')]
    public function onBiometricEnrolled(): void
    {
        $this->biometricEnrolled = true;
        $this->flashMessage = '';
    }

    public function confirmDeenroll(): void
    {
        $this->confirmingDeenroll = true;
        $this->deenrollPin = '';
    }

    public function deenroll(
        CurrentUser $currentUser,
        BiometricDeviceStore $biometricStore,
        AppLockProvisioner $provisioner,
    ): void {
        $user = $currentUser->user();

        if (! $provisioner->verifyPin($user->id, $this->deenrollPin)) {
            $this->flashMessage = self::PIN_INCORRECT;

            return;
        }

        $biometricStore->deleteForUser($user->id);

        $this->biometricEnrolled = false;
        $this->confirmingDeenroll = false;
        $this->deenrollPin = '';
        $this->flashMessage = '';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    // Shared by every flow that sets a new PIN (set / change / forgot):
    // returns the user-facing error string, or null when the entered PIN
    // passes the minimum-length and confirmation-match checks.
    private function newPinValidationError(): ?string
    {
        return match (true) {
            strlen($this->newPin) < 4 => self::PIN_TOO_SHORT,
            $this->newPin !== $this->confirmPin => self::PIN_MISMATCH,
            default => null,
        };
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(ViewFactory $views): View
    {
        return $views->make('auth::livewire.app-lock-settings-section');
    }
}
