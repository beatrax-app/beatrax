<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;

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
     * Whether biometric unlock is enrolled (display-only in this plan;
     * plan 05-05 wires the actual enrollment toggle).
     */
    public bool $biometricEnrolled = false;

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

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Hydrate component state from the user_app_lock_configs row.
     * Creates a default row via updateOrInsert if none exists.
     */
    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $user = $currentUser->user();

        $row = $db->connection()
            ->table('user_app_lock_configs')
            ->where('user_id', $user->id)
            ->first(['lock_enabled', 'idle_timeout_minutes']);

        if ($row === null) {
            // Bootstrap a default row so subsequent reads never need null-guarding.
            $db->connection()->table('user_app_lock_configs')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'lock_enabled' => false,
                    'idle_timeout_minutes' => 5,
                    'failed_attempts' => 0,
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
    public function setPin(CurrentUser $currentUser, AppLockProvisioner $provisioner, Hasher $hasher): void
    {
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

        $provisioner->enable($user->id, $this->newPin, $this->accountPassword);

        $this->lockEnabled = true;
        $this->flashMessage = '';

        // Clear sensitive props immediately.
        $this->newPin = '';
        $this->confirmPin = '';
        $this->accountPassword = '';
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
    }

    /**
     * Change the PIN after verifying the current PIN (D-23).
     *
     * Requires both the current PIN and a new confirmed PIN.
     * On wrong current PIN: flashes the wrong-PIN message.
     */
    public function changePin(CurrentUser $currentUser, AppLockProvisioner $provisioner): void
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
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(ViewFactory $views): View
    {
        return $views->make('auth::livewire.app-lock-settings-section');
    }
}
