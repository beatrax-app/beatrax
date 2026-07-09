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
 * App Lock settings section component.
 *
 * Surfaces the LOCK-01 enablement flow (enable/disable the app-lock,
 * set/change the PIN, adjust the idle-timeout preset) as a settings card
 * inside the Core settings page.
 *
 * Security decisions (D-23 downgrade confirmations):
 *   - Enable (first-time PIN setup): requires the user's account password
 *     to build the D-21 recovery wrap — verified via Hasher::check() against
 *     $user->password; never persisted in plaintext.
 *   - Disable: requires current PIN confirmation (AppLockProvisioner::disable).
 *   - Change PIN: requires current PIN before new PIN entry (05-04 wires the
 *     modal; the actual changePin() call is deferred to a future UI task).
 *   - Idle timeout change: no PIN confirmation required (D-23 explicitly exempts it).
 *
 * Service collaborators are injected per-method (no constructor DI); this
 * mirrors SettingsPage.php and keeps the component PHPStan-clean at level 10.
 *
 * The authenticated user is always read from CurrentUser — never from a
 * request-supplied user_id — so cross-user writes are structurally impossible
 * (T-05-15 mitigated via provisioner methods that require PIN verification).
 */
final class AppLockSettingsSection extends Component
{
    /**
     * Whether the app lock is currently enabled for this user.
     */
    public bool $lockEnabled = false;

    /**
     * Whether biometric unlock is enrolled (at least one active credential).
     */
    public bool $biometricEnrolled = false;

    /**
     * Whether the platform supports WebAuthn biometric (PublicKeyCredential).
     * Defaults to false (server-side cannot know; JS sets this on mount).
     */
    public bool $biometricCapable = false;

    /**
     * Platform-aware biometric label (e.g. "Touch ID", "Windows Hello").
     */
    public string $biometricLabel = 'biometric unlock';

    /**
     * Whether the "confirm de-enroll" modal is open.
     */
    public bool $confirmingDeenroll = false;

    /**
     * PIN for de-enrollment confirmation (D-23).
     */
    #[Validate('nullable|regex:/^[0-9]{4,10}$/')]
    public string $deenrollPin = '';

    /**
     * Idle timeout in minutes. Allowed: 1, 5, 15, 30.
     * Applied without PIN confirmation per D-23.
     */
    #[Validate('required|integer|in:1,5,15,30')]
    public int $idleTimeoutMinutes = 5;

    /**
     * New PIN for the enable / setup flow (4–10 digits).
     * Cleared after successful submission.
     */
    #[Validate('nullable|regex:/^[0-9]{4,10}$/')]
    public string $newPin = '';

    /**
     * PIN confirmation for the enable / setup flow — must equal $newPin.
     */
    #[Validate('nullable|regex:/^[0-9]{4,10}$/')]
    public string $confirmPin = '';

    /**
     * Current PIN for disable / change-PIN flows (D-23 confirmation).
     */
    #[Validate('nullable|regex:/^[0-9]{4,10}$/')]
    public string $currentPin = '';

    /**
     * Account password required when enabling the lock for the first time.
     * Used transiently to build the D-21 password recovery wrap; never stored.
     */
    #[Validate('nullable|string')]
    public string $accountPassword = '';

    /**
     * Flash message shown to the user after an action (success or error).
     */
    public string $flashMessage = '';

    /**
     * Whether the "confirm disable" modal is open.
     */
    public bool $confirmingDisable = false;

    /**
     * Whether the "confirm change PIN" modal is open.
     */
    public bool $confirmingChangePin = false;

    /**
     * Whether the "forgot PIN" recovery modal is open (D-11/D-21).
     */
    public bool $confirmingForgotPin = false;

    /**
     * Change-PIN success caption (Phase 14 D-10). Distinct from $flashMessage
     * (which is reserved for the rose error/alert copy) — set ONLY after a
     * successful changePin(), and ONLY carries the D-10 re-secured-encryption
     * note (gated on an encrypted keyring existing for this user via
     * EncryptionMigrationService::isEnabled()). Empty string renders nothing.
     */
    public string $changePinSuccessMessage = '';

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Hydrate component state from the user_app_lock_configs row.
     * Creates a default row via updateOrInsert if none exists.
     */
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
            // Bootstrap a default row so subsequent reads never need null-guarding.
            // updateOrInsert does not manage timestamps — set them explicitly (IN-07).
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

        // Biometric enrollment state: at least one armed credential.
        $credentials = $biometricStore->findForUser($user->id);
        $this->biometricEnrolled = $credentials->contains(
            fn (object $cred): bool => $biometricStore->isArmed($cred)
        );

        // Platform-aware label (server-side UA detection).
        $ua = $request->userAgent() ?? '';
        $this->biometricLabel = $detector->detectLabel($ua);

        // $biometricCapable is set client-side via JS (window.PublicKeyCredential check).
        // Server defaults to false; lock.js sets it to true when capable.
    }

    // -------------------------------------------------------------------------
    // Action: enable lock / set PIN
    // -------------------------------------------------------------------------

    /**
     * Enable the app lock with a new PIN.
     *
     * Validates:
     *   - PIN is ≥4 digits (4–10 numeric).
     *   - confirmPin matches newPin.
     *   - accountPassword verifies against the user's stored hash (D-21 recovery wrap).
     *
     * On success: calls AppLockProvisioner::enable() which generates and double-wraps
     * the data key (pin wrap + password recovery wrap), sets lockEnabled=true.
     *
     * Threat mitigations:
     *   T-05-17: accountPassword is used only transiently (Hasher::check + provisioner->enable);
     *            immediately cleared from props after use; never persisted.
     */
    public function setPin(CurrentUser $currentUser, AppLockProvisioner $provisioner, Hasher $hasher, Session $session): void
    {
        // WR-06 guard: enable() generates a NEW data key (and clears biometric
        // enrollments). Re-running it on an already-enabled lock would silently
        // rotate the key; PIN changes must go through changePin() instead.
        if ($this->lockEnabled) {
            return;
        }

        // PIN length validation.
        if (strlen($this->newPin) < 4) {
            $this->flashMessage = 'PIN must be at least 4 digits.';

            return;
        }

        // PIN confirmation match.
        if ($this->newPin !== $this->confirmPin) {
            $this->flashMessage = "PINs don't match. Try again.";

            return;
        }

        $user = $currentUser->user();

        // Account password verification (D-21 recovery wrap requirement).
        if (! $hasher->check($this->accountPassword, $user->password)) {
            $this->flashMessage = 'Incorrect account password.';

            return;
        }

        // Pass the session so enable() stores the data key in the session immediately
        // (Gap B fix: coherent post-enable state — the user just authenticated, so the
        // session should be unlocked with the key available, not key-less/locked).
        $provisioner->enable($user->id, $this->newPin, $this->accountPassword, $session);

        $this->lockEnabled = true;
        $this->flashMessage = '';

        // Clear sensitive props immediately.
        $this->newPin = '';
        $this->confirmPin = '';
        $this->accountPassword = '';

        // Notify sibling sections (e.g. Devices & Sync, D-02) that an app-lock
        // now exists so their app-lock-gated UI updates live without a reload.
        // Plain browser-event name — no cross-module PHP dependency.
        $this->dispatch('app-lock-configured');
    }

    // -------------------------------------------------------------------------
    // Action: idle timeout (instant-apply, no PIN confirmation)
    // -------------------------------------------------------------------------

    /**
     * Persist the idle timeout preset immediately (D-23: no PIN confirmation required).
     * Validates that the value is one of the allowed presets.
     */
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
    // Action: disable lock (D-23 confirmation — requires current PIN)
    // -------------------------------------------------------------------------

    /**
     * Open the disable-lock confirmation modal.
     */
    public function confirmDisable(): void
    {
        $this->confirmingDisable = true;
        $this->currentPin = '';
    }

    /**
     * Disable the app lock after verifying the current PIN (D-23).
     *
     * On wrong PIN: flashes the wrong-PIN error message; the lock stays enabled.
     * On correct PIN: calls AppLockProvisioner::disable() which clears all PIN
     * material from user_app_lock_configs and sets lock_enabled=false.
     */
    public function disable(CurrentUser $currentUser, AppLockProvisioner $provisioner): void
    {
        $user = $currentUser->user();
        $result = $provisioner->disable($user->id, $this->currentPin);

        if ($result === false) {
            $this->flashMessage = 'Incorrect PIN.';

            return;
        }

        $this->lockEnabled = false;
        // WR-06: provisioner->disable() deleted all biometric credentials
        // (their wraps held the now-destroyed data key) — mirror that in the UI.
        $this->biometricEnrolled = false;
        $this->confirmingDisable = false;
        $this->currentPin = '';
        $this->flashMessage = '';
    }

    // -------------------------------------------------------------------------
    // Action: change PIN (D-23 confirmation — requires current PIN + new PIN)
    // -------------------------------------------------------------------------

    /**
     * Open the change-PIN confirmation modal.
     */
    public function confirmChangePin(): void
    {
        $this->confirmingChangePin = true;
        $this->currentPin = '';
        $this->newPin = '';
        $this->confirmPin = '';
        $this->changePinSuccessMessage = '';
    }

    /**
     * Change the PIN after verifying the current PIN (D-23).
     *
     * Requires both the current PIN and a new confirmed PIN.
     * On wrong current PIN: flashes the wrong-PIN message.
     *
     * Phase 14 D-10: on success, when an encrypted keyring already exists for
     * this user (EncryptionMigrationService::isEnabled()), surfaces the
     * re-secured-encryption note via $changePinSuccessMessage — the GDK
     * keyring re-wrap itself happens invisibly via the AppLockPassphraseChanged
     * event -> RewrapGdkOnPassphraseChange listener (Plan 07); this is only
     * the user-visible confirmation copy.
     */
    public function changePin(CurrentUser $currentUser, AppLockProvisioner $provisioner, EncryptionMigrationService $migrationService): void
    {
        if (strlen($this->newPin) < 4) {
            $this->flashMessage = 'PIN must be at least 4 digits.';

            return;
        }

        if ($this->newPin !== $this->confirmPin) {
            $this->flashMessage = "PINs don't match. Try again.";

            return;
        }

        $user = $currentUser->user();
        $result = $provisioner->changePin($user->id, $this->currentPin, $this->newPin);

        if ($result === false) {
            $this->flashMessage = 'Incorrect PIN.';

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
    // Action: forgot PIN — password-based recovery (D-11/D-21)
    // -------------------------------------------------------------------------

    /**
     * Open the forgot-PIN recovery modal.
     */
    public function confirmForgotPin(): void
    {
        $this->confirmingForgotPin = true;
        $this->accountPassword = '';
        $this->newPin = '';
        $this->confirmPin = '';
    }

    /**
     * Reset a forgotten PIN using the account password (D-11/D-21).
     *
     * The D-21 password recovery wrap is the authoritative recovery path:
     * AppLockProvisioner::rewrapForNewPin() unwraps the data key from
     * password_wrapped_key and re-wraps it under the new PIN — the data key
     * survives unchanged (no key loss). The account password doubles as the
     * D-23 confirmation for this security-sensitive change.
     *
     * Reachability: sign out from the lock screen → password login (which
     * primes the session per WR-03) → Settings → "Forgot PIN?".
     */
    public function resetForgottenPin(CurrentUser $currentUser, AppLockProvisioner $provisioner, Hasher $hasher): void
    {
        if (strlen($this->newPin) < 4) {
            $this->flashMessage = 'PIN must be at least 4 digits.';

            return;
        }

        if ($this->newPin !== $this->confirmPin) {
            $this->flashMessage = "PINs don't match. Try again.";

            return;
        }

        $user = $currentUser->user();

        if (! $hasher->check($this->accountPassword, $user->password)) {
            $this->flashMessage = 'Incorrect account password.';

            return;
        }

        $result = $provisioner->rewrapForNewPin($user->id, $this->accountPassword, $this->newPin);

        // Clear sensitive props immediately, success or not.
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
    // Biometric enrollment (D-13 — only when lock is enabled)
    // -------------------------------------------------------------------------

    /**
     * Dispatch 'beatrax:webauthn-create' so lock.js calls navigator.credentials.create.
     *
     * D-13: Enrollment only available when the PIN lock is already enabled.
     * D-15: The browser prompt fires on button tap only (this method is tap-triggered).
     *
     * lock.js will:
     *   1. Fetch creationOptions from /lock/biometric/challenge?enroll=1
     *   2. Call navigator.credentials.create(options)
     *   3. POST the attestation to /lock/biometric/enroll
     *   4. Dispatch 'biometric-enrolled' back to this component on success.
     */
    public function startEnroll(): void
    {
        if (! $this->lockEnabled) {
            $this->flashMessage = 'Enable the PIN lock first before enrolling biometrics.';

            return;
        }

        $this->dispatch('beatrax:webauthn-create');
    }

    /**
     * Handle the browser-side enrollment completion.
     *
     * Dispatched by lock.js after a successful POST to /lock/biometric/enroll.
     */
    #[On('biometric-enrolled')]
    public function onBiometricEnrolled(): void
    {
        $this->biometricEnrolled = true;
        $this->flashMessage = '';
    }

    /**
     * Open the de-enroll confirmation modal (D-23: requires PIN).
     */
    public function confirmDeenroll(): void
    {
        $this->confirmingDeenroll = true;
        $this->deenrollPin = '';
    }

    /**
     * De-enroll biometric after verifying the current PIN (D-23).
     *
     * The PIN check is side-effect-free (AppLockProvisioner::verifyPin) so
     * de-enrollment NEVER disables the lock or touches pin_hash / kdf_salt /
     * pin_wrapped_key / password_wrapped_key. Only the user's
     * user_biometric_credentials rows are deleted.
     */
    public function deenroll(
        CurrentUser $currentUser,
        BiometricDeviceStore $biometricStore,
        AppLockProvisioner $provisioner,
    ): void {
        $user = $currentUser->user();

        // D-23: require PIN confirmation before de-enrollment — without
        // mutating any lock state (verify-only, no disable).
        if (! $provisioner->verifyPin($user->id, $this->deenrollPin)) {
            $this->flashMessage = 'Incorrect PIN.';

            return;
        }

        $biometricStore->deleteForUser($user->id);

        $this->biometricEnrolled = false;
        $this->confirmingDeenroll = false;
        $this->deenrollPin = '';
        $this->flashMessage = '';
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(ViewFactory $views): View
    {
        return $views->make('auth::livewire.app-lock-settings-section');
    }
}
