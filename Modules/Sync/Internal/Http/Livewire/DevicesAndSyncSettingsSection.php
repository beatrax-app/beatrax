<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\DeviceRegistryService;

/**
 * "Devices & Sync" settings section (PAIR-02/PAIR-03, D-02/D-09/D-10/D-11/D-12).
 *
 * Mounted into the Core settings page below the App lock section via the
 * livewire('sync.devices-and-sync-settings-section') component name. Mirrors
 * the structural pattern of AppLockSettingsSection (per-method DI — never
 * constructor DI; the component stays PHPStan-clean at level 10).
 *
 * Responsibilities:
 *   - Surface the enable-sync toggle GATED on an app-lock being configured
 *     (D-02): with no app-lock the toggle is blocked and flashes "Set an app
 *     lock first to enable sync." This is the UI half of the defense-in-depth
 *     gate (DeviceIdentityService::generateAndPersist hard-throws when the KEK
 *     is unavailable — the service half).
 *   - On enable, generate + persist the device identity (D-12: lazy, explicit
 *     opt-in — no key-file exists until the user turns sync on) and show the
 *     self device in the list.
 *   - List confirmed devices (name, word safety-number, paired-at) with inline
 *     rename (D-09). NO revoke / remove action (D-10) — view / rename / verify
 *     only.
 *   - Open the pairing-flow modal (D-11).
 *
 * Cross-module boundary (CLAUDE.md): the app-lock state is read via the Auth
 * public service AppLockClientConfig — never by querying user_app_lock_configs
 * directly. Device rows are read via the Sync public DeviceRegistryService and
 * written only through user-scoped where('user_id', …) queries.
 *
 * The authenticated user is always read from CurrentUser — never from a
 * request-supplied id — so cross-user reads/writes are structurally impossible
 * (T-12-16).
 */
final class DevicesAndSyncSettingsSection extends Component
{
    /**
     * Whether sync is enabled for this user (a self device_registry row exists).
     */
    public bool $syncEnabled = false;

    /**
     * Whether an app-lock is configured (the D-02 enable-sync gate).
     */
    public bool $appLockConfigured = false;

    /**
     * Flash message shown to the user after an action (the app-lock gate or an
     * enable failure).
     */
    public string $flashMessage = '';

    /**
     * Whether the pairing-flow modal is open.
     */
    public bool $pairingModalOpen = false;

    /**
     * Confirmed device rows for the list (name, safety-number, paired-at).
     *
     * @var list<array<string, mixed>>
     */
    public array $devices = [];

    /**
     * The id of the device row currently being renamed inline, or null.
     */
    public ?int $renamingDeviceId = null;

    /**
     * The working value of the inline rename input.
     */
    public string $renameValue = '';

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Hydrate the section: the D-02 gate, whether sync is on (a self-row
     * exists), and the confirmed-device list.
     */
    public function mount(
        CurrentUser $currentUser,
        DatabaseManager $db,
        AppLockClientConfig $lockConfig,
        DeviceRegistryService $registry,
    ): void {
        $userId = $currentUser->user()->id;

        // D-02 gate: an app-lock is configured iff AppLockClientConfig returns a
        // non-null idle timeout. Read via the Auth public service (never query
        // user_app_lock_configs from the Sync module — cross-module boundary).
        $this->appLockConfigured = $lockConfig->idleTimeoutMs($userId) !== null;

        $this->syncEnabled = $this->selfRowExists($db, $userId);
        $this->devices = $this->loadDevices($registry, $userId);
    }

    // -------------------------------------------------------------------------
    // Action: enable sync (app-lock gated, D-02 / D-12)
    // -------------------------------------------------------------------------

    /**
     * Enable sync: generate + persist the device identity and show the self
     * device. Gated on an app-lock being configured (D-02). The service-level
     * LogicException guard is the defense-in-depth backstop.
     */
    public function enableSync(
        CurrentUser $currentUser,
        DeviceIdentityService $identityService,
        Session $session,
        DatabaseManager $db,
        DeviceRegistryService $registry,
    ): void {
        if ($this->syncEnabled) {
            return;
        }

        // D-02 UI gate (defense-in-depth with the service-level LogicException).
        if (! $this->appLockConfigured) {
            $this->flashMessage = 'Set an app lock first to enable sync.';

            return;
        }

        $userId = $currentUser->user()->id;

        try {
            $identityService->generateAndPersist($userId, $session);
            $this->syncEnabled = true;
            $this->flashMessage = '';
            $this->devices = $this->loadDevices($registry, $userId);
        } catch (\LogicException) {
            // The KEK was unavailable despite the configured-lock check (the app
            // is locked, or the session is keyless) — surface the recovery copy.
            $this->flashMessage = 'Failed to enable sync. Make sure your app lock is active and try again.';
        }
    }

    // -------------------------------------------------------------------------
    // Action: open the pairing modal (D-11)
    // -------------------------------------------------------------------------

    public function openPairingModal(): void
    {
        $this->pairingModalOpen = true;
    }

    // -------------------------------------------------------------------------
    // Action: inline device rename (D-09)
    // -------------------------------------------------------------------------

    /**
     * Begin an inline rename of a device row (open the input pre-filled with the
     * current name).
     */
    public function startRename(int $deviceId): void
    {
        $this->renamingDeviceId = $deviceId;
        $this->renameValue = $this->currentNameFor($deviceId);
    }

    public function cancelRename(): void
    {
        $this->renamingDeviceId = null;
        $this->renameValue = '';
    }

    /**
     * Persist a device rename, user-scoped. Trims the new name and ignores an
     * empty value (a blank rename is a no-op cancel). Both the inline-edit path
     * (renamingDeviceId + renameValue) and the direct (id, name) call shape
     * route through here so the RED contract's
     * call('renameDevice', $id, 'New Name') works.
     */
    public function renameDevice(
        DatabaseManager $db,
        CurrentUser $currentUser,
        DeviceRegistryService $registry,
        ?int $deviceId = null,
        ?string $name = null,
    ): void {
        $targetId = $deviceId ?? $this->renamingDeviceId;
        $newName = trim($name ?? $this->renameValue);

        if ($targetId === null || $newName === '') {
            $this->cancelRename();

            return;
        }

        $userId = $currentUser->user()->id;

        $db->connection()->table('device_registry')
            ->where('id', $targetId)
            ->where('user_id', $userId)
            ->update(['name' => $newName]);

        $this->devices = $this->loadDevices($registry, $userId);
        $this->cancelRename();
    }

    // -------------------------------------------------------------------------
    // Event hooks
    // -------------------------------------------------------------------------

    /**
     * Refresh the device list after the pairing modal admits a new device.
     */
    public function refreshDevices(CurrentUser $currentUser, DeviceRegistryService $registry): void
    {
        $this->devices = $this->loadDevices($registry, $currentUser->user()->id);
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(ViewFactory $views): View
    {
        return $views->make('sync::livewire.devices-and-sync-settings-section');
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Whether a self device row exists for this user (sync is enabled).
     */
    private function selfRowExists(DatabaseManager $db, int $userId): bool
    {
        return $db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->exists();
    }

    /**
     * Load the confirmed-device rows for the list as plain view-model arrays.
     *
     * @return list<array<string, mixed>>
     */
    private function loadDevices(DeviceRegistryService $registry, int $userId): array
    {
        $rows = $registry->confirmedDevices($userId);

        $devices = [];
        foreach ($rows as $row) {
            $devices[] = [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) ? $row->name : '',
                'safety_number_words' => is_string($row->safety_number_words) ? $row->safety_number_words : '',
                'paired_at' => is_string($row->paired_at) ? $row->paired_at : '',
                'is_self' => is_numeric($row->is_self) && (int) $row->is_self === 1,
                'confirmed' => $row->confirmed_at !== null,
            ];
        }

        return $devices;
    }

    /**
     * The current displayed name for a device id (from the loaded list).
     */
    private function currentNameFor(int $deviceId): string
    {
        foreach ($this->devices as $device) {
            if (($device['id'] ?? null) === $deviceId) {
                $name = $device['name'] ?? '';

                return is_string($name) ? $name : '';
            }
        }

        return '';
    }
}
