<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Http\Livewire;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Auth\Public\AppLockEvents;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\Lang;

final class AppLockSettingsSection extends Component
{
    use DispatchesToast;
    use HoldsFlashMessage;

    private const PIN_RULES = 'nullable|regex:/^[0-9]{6,10}$/';

    public bool $lockEnabled = false;

    public bool $biometricEnrolled = false;

    public bool $biometricCapable = false;

    public string $biometricLabel = 'biometric unlock';

    public bool $confirmingDeenroll = false;

    #[Validate(self::PIN_RULES)]
    public string $deenrollPin = '';

    // Exempt from the PIN confirmation every other mutation here requires:
    // narrowing the auto-lock window touches no key material.
    #[Validate('required|integer|in:1,5,15,30')]
    public int $idleTimeoutMinutes = 5;

    #[Validate(self::PIN_RULES)]
    public string $newPin = '';

    #[Validate(self::PIN_RULES)]
    public string $confirmPin = '';

    #[Validate(self::PIN_RULES)]
    public string $currentPin = '';

    #[Validate('nullable|string')]
    public string $accountPassword = '';

    public bool $confirmingDisable = false;

    public bool $confirmingChangePin = false;

    public bool $confirmingForgotPin = false;

    public string $changePinSuccessMessage = '';

    public function mount(
        CurrentUser $currentUser,
        DatabaseManager $db,
        BiometricDeviceStore $biometricStore,
        PlatformDetector $detector,
        Request $request,
        Clock $clock,
        ColdStartVault $vault,
    ): void {
        $user = $currentUser->user();

        $row = $db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $user->id)
            ->first(['lock_enabled', 'idle_timeout_minutes']);

        if ($row === null) {
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
        $this->biometricEnrolled = $vault->isEnrolled($user->id) || $credentials->contains(
            fn (object $cred): bool => $biometricStore->isArmed($cred)
        );

        $ua = $request->userAgent() ?? '';
        $this->biometricLabel = $detector->detectLabel($ua);

        // Seeded server-side because lock.js's browser probe cannot see an
        // OS-owned biometric at all.
        $this->biometricCapable = $vault->isAvailable();
    }

    public function setPin(CurrentUser $currentUser, AppLockProvisioner $provisioner, Hasher $hasher, Session $session): void
    {
        // enable() mints a new data key, so re-running it on an enabled lock
        // would silently rotate it. PIN changes go through changePin().
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
            $this->flashMessage = Lang::get('auth::app_lock.error_account_password');

            return;
        }

        // The session is passed so enable() stores the data key straight away:
        // the user just authenticated, so leave them unlocked, not key-less.
        $provisioner->enable($user->id, $this->newPin, $this->accountPassword, $session);

        $this->lockEnabled = true;
        $this->flashMessage = '';

        $this->newPin = '';
        $this->confirmPin = '';
        $this->accountPassword = '';

        // A browser event, not a PHP one: sibling sections refresh their
        // lock-gated UI live without a cross-module dependency.
        $this->dispatch(AppLockEvents::CONFIGURED);

        // Every other write on this screen confirms itself; this one blanked
        // its three inputs and said nothing, which reads as "it did not take".
        $this->toast(Lang::get('core::settings.saved'));
    }

    public function setIdleTimeout(CurrentUser $currentUser, DatabaseManager $db, Clock $clock, Session $session): void
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

        // Both the middleware's session cache and the client's idle watcher
        // hold a stale window until pushed; neither expires usefully.
        $session->forget(AppLockMiddleware::SESSION_CONFIG_CACHE);

        $this->dispatch(
            'beatrax-idle-timeout-changed',
            ms: $this->idleTimeoutMinutes * 60_000,
        );

        $this->toast(Lang::get('core::settings.saved'));
    }

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
            $this->flashMessage = Lang::get('auth::app_lock.error_pin_incorrect');

            return;
        }

        $this->lockEnabled = false;
        $this->biometricEnrolled = false;
        $this->confirmingDisable = false;
        $this->currentPin = '';
        $this->flashMessage = '';
    }

    public function confirmChangePin(): void
    {
        $this->confirmingChangePin = true;
        $this->currentPin = '';
        $this->newPin = '';
        $this->confirmPin = '';
        $this->changePinSuccessMessage = '';
    }

    // The keyring re-wrap is not done here: AppLockProvisioner::changePin()
    // dispatches AppLockPassphraseChanged and that does the work.
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
            $this->flashMessage = Lang::get('auth::app_lock.error_pin_incorrect');

            return;
        }

        $this->confirmingChangePin = false;
        $this->currentPin = '';
        $this->newPin = '';
        $this->confirmPin = '';
        $this->flashMessage = '';

        $this->changePinSuccessMessage = $migrationService->isEnabled($user->id)
            ? Lang::get('auth::app_lock.change_pin_success')
            : '';
    }

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
            $this->flashMessage = Lang::get('auth::app_lock.error_account_password');

            return;
        }

        $result = $provisioner->rewrapForNewPin($user->id, $this->accountPassword, $this->newPin);

        $this->accountPassword = '';
        $this->newPin = '';
        $this->confirmPin = '';

        if ($result === false) {
            // The recovery wrap is missing or corrupted — recovery impossible
            // without the old PIN; the user must disable and re-enable the lock.
            $this->flashMessage = Lang::get('auth::app_lock.error_forgot_failed');

            return;
        }

        $this->confirmingForgotPin = false;
        $this->flashMessage = '';

        // Nothing else marks this one: the lock was already on and stays on, so
        // without a word the screen looks identical to a reset that failed.
        $this->toast(Lang::get('core::settings.saved'));
    }

    // Half of a browser round trip: lock.js answers 'beatrax:webauthn-create'
    // by POSTing an attestation to /lock/biometric/enroll, then dispatching
    // 'biometric-enrolled' back here.
    public function startEnroll(
        CurrentUser $currentUser,
        ColdStartVault $vault,
        AppLockKeyService $keyService,
        Session $session,
        ConfigRepository $config,
    ): void {
        if (! $this->lockEnabled) {
            $this->flashMessage = Lang::get('auth::app_lock.error_enable_first');

            return;
        }

        // An OS-owned biometric is enrolled directly: WebAuthn is a browser
        // API, and navigator.credentials.create() resolves to nothing behind
        // the desktop shell, which read as a dead button.
        if ($vault->isAvailable()) {
            $this->enrollNatively($currentUser, $vault, $keyService, $session);

            return;
        }

        // Same dead-button case with no OS vault to fall back on: say so
        // rather than dispatching into nothing.
        if ($config->get('nativephp-internal.running') === true) {
            $this->flashMessage = Lang::get('auth::app_lock.error_enroll_unsupported');

            return;
        }

        $this->dispatch('beatrax:webauthn-create');
    }

    // Stores the live data key under the OS gate, so it only works unlocked --
    // which is the only state this settings screen is reachable in.
    private function enrollNatively(
        CurrentUser $currentUser,
        ColdStartVault $vault,
        AppLockKeyService $keyService,
        Session $session,
    ): void {
        $dataKey = $keyService->release($session);

        if ($dataKey === null) {
            $this->flashMessage = Lang::get('auth::app_lock.error_enroll_locked');

            return;
        }

        if (! $vault->enroll($currentUser->user()->id, $dataKey)) {
            $this->flashMessage = Lang::get('auth::app_lock.error_enroll_failed');

            return;
        }

        $this->biometricEnrolled = true;
        $this->flashMessage = '';
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
        ColdStartVault $vault,
    ): void {
        $user = $currentUser->user();

        if (! $provisioner->verifyPin($user->id, $this->deenrollPin)) {
            $this->flashMessage = Lang::get('auth::app_lock.error_pin_incorrect');

            return;
        }

        $biometricStore->deleteForUser($user->id);
        $vault->forget($user->id);

        $this->biometricEnrolled = false;
        $this->confirmingDeenroll = false;
        $this->deenrollPin = '';
        $this->flashMessage = '';
    }

    private function newPinValidationError(): ?string
    {
        return match (true) {
            strlen($this->newPin) < 6 => Lang::get('auth::app_lock.error_pin_too_short'),
            $this->newPin !== $this->confirmPin => Lang::get('auth::app_lock.error_pin_mismatch'),
            default => null,
        };
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('auth::livewire.app-lock-settings-section');
    }
}
