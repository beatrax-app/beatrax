<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Http\Livewire;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\AppLockCredentialRejections;
use Modules\Auth\Internal\Lock\AppLockDisableResult;
use Modules\Auth\Internal\Lock\AppLockKeyState;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\IdleTimeoutOptions;
use Modules\Auth\Internal\Lock\PlatformDetector;
use Modules\Auth\Public\AppLockEvents;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\Lang;

final class AppLockSettingsSection extends Component
{
    use DispatchesToast;
    use HoldsFlashMessage;

    // No #[Validate] on the PIN and password boxes below. The attribute only
    // runs where an action calls validate(), none of these do, and a rule that
    // never runs reads as a gate that is there. AppLockPinShape is the rule.

    // Locked: setPin() refuses to run on an enabled lock because enable()
    // re-provisions rather than re-wraps. Read off the wire, that refusal was
    // the client's to waive — moving this to false rotated the salt, replaced
    // the PIN hash and dropped every biometric enrolment.
    #[Locked]
    public bool $lockEnabled = false;

    public bool $biometricEnrolled = false;

    public bool $biometricCapable = false;

    public string $biometricLabel = 'biometric unlock';

    public bool $confirmingDeenroll = false;

    public string $deenrollPin = '';

    // Exempt from the PIN confirmation every other mutation here requires:
    // narrowing the auto-lock window touches no key material. The rule is in
    // rules() because an attribute argument cannot read the options list.
    public int $idleTimeoutMinutes = IdleTimeoutOptions::DEFAULT_MINUTES;

    public string $newPin = '';

    public string $confirmPin = '';

    public string $currentPin = '';

    public string $accountPassword = '';

    public bool $confirmingDisable = false;

    public bool $confirmingChangePin = false;

    public bool $confirmingForgotPin = false;

    // Rendered only in the state it repairs, so the ordinary screen never
    // carries a control for a fault nobody has.
    public bool $recoveryWrapStale = false;

    public bool $confirmingRelink = false;

    public string $changePinSuccessMessage = '';

    public function mount(
        CurrentUser $currentUser,
        DatabaseManager $db,
        BiometricDeviceStore $biometricStore,
        PlatformDetector $detector,
        Request $request,
        Clock $clock,
        ColdStartVault $vault,
        AppLockProvisioner $provisioner,
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
                    'idle_timeout_minutes' => IdleTimeoutOptions::DEFAULT_MINUTES,
                    'failed_attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            $this->lockEnabled = false;
            $this->idleTimeoutMinutes = IdleTimeoutOptions::DEFAULT_MINUTES;
        } else {
            $this->lockEnabled = (bool) $row->lock_enabled;
            $idleRaw = $row->idle_timeout_minutes;
            $idle = is_numeric($idleRaw) ? (int) $idleRaw : IdleTimeoutOptions::DEFAULT_MINUTES;
            $this->idleTimeoutMinutes = in_array($idle, IdleTimeoutOptions::minutes(), true)
                ? $idle
                : IdleTimeoutOptions::DEFAULT_MINUTES;
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

        $this->applyKeyState($provisioner->keyState($user->id));
    }

    // Both faults are silent everywhere else — a stranded key renders columns
    // empty, a stale recovery wrap waits until a forgotten PIN — so this screen
    // is where each one is said out loud.
    private function applyKeyState(AppLockKeyState $state): void
    {
        $this->recoveryWrapStale = $state === AppLockKeyState::RecoveryUnreadable;

        $this->flashMessage = match ($state) {
            AppLockKeyState::Stranded => Lang::get('auth::app_lock.error_key_material_lost'),
            AppLockKeyState::RecoveryUnreadable => Lang::get('auth::app_lock.error_recovery_wrap_stale'),
            AppLockKeyState::Absent, AppLockKeyState::Held => $this->flashMessage,
        };
    }

    public function confirmRelinkRecovery(): void
    {
        $this->confirmingRelink = true;
        $this->currentPin = '';
        $this->accountPassword = '';
    }

    // Takes both credentials at once because that is what the repair costs: the
    // PIN produces the data key, the account password becomes its new wrap.
    public function relinkRecovery(CurrentUser $currentUser, AppLockProvisioner $provisioner, AppLockCredentialRejections $rejections): void
    {
        $user = $currentUser->user();

        $rejection = $rejections->pinRequired($this->currentPin)
            ?? $rejections->accountPassword($this->accountPassword, $user->password);

        if ($rejection !== null) {
            $this->flashMessage = $rejection;

            return;
        }

        $relinked = $provisioner->relinkRecoveryWrap($user->id, $this->currentPin, $this->accountPassword);

        $this->accountPassword = '';
        $this->currentPin = '';

        if (! $relinked) {
            $this->flashMessage = Lang::get('auth::app_lock.error_pin_incorrect');

            return;
        }

        $this->recoveryWrapStale = false;
        $this->confirmingRelink = false;
        $this->flashMessage = '';

        $this->toast(Lang::get('auth::app_lock.relink_recovery_success'));
    }

    public function setPin(CurrentUser $currentUser, AppLockProvisioner $provisioner, AppLockCredentialRejections $rejections, Session $session): void
    {
        // enable() re-provisions the whole lock, so re-running it on an enabled
        // lock would rotate what a PIN change only re-wraps. Go via changePin().
        if ($this->lockEnabled) {
            return;
        }

        $user = $currentUser->user();

        // The key-state read sits between the two, ahead of the password checks
        // because no answer to them changes it, and a form that asks first
        // reads as though it could.
        $rejection = $rejections->newPin($this->newPin, $this->confirmPin);

        if ($rejection === null && $provisioner->keyState($user->id) === AppLockKeyState::Stranded) {
            $rejection = Lang::get('auth::app_lock.error_key_material_lost');
        }

        $rejection ??= $rejections->accountPassword($this->accountPassword, $user->password);

        if ($rejection !== null) {
            $this->flashMessage = $rejection;

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
            ms: $this->idleTimeoutMinutes * Duration::Minute->milliseconds(),
        );

        $this->toast(Lang::get('core::settings.saved'));
    }

    public function confirmDisable(): void
    {
        $this->confirmingDisable = true;
        $this->currentPin = '';
    }

    public function disable(CurrentUser $currentUser, AppLockProvisioner $provisioner, AppLockCredentialRejections $rejections): void
    {
        $rejection = $rejections->pinRequired($this->currentPin);

        if ($rejection !== null) {
            $this->flashMessage = $rejection;

            return;
        }

        $user = $currentUser->user();
        $result = $provisioner->disable($user->id, $this->currentPin);

        if ($result === AppLockDisableResult::PinIncorrect) {
            $this->flashMessage = Lang::get('auth::app_lock.error_pin_incorrect');

            return;
        }

        // Closed, unlike a wrong PIN: no PIN typed into this box changes the
        // answer, so leaving it open invites the user to keep trying.
        if ($result === AppLockDisableResult::EncryptedDataDependsOnIt) {
            $this->confirmingDisable = false;
            $this->currentPin = '';
            $this->flashMessage = Lang::get('auth::app_lock.error_disable_blocked_by_encryption');

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
    public function changePin(
        CurrentUser $currentUser,
        AppLockProvisioner $provisioner,
        EncryptionMigrationService $migrationService,
        AppLockCredentialRejections $rejections,
    ): void {
        $rejection = $rejections->newPin($this->newPin, $this->confirmPin)
            ?? $rejections->pinRequired($this->currentPin);

        if ($rejection !== null) {
            $this->flashMessage = $rejection;

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
    public function resetForgottenPin(CurrentUser $currentUser, AppLockProvisioner $provisioner, AppLockCredentialRejections $rejections): void
    {
        $user = $currentUser->user();

        $rejection = $rejections->newPin($this->newPin, $this->confirmPin)
            ?? $rejections->accountPassword($this->accountPassword, $user->password);

        if ($rejection !== null) {
            $this->flashMessage = $rejection;

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
        SecretShield $shield,
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

        $refusal = $this->browserEnrolmentRefusal($config, $shield);

        if ($refusal !== null) {
            $this->flashMessage = $refusal;

            return;
        }

        $this->dispatch('beatrax:webauthn-create');
    }

    // Reached only once the OS vault above turned out to be unavailable: both
    // answers here are about the browser road specifically, and a device with
    // its own vault never travels it.
    private function browserEnrolmentRefusal(ConfigRepository $config, SecretShield $shield): ?string
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
        AppLockCredentialRejections $rejections,
    ): void {
        $rejection = $rejections->pinRequired($this->deenrollPin);

        if ($rejection !== null) {
            $this->flashMessage = $rejection;

            return;
        }

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

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'idleTimeoutMinutes' => 'required|integer|in:'.implode(',', IdleTimeoutOptions::minutes()),
        ];
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('auth::livewire.app-lock-settings-section');
    }
}
