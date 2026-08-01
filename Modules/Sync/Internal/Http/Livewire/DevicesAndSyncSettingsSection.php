<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Auth\Public\AppLockEvents;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Http\Livewire\Concerns\ManagesDeviceRenaming;
use Modules\Sync\Internal\Http\Livewire\Concerns\ReadsDeviceState;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class DevicesAndSyncSettingsSection extends Component
{
    use ManagesDeviceRenaming;
    use ReadsDeviceState;

    public bool $syncEnabled = false;

    public bool $appLockConfigured = false;

    public string $flashMessage = '';

    /**
     * @var list<array<string, mixed>>
     */
    public array $devices = [];

    public ?int $renamingDeviceId = null;

    public string $renameValue = '';

    public ?int $removingDeviceId = null;

    // A separate boolean flag (rather than binding <flux:modal wire:model>
    // directly to the ?int $removingDeviceId) so Flux's own
    // close-on-backdrop/-Escape flow — which sets its bound model to a JS
    // `false` — can never violate the `?int` property type.
    public bool $showRemoveModal = false;

    // Whether at-rest encryption is currently ON for this user on this
    // device (sync_encryption_state.current_epoch is set).
    public bool $encryptionOn = false;

    public bool $showEncryptionModal = false;

    // Enable-encryption modal step: confirm|progress|done|error.
    // 0-100 migration progress is sourced from EncryptionMigrationService::progress().
    public string $encryptionStep = 'confirm';

    public int $encryptionProgress = 0;

    // Set true when the mandatory at-rest-encryption auto-activation threw
    // during enableSync() — the device is synced but not yet encrypted, so
    // the blade renders a retry affordance instead of failing silently.
    public bool $encryptionActivationFailed = false;

    // Default none — empty string means "not configured", which resolves to
    // LAN-direct out-of-box.
    public string $relayEndpointUrl = '';

    // Whether the configured relay URL uses plain HTTP (not HTTPS); surfaces
    // a warning in the blade view.
    public bool $relayIsInsecure = false;

    public string $relayFlashMessage = '';

    public function mount(
        CurrentUser $currentUser,
        DatabaseManager $db,
        AppLockClientConfig $lockConfig,
        DeviceRegistryService $registry,
        RelayConfig $relayConfig,
    ): void {
        $userId = $currentUser->user()->id;

        // An app-lock is configured iff AppLockClientConfig returns a
        // non-null idle timeout. Read via the Auth public service (never
        // query user_app_lock_configs from the Sync module — cross-module boundary).
        $this->appLockConfigured = $lockConfig->idleTimeoutMs($userId) !== null;

        $this->syncEnabled = $this->selfRowExists($db, $userId);
        $this->devices = $this->loadDevices($registry, $userId);

        $this->relayEndpointUrl = $relayConfig->endpointUrl() ?? '';
        $this->relayIsInsecure = $relayConfig->isInsecure();

        $this->encryptionOn = $this->encryptionEnabled($db, $userId);
    }

    // Enable sync: generate + persist the device identity and show the self
    // device. Gated on an app-lock being configured. The moment this device
    // becomes a sync peer, at-rest encryption auto-activates via
    // EncryptionMigrationService::migrate() (idempotent — safe to call again).
    public function enableSync(
        CurrentUser $currentUser,
        DeviceIdentityService $identityService,
        Session $session,
        DatabaseManager $db,
        DeviceRegistryService $registry,
        EncryptionMigrationService $migrationService,
        LoggerInterface $logger,
    ): void {
        if ($this->syncEnabled) {
            return;
        }

        // UI gate — defense-in-depth alongside the service-level
        // LogicException thrown when the KEK is unavailable.
        if (! $this->appLockConfigured) {
            $this->flashMessage = Lang::get('sync::devices.flash.app_lock_first');

            return;
        }

        $userId = $currentUser->user()->id;

        try {
            $identityService->generateAndPersist($userId, $session);
        } catch (\LogicException) {
            // The KEK was unavailable despite the configured-lock check (the app
            // is locked, or the session is keyless) — surface the recovery copy.
            $this->flashMessage = Lang::get('sync::devices.flash.enable_failed');

            return;
        }

        $this->syncEnabled = true;
        $this->flashMessage = '';
        $this->devices = $this->loadDevices($registry, $userId);

        // Mandatory auto-activation — no decline path. A migration failure
        // never un-enables sync. Do NOT swallow it silently: log it and
        // raise a non-blocking indicator so the blade can retry.
        $this->encryptionActivationFailed = false;
        try {
            $migrationService->migrate($currentUser->user(), $session);
        } catch (\Throwable $e) {
            $this->encryptionActivationFailed = true;
            $logger->warning('At-rest encryption auto-activation failed during enableSync.', [
                'user_id' => $userId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        $this->encryptionOn = $this->encryptionEnabled($db, $userId);
    }

    // ONLY reachable from the single-device (sync off) optional offer — the
    // synced/mandatory path never renders this CTA.
    public function showEnableEncryptionModal(): void
    {
        $this->encryptionStep = 'confirm';
        $this->encryptionProgress = 0;
        $this->showEncryptionModal = true;
    }

    public function declineEncryption(): void
    {
        $this->showEncryptionModal = false;
        $this->encryptionStep = 'confirm';
    }

    // Runs the migration for the single-device optional-offer path (same
    // idempotent service call enableSync() auto-triggers). Known cosmetic
    // limitation: migrate() runs SYNCHRONOUSLY, so wire:poll's
    // pollEncryptionProgress() can't animate — the bar jumps 0 -> done.
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
            // migrate() already rolled back to zero half-encrypted rows and
            // restored the pre-migration snapshot on any throw — the error
            // copy reflects that guarantee.
            $this->encryptionStep = 'error';
        }
    }

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

    public function closeEncryptionModal(): void
    {
        $this->showEncryptionModal = false;
        $this->encryptionStep = 'confirm';
        $this->encryptionProgress = 0;
    }

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

    // Flux's own close-on-backdrop/-Escape flow only flips the bound
    // $showRemoveModal to false — keep $removingDeviceId in sync so a stale
    // target id never lingers after a non-button dismissal.
    public function updatedShowRemoveModal(bool $value): void
    {
        if (! $value) {
            $this->removingDeviceId = null;
        }
    }

    // Revoke the device's trust AND rotate the GDK epoch in one operation via
    // GdkRotationService::rotateAndRevoke. The row is marked "removed" IN
    // PLACE (not reloaded, which would exclude it immediately) so the
    // "Removed" badge renders until the next page load/navigation.
    public function removeDevice(
        GdkRotationService $rotationService,
        CurrentUser $currentUser,
        Session $session,
        DatabaseManager $db,
    ): void {
        if ($this->removingDeviceId === null) {
            return;
        }

        $targetId = $this->removingDeviceId;
        $userId = $currentUser->user()->id;

        // Server-side self-removal guard: Livewire actions are
        // client-invokable, so a crafted startRemove(selfRowId) must be
        // rejected against an AUTHORITATIVE, user-scoped DB read — never
        // against the client-hydrated $this->devices array.
        $targetIsSelf = $db->connection()->table('device_registry')
            ->where('id', $targetId)
            ->where('user_id', $userId)
            ->value('is_self');
        if (is_numeric($targetIsSelf) && (int) $targetIsSelf === 1) {
            $this->flashMessage = Lang::get('sync::devices.flash.cannot_remove_self');
            $this->cancelRemove();

            return;
        }

        try {
            $rotationService->rotateAndRevoke($userId, $targetId, $session);
        } catch (\Throwable) {
            $this->flashMessage = Lang::get('sync::devices.flash.remove_failed');
            $this->cancelRemove();

            return;
        }

        foreach ($this->devices as $index => $device) {
            if (($device['id'] ?? null) === $targetId) {
                $this->devices[$index]['removed'] = true;
            }
        }

        // Removed rows sort to the bottom of the list so the still-active
        // devices stay together at the top.
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

    #[On('pairing-closed')]
    public function onPairingClosed(CurrentUser $currentUser, DeviceRegistryService $registry): void
    {
        $this->devices = $this->loadDevices($registry, $currentUser->user()->id);
    }

    // A peer was just confirmed in the pairing modal: refresh the device
    // list live and re-read the encryption state, since
    // PairingFlowModal::confirmMatch auto-triggers the migration.
    #[On('pairing-confirmed')]
    public function onPairingConfirmed(CurrentUser $currentUser, DeviceRegistryService $registry, DatabaseManager $db): void
    {
        $userId = $currentUser->user()->id;
        $this->devices = $this->loadDevices($registry, $userId);
        $this->syncEnabled = true;
        $this->encryptionOn = $this->encryptionEnabled($db, $userId);
    }

    // The app-lock was just configured in the sibling AppLockSettingsSection.
    // Re-evaluate the enable-sync gate live so the "Set an app lock first"
    // notice clears and the toggle enables without a full page reload.
    #[On(AppLockEvents::CONFIGURED)]
    public function onAppLockConfigured(CurrentUser $currentUser, AppLockClientConfig $lockConfig): void
    {
        $this->appLockConfigured = $lockConfig->idleTimeoutMs($currentUser->user()->id) !== null;

        if ($this->appLockConfigured) {
            $this->flashMessage = '';
        }
    }

    // Gated behind app-lock being configured, same as sync enable. An empty
    // URL clears the relay (LAN-direct). Non-HTTPS URLs are accepted but
    // flagged insecure — an http:// endpoint leaks metadata to an observer.
    public function saveRelayEndpoint(RelayConfig $relayConfig): void
    {
        if (! $this->appLockConfigured) {
            $this->relayFlashMessage = Lang::get('sync::devices.flash.app_lock_first_settings');

            return;
        }

        $url = trim($this->relayEndpointUrl);

        try {
            $relayConfig->setEndpointUrl($url === '' ? null : $url);
            $this->relayIsInsecure = $relayConfig->isInsecure();
            $this->relayEndpointUrl = $relayConfig->endpointUrl() ?? '';
            $this->relayFlashMessage = $url === '' ? Lang::get('sync::devices.flash.relay_cleared') : Lang::get('sync::devices.flash.relay_saved');
        } catch (\RuntimeException $e) {
            $this->relayFlashMessage = Lang::get('sync::devices.flash.relay_save_failed', ['message' => $e->getMessage()]);
        }
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('sync::livewire.devices-and-sync-settings-section');
    }
}
