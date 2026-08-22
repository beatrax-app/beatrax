<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Auth\Public\AppLockEvents;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\Crypto\EncryptionSetupStep;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Exceptions\DeviceIdentityUnreadableException;
use Modules\Sync\Internal\Http\Livewire\Concerns\ManagesDeviceRenaming;
use Modules\Sync\Internal\Http\Livewire\Concerns\ReadsDeviceState;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Identity\DeviceIdentityState;
use Modules\Sync\Internal\OpLog\SyncBacklogState;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Enums\PairingSide;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\EncryptionRecoveryMarkers;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;
use Modules\Sync\Public\Services\HistoryReprojector;
use Modules\Sync\Public\Services\PairingGateway;
use Modules\Sync\Public\Services\SyncStatusService;
use Psr\Log\LoggerInterface;

final class DevicesAndSyncSettingsSection extends Component
{
    use HoldsFlashMessage;
    use ManagesDeviceRenaming;
    use ReadsDeviceState;

    public bool $syncEnabled = false;

    public bool $appLockConfigured = false;

    // A key-file this device holds but cannot open. Distinct from "sync is
    // off": the identity is there, it signs this device's history, and no
    // unlock reaches it — so the section says so rather than presenting the
    // ordinary off-state and quietly failing every action behind it.
    public bool $identityUnreadable = false;

    /**
     * @var list<array<string, mixed>>
     */
    public array $devices = [];

    // The peer of a handshake that has reached the safety-word comparison and
    // is waiting on this device, or '' when none is. Empty is the whole of the
    // "nothing pending" state: a ceremony this device owns no side of, and one
    // already confirmed, both read as no ceremony here.
    public string $pairingWaitingOnPeer = '';

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

    // Stays a string on the wire: a public property is rehydrated straight from
    // the client payload with no enum coercion. 0-100 migration progress is
    // sourced from EncryptionMigrationService::progress().
    public string $encryptionStep = EncryptionSetupStep::Confirm->value;

    public int $encryptionProgress = 0;

    // A peer's data this device has received and not yet written into the
    // tables the screens read. A string on the wire for the same reason
    // $encryptionStep is one. Nothing behind any value of it is lost.
    public string $syncBacklog = SyncBacklogState::None->value;

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
        PairingGateway $pairing,
        GdkEpochDeliveryGateway $epochDelivery,
        DeviceIdentityLoader $identityLoader,
        Session $session,
        HistoryReprojector $reprojector,
        EncryptionRecoveryMarkers $markers,
    ): void {
        $userId = $currentUser->user()->id;

        $this->identityUnreadable = $identityLoader->state($userId, $session) === DeviceIdentityState::Unreadable;

        // An app-lock is configured iff AppLockClientConfig returns a
        // non-null idle timeout. Read via the Auth public service (never
        // query user_app_lock_configs from the Sync module — cross-module boundary).
        $this->appLockConfigured = $lockConfig->idleTimeoutMs($userId) !== null;

        $this->syncEnabled = $this->selfRowExists($db, $userId);
        $this->devices = $this->loadDevices($registry, $userId);

        $this->relayEndpointUrl = $relayConfig->endpointUrl() ?? '';
        $this->relayIsInsecure = $relayConfig->isInsecure();

        $this->encryptionOn = $this->encryptionEnabled($db, $userId);

        // Kept alongside HoldPairingCeremonyOpenOnUnlock, not replaced by it: the
        // listener catches a lapse the lock caused, this one catches a lapse it
        // did not — a 30-minute idle window outlives a pairing TTL with no lock
        // in between. The extension is an idempotent UPDATE, so both is free.

        // Before the read, not after: the read filters on a live TTL, so a
        // ceremony that lapsed behind the lock would report as no ceremony and
        // the extension would never reach the row it exists for.
        $pairing->holdCeremonyOpenAcrossLock($userId, $session);
        $this->pairingWaitingOnPeer = $this->peerWaitingOnThisDevice($pairing, $session, $userId);

        $this->applyHeldKeyWraps($pairing, $epochDelivery, $session, $userId);

        // After the inbox drain, never before it: that drain is what installs
        // a wrap this device was holding, and asking first reports a wait that
        // the same mount had already ended.
        $this->syncBacklog = $this->readBacklog($reprojector, $markers, $session, $userId)->value;
    }

    // Read here rather than in render(): render runs on every poll, and the
    // question costs an index seek plus a keyring read. The recovery pass runs
    // after this response, so a Deferred reported here is gone by the next one
    // — which is the transient the reader is being shown.
    private function readBacklog(
        HistoryReprojector $reprojector,
        EncryptionRecoveryMarkers $markers,
        Session $session,
        int $userId,
    ): SyncBacklogState {
        if (! $markers->isEnrolled($userId)) {
            return SyncBacklogState::None;
        }

        try {
            return $reprojector->backlogState(
                $userId,
                $session,
                $markers->historyReprojectedAt($userId),
                $markers->reprojectedKeyringFingerprint($userId),
            );
        } catch (\Throwable) {
            return SyncBacklogState::None;
        }
    }

    // A ceremony lives on the row, not in the modal that started it, so an
    // auto-lock or a navigation loses the screen and not the handshake. The
    // page has to say so: the token expires in minutes, and the modal's own
    // resume is behind a button that reads as starting a new pairing.
    /**
     * @link ../../../../../.docs/features/sync/pairing-handshake.md#a-ceremony-outlives-the-screen-that-started-it
     */
    private function peerWaitingOnThisDevice(PairingGateway $pairing, Session $session, int $userId): string
    {
        $inFlight = $pairing->inFlightFor($userId);

        if ($inFlight === null || $inFlight['state'] === PairingGateway::STATE_CONFIRMED) {
            return '';
        }

        $side = $pairing->sideOwnedBySelf(
            $inFlight['initiator_device_id'],
            $inFlight['responder_device_id'],
            $userId,
            $session,
        );

        if ($side === null) {
            return '';
        }

        $names = $pairing->deviceNamesFor($inFlight['id'], $userId);

        return match ($side->peer()) {
            PairingSide::Initiator => $names['initiator'],
            PairingSide::Responder => $names['responder'],
        } ?? Lang::get('sync::devices.peer_default_name');
    }

    // The listener that receives these can never open one: it resolves a
    // session no middleware ever started, so its app-lock key is absent by
    // construction. This mount is the unlocked pass that comes back for what
    // it had to leave in the mailbox.
    private function applyHeldKeyWraps(
        PairingGateway $pairing,
        GdkEpochDeliveryGateway $epochDelivery,
        Session $session,
        int $userId,
    ): void {
        $deviceId = $pairing->currentDeviceId($userId, $session);
        if ($deviceId === null) {
            return;
        }

        $epochDelivery->drainInbox($userId, $deviceId, $session);
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
        } catch (DeviceIdentityUnreadableException) {
            // Refused rather than minted over: this device already has a
            // key-file, it just cannot open it. Raising the flag puts the
            // notice and its explicit replacement action on screen.
            $this->identityUnreadable = true;
            $this->flashMessage = Lang::get('sync::devices.identity_unreadable');

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
                ...SafeExceptionContext::describe($e),
            ]);
        }

        $this->encryptionOn = $this->encryptionEnabled($db, $userId);
    }

    // The only route out of an identity key-file this device cannot open, and
    // destructive on purpose — the notice states what is lost before the tap.
    // Nothing on a render path may take this decision, which is why the loader
    // reports the state instead of acting on it.
    public function replaceUnreadableIdentity(
        CurrentUser $currentUser,
        DeviceIdentityService $identityService,
        Session $session,
        DatabaseManager $db,
        DeviceRegistryService $registry,
        EncryptionMigrationService $migrationService,
        LoggerInterface $logger,
    ): void {
        // Offered only where the blade offers it. A registered self row means
        // an identity peers were told about and a history signed under it:
        // retiring THAT needs the registry row and every pairing retired with
        // it, which is not a thing one settings button may decide.
        if (! $this->identityUnreadable || $this->syncEnabled) {
            return;
        }

        try {
            $identityService->retireUnreadableIdentity($currentUser->user()->id, $session);
        } catch (DeviceIdentityUnreadableException) {
            $this->flashMessage = Lang::get('sync::devices.flash.identity_replace_failed');

            return;
        }

        // Straight on through the ordinary enable, so a device recovering from
        // a dead key-file lands in the state a first-time enable leaves:
        // minted identity, self row, at-rest encryption activated.
        $this->identityUnreadable = false;
        $this->enableSync($currentUser, $identityService, $session, $db, $registry, $migrationService, $logger);

        if ($this->syncEnabled) {
            $this->flashMessage = Lang::get('sync::devices.flash.identity_replaced');
        }
    }

    // ONLY reachable from the single-device (sync off) optional offer — the
    // synced/mandatory path never renders this CTA.
    public function showEnableEncryptionModal(): void
    {
        $this->encryptionStep = EncryptionSetupStep::Confirm->value;
        $this->encryptionProgress = 0;
        $this->showEncryptionModal = true;
    }

    public function declineEncryption(): void
    {
        $this->showEncryptionModal = false;
        $this->encryptionStep = EncryptionSetupStep::Confirm->value;
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
        $this->encryptionStep = EncryptionSetupStep::Progress->value;

        try {
            $migrationService->migrate($currentUser->user(), $session);
            $userId = $currentUser->user()->id;
            $this->encryptionOn = $this->encryptionEnabled($db, $userId);
            $this->encryptionProgress = $migrationService->progress($userId);
            $this->encryptionStep = EncryptionSetupStep::Done->value;
        } catch (\Throwable) {
            // migrate() already rolled back to zero half-encrypted rows and
            // restored the pre-migration snapshot on any throw — the error
            // copy reflects that guarantee.
            $this->encryptionStep = EncryptionSetupStep::Error->value;
        }
    }

    public function pollEncryptionProgress(EncryptionMigrationService $migrationService, CurrentUser $currentUser): void
    {
        if ($this->currentEncryptionStep() !== EncryptionSetupStep::Progress) {
            return;
        }

        $this->encryptionProgress = $migrationService->progress($currentUser->user()->id);

        if ($this->encryptionProgress >= 100) {
            $this->encryptionStep = EncryptionSetupStep::Done->value;
        }
    }

    private function currentEncryptionStep(): EncryptionSetupStep
    {
        return EncryptionSetupStep::tryFrom($this->encryptionStep) ?? EncryptionSetupStep::Confirm;
    }

    public function closeEncryptionModal(): void
    {
        $this->showEncryptionModal = false;
        $this->encryptionStep = EncryptionSetupStep::Confirm->value;
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
        DeviceRegistryService $registry,
        SyncStatusService $statusService,
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

            // Revocation stops at confirmed_at; everything else keyed to this
            // device — its sessions, mailbox, tokens, and the row itself —
            // has to go, or "removed" devices keep surfacing elsewhere.
            $registry->purge($userId, $targetId);

            // purge() can only clear sessions it can name. A failed handshake
            // records a session under a peer id that never became a device,
            // so removing the real one left those behind — still red, still
            // listed, with nothing tying them to anything removable.
            $statusService->forgetOrphanedSessions($userId);
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

    // The notice is re-read rather than cleared: closing the modal on a
    // cancelled handshake retires it, and closing it on a live one does not.
    #[On('pairing-closed')]
    public function onPairingClosed(
        CurrentUser $currentUser,
        DeviceRegistryService $registry,
        PairingGateway $pairing,
        Session $session,
    ): void {
        $userId = $currentUser->user()->id;
        $this->devices = $this->loadDevices($registry, $userId);
        $this->pairingWaitingOnPeer = $this->peerWaitingOnThisDevice($pairing, $session, $userId);
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
        $this->pairingWaitingOnPeer = '';
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
        // Under its own name, not $encryptionStep: view data cannot shadow a
        // public property, and the view needs the resolved enum rather than the
        // raw string the client last put on the wire.
        return $views->make('sync::livewire.devices-and-sync-settings-section', [
            'encryptionModalStep' => $this->currentEncryptionStep(),
            'backlogState' => SyncBacklogState::tryFrom($this->syncBacklog) ?? SyncBacklogState::None,
        ]);
    }
}
