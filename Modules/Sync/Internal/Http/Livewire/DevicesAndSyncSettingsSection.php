<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
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
 *   - Configure the relay endpoint URL (D-01 / Phase 13): default none (LAN-direct
 *     out-of-box); non-HTTPS URL surfaces a warning (T-13-08 / Pitfall 6). Writes
 *     are gated behind the existing app-lock requirement (T-13-18). The relay URL
 *     field is shown only when sync is enabled. RelayConfig is injected via method DI
 *     on mount/saveRelayEndpoint.
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

    /**
     * The id of the device row currently confirming removal (Surface D), or
     * null when no revocation modal is open. Mirrors $renamingDeviceId.
     */
    public ?int $removingDeviceId = null;

    /**
     * Whether the revocation modal (Surface D) is open. A separate boolean
     * flag (rather than binding <flux:modal wire:model> directly to the
     * ?int $removingDeviceId) so Flux's own close-on-backdrop/-Escape flow —
     * which sets its bound model to a JS `false` — can never violate the
     * `?int` property type.
     */
    public bool $showRemoveModal = false;

    // -------------------------------------------------------------------------
    // At-rest encryption (Phase 14, D-07/D-09/D-11 — 14-UI-SPEC Surfaces A/B)
    // -------------------------------------------------------------------------

    /**
     * Whether at-rest encryption is currently ON for this user on this
     * device (`sync_encryption_state.current_epoch` is set).
     */
    public bool $encryptionOn = false;

    /**
     * Whether the enable-encryption modal (Surface B) is open.
     */
    public bool $showEncryptionModal = false;

    /**
     * Enable-encryption modal step: confirm|progress|done|error.
     */
    public string $encryptionStep = 'confirm';

    /**
     * 0-100 migration progress, sourced from EncryptionMigrationService::progress().
     */
    public int $encryptionProgress = 0;

    // -------------------------------------------------------------------------
    // Relay endpoint URL (D-01 / Phase 13 Plan 06 / T-13-08)
    // -------------------------------------------------------------------------

    /**
     * Current relay endpoint URL bound to the input field.
     * Default none (empty string = "not configured" → LAN-direct out-of-box, D-01).
     */
    public string $relayEndpointUrl = '';

    /**
     * Whether the configured relay URL uses plain HTTP (not HTTPS).
     * Surfaces a warning per T-13-08 / Pitfall 6 in the blade view.
     */
    public bool $relayIsInsecure = false;

    /**
     * Flash message for relay-URL save operations (success or error).
     */
    public string $relayFlashMessage = '';

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
        RelayConfig $relayConfig,
    ): void {
        $userId = $currentUser->user()->id;

        // D-02 gate: an app-lock is configured iff AppLockClientConfig returns a
        // non-null idle timeout. Read via the Auth public service (never query
        // user_app_lock_configs from the Sync module — cross-module boundary).
        $this->appLockConfigured = $lockConfig->idleTimeoutMs($userId) !== null;

        $this->syncEnabled = $this->selfRowExists($db, $userId);
        $this->devices = $this->loadDevices($registry, $userId);

        // Relay endpoint URL (D-01 default none, T-13-08 insecure flag).
        $this->relayEndpointUrl = $relayConfig->endpointUrl() ?? '';
        $this->relayIsInsecure = $relayConfig->isInsecure();

        // Phase 14 (D-07/D-09): whether at-rest encryption is already ON.
        $this->encryptionOn = $this->encryptionEnabled($db, $userId);
    }

    // -------------------------------------------------------------------------
    // Action: enable sync (app-lock gated, D-02 / D-12)
    // -------------------------------------------------------------------------

    /**
     * Enable sync: generate + persist the device identity and show the self
     * device. Gated on an app-lock being configured (D-02). The service-level
     * LogicException guard is the defense-in-depth backstop.
     *
     * D-07 mandatory-when-synced: the moment this device becomes a sync peer,
     * at-rest encryption auto-activates via EncryptionMigrationService::migrate()
     * — no decline affordance exists on this path. migrate() is idempotent
     * (a no-op once `current_epoch` is already set), so calling it here is safe
     * even when this device was already synced+encrypted on a prior visit.
     */
    public function enableSync(
        CurrentUser $currentUser,
        DeviceIdentityService $identityService,
        Session $session,
        DatabaseManager $db,
        DeviceRegistryService $registry,
        EncryptionMigrationService $migrationService,
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
        } catch (\LogicException) {
            // The KEK was unavailable despite the configured-lock check (the app
            // is locked, or the session is keyless) — surface the recovery copy.
            $this->flashMessage = 'Failed to enable sync. Make sure your app lock is active and try again.';

            return;
        }

        $this->syncEnabled = true;
        $this->flashMessage = '';
        $this->devices = $this->loadDevices($registry, $userId);

        // D-07 mandatory auto-activation — no decline path. A migration
        // failure never un-enables sync (D-09's own rollback already leaves
        // zero half-encrypted rows); the encryption row simply keeps
        // rendering its mandatory/off state until the next successful pass.
        try {
            $migrationService->migrate($currentUser->user(), $session);
        } catch (\Throwable) {
            // Best-effort — see docblock above.
        }

        $this->encryptionOn = $this->encryptionEnabled($db, $userId);
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
    // Action: enable-encryption modal (Surface B, single-device optional offer)
    // -------------------------------------------------------------------------

    /**
     * Open the enable-encryption modal (Surface B, confirm step). ONLY
     * reachable from the single-device (sync off) optional offer per D-07 —
     * the synced/mandatory path never renders this CTA.
     */
    public function showEnableEncryptionModal(): void
    {
        $this->encryptionStep = 'confirm';
        $this->encryptionProgress = 0;
        $this->showEncryptionModal = true;
    }

    /**
     * Decline the single-device offer (D-07 second bullet) — closes the
     * modal with no action. Never reachable on the mandatory-when-synced path.
     */
    public function declineEncryption(): void
    {
        $this->showEncryptionModal = false;
        $this->encryptionStep = 'confirm';
    }

    /**
     * Run the D-09 migration for the single-device optional-offer path.
     * Mirrors the D-07 auto-trigger in enableSync()/PairingFlowModal::confirmMatch()
     * — same service call, same idempotent contract — just reached via an
     * explicit user confirmation instead of an automatic activation.
     */
    public function enableEncryption(
        EncryptionMigrationService $migrationService,
        CurrentUser $currentUser,
        Session $session,
        DatabaseManager $db,
    ): void {
        $this->encryptionStep = 'progress';

        try {
            $migrationService->migrate($currentUser->user(), $session);
            $userId = $currentUser->user()->id;
            $this->encryptionOn = $this->encryptionEnabled($db, $userId);
            $this->encryptionProgress = $migrationService->progress($userId);
            $this->encryptionStep = 'done';
        } catch (\Throwable) {
            // D-09: migrate() already rolled back to zero half-encrypted rows
            // and restored the pre-migration snapshot on any throw — the
            // error copy reflects that guarantee (Surface B error state).
            $this->encryptionStep = 'error';
        }
    }

    /**
     * Poll the in-flight migration progress (wire:poll target while
     * $encryptionStep === 'progress'). Advances to done once
     * EncryptionMigrationService::progress() reports 100.
     */
    public function pollEncryptionProgress(EncryptionMigrationService $migrationService, CurrentUser $currentUser): void
    {
        if ($this->encryptionStep !== 'progress') {
            return;
        }

        $this->encryptionProgress = $migrationService->progress($currentUser->user()->id);

        if ($this->encryptionProgress >= 100) {
            $this->encryptionStep = 'done';
        }
    }

    /**
     * Close the enable-encryption modal after a done/error terminal state and
     * refresh Surface A to the "On" state.
     */
    public function closeEncryptionModal(): void
    {
        $this->showEncryptionModal = false;
        $this->encryptionStep = 'confirm';
        $this->encryptionProgress = 0;
    }

    // -------------------------------------------------------------------------
    // Action: device remove / revocation (Surfaces C + D, CRYPT-02 / D-06)
    // -------------------------------------------------------------------------

    /**
     * Open the revocation modal (Surface D) for a non-self device row.
     * Mirrors startRename/cancelRename/renameDevice's structural precedent.
     */
    public function startRemove(int $deviceId): void
    {
        $this->removingDeviceId = $deviceId;
        $this->showRemoveModal = true;
    }

    public function cancelRemove(): void
    {
        $this->removingDeviceId = null;
        $this->showRemoveModal = false;
    }

    /**
     * Flux's own close-on-backdrop/-Escape flow only flips the bound
     * $showRemoveModal to false — keep $removingDeviceId in sync so a stale
     * target id never lingers after a non-button dismissal.
     */
    public function updatedShowRemoveModal(bool $value): void
    {
        if (! $value) {
            $this->removingDeviceId = null;
        }
    }

    /**
     * Confirm removal: revoke the device's trust AND rotate the GDK epoch in
     * one operation via GdkRotationService::rotateAndRevoke (T-14-04 — the UI
     * never rotates without revoking, and never revokes without rotating).
     *
     * The row is marked "removed" IN PLACE (not reloaded via
     * DeviceRegistryService::confirmedDevices(), which excludes it the moment
     * confirmed_at is cleared) so the "Removed" badge renders per Surface C —
     * the row disappears only on the next page load/navigation.
     */
    public function removeDevice(GdkRotationService $rotationService, CurrentUser $currentUser): void
    {
        if ($this->removingDeviceId === null) {
            return;
        }

        $targetId = $this->removingDeviceId;
        $userId = $currentUser->user()->id;

        try {
            $rotationService->rotateAndRevoke($userId, $targetId);
        } catch (\Throwable) {
            $this->flashMessage = 'Failed to remove device. Please try again.';
            $this->cancelRemove();

            return;
        }

        foreach ($this->devices as $index => $device) {
            if (($device['id'] ?? null) === $targetId) {
                $this->devices[$index]['removed'] = true;
            }
        }

        // Removed rows sort to the bottom (Surface C).
        usort(
            $this->devices,
            static function (array $a, array $b): int {
                $aRemoved = ($a['removed'] ?? false) === true;
                $bRemoved = ($b['removed'] ?? false) === true;

                return $aRemoved <=> $bRemoved;
            },
        );

        $this->cancelRemove();
    }

    // -------------------------------------------------------------------------
    // Event hooks
    // -------------------------------------------------------------------------

    /**
     * The pairing modal closed (cancel / done): refresh the device list so a
     * newly-confirmed peer appears. The modal component owns its own open state.
     */
    #[On('pairing-closed')]
    public function onPairingClosed(CurrentUser $currentUser, DeviceRegistryService $registry): void
    {
        $this->devices = $this->loadDevices($registry, $currentUser->user()->id);
    }

    /**
     * A peer was just confirmed in the pairing modal (the success step, WR-02):
     * refresh the device list live so the newly-trusted device appears without
     * waiting for the user to click "Done". Also re-reads the encryption
     * state (D-07): PairingFlowModal::confirmMatch auto-triggers the
     * migration the moment this device becomes a confirmed peer.
     */
    #[On('pairing-confirmed')]
    public function onPairingConfirmed(CurrentUser $currentUser, DeviceRegistryService $registry, DatabaseManager $db): void
    {
        $userId = $currentUser->user()->id;
        $this->devices = $this->loadDevices($registry, $userId);
        $this->syncEnabled = true;
        $this->encryptionOn = $this->encryptionEnabled($db, $userId);
    }

    /**
     * The app-lock was just configured in the sibling AppLockSettingsSection
     * (D-02). Re-evaluate the enable-sync gate live so the "Set an app lock
     * first" notice clears and the toggle enables without a full page reload.
     */
    #[On('app-lock-configured')]
    public function onAppLockConfigured(CurrentUser $currentUser, AppLockClientConfig $lockConfig): void
    {
        $this->appLockConfigured = $lockConfig->idleTimeoutMs($currentUser->user()->id) !== null;

        if ($this->appLockConfigured) {
            $this->flashMessage = '';
        }
    }

    // -------------------------------------------------------------------------
    // Action: save relay endpoint URL (D-01 / T-13-08 / T-13-18)
    // -------------------------------------------------------------------------

    /**
     * Persist the relay endpoint URL via RelayConfig::setEndpointUrl().
     *
     * Gated behind app-lock being configured (T-13-18 — relay-config writes are
     * protected by the same gate as sync enable). An empty URL clears the relay
     * (reverts to LAN-direct, D-01). Non-HTTPS URLs are accepted but flagged
     * insecure (T-13-08 / Pitfall 6 — the relay is ZK regardless, but an http://
     * endpoint leaks metadata to a network observer).
     */
    public function saveRelayEndpoint(RelayConfig $relayConfig): void
    {
        // T-13-18: relay-URL writes are gated on the same app-lock requirement as
        // sync enable. No relayUrl change is possible without an active app-lock.
        if (! $this->appLockConfigured) {
            $this->relayFlashMessage = 'Set an app lock first to change sync settings.';

            return;
        }

        $url = trim($this->relayEndpointUrl);

        try {
            $relayConfig->setEndpointUrl($url === '' ? null : $url);
            $this->relayIsInsecure = $relayConfig->isInsecure();
            $this->relayEndpointUrl = $relayConfig->endpointUrl() ?? '';
            $this->relayFlashMessage = $url === '' ? 'Relay endpoint cleared.' : 'Relay endpoint saved.';
        } catch (\RuntimeException $e) {
            $this->relayFlashMessage = 'Failed to save relay endpoint: '.$e->getMessage();
        }
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
     * Whether at-rest encryption is ON for $userId on this device
     * (`sync_encryption_state.current_epoch` is set). Read directly via the
     * injected DatabaseManager — this table is device-local and never synced
     * (see the create-table migration docblock), so a raw scoped read here
     * mirrors selfRowExists()'s own precedent rather than reaching into
     * Modules\Core\Public\Services\EncryptionMigrationService::isEnabled()
     * for a value this component can read in one query already.
     */
    private function encryptionEnabled(DatabaseManager $db, int $userId): bool
    {
        $value = $db->connection()->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->value('current_epoch');

        return $value !== null;
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
                'removed' => false,
            ];
        }

        return $devices;
    }

    /**
     * The current displayed name for a device id (from the loaded list).
     * Public — Surface D's revocation modal sub-label ("Removing: {name}")
     * reads this from the blade view.
     */
    public function currentNameFor(int $deviceId): string
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
