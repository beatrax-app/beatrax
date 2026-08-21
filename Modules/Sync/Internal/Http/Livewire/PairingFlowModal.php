<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\Concerns\ReadsPairingTokenRow;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\OpLog\PreSyncHistoryCapture;
use Modules\Sync\Internal\Pairing\PairingRelayCourier;
use Modules\Sync\Internal\Pairing\PairingRowGuards;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\QrPayloadBuilder;
use Modules\Sync\Internal\Pairing\RelayBootstrap;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Events\SyncTransportCredentialsAvailable;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Psr\Log\LoggerInterface;
use Throwable;

final class PairingFlowModal extends Component
{
    use HoldsFlashMessage;
    use ReadsPairingTokenRow;

    // Translation key (resolved via Lang::get at each use site) rather than
    // literal copy, so the const stays free of the banned container call.
    private const IDENTITY_LOCKED_MESSAGE = 'sync::pairing.identity_locked';

    public bool $open = false;

    public string $step = 'choose_direction';

    // The pairing_tokens row id for the in-flight handshake ('' when none).
    // #[Locked] — the trust gate MUST NOT let the client retarget which token
    // is being confirmed. Only server code (showMyCode/submitCode) may set
    // this; Livewire rejects any client-side mutation.
    #[Locked]
    public string $pairingTokenId = '';

    public string $wordCode = '';

    // #[Locked] — the pairing view raw-echoes this with {!! !!} because an
    // inline SVG cannot be escaped. That is only safe while the markup is
    // server-built: an unlocked public property is rehydrated from the
    // client payload on every request, which would make it an XSS sink.
    #[Locked]
    public string $qrSvg = '';

    public int $expiresInSeconds = 600;

    public bool $awaitingPeer = false;

    // Non-blocking retry indicator for a failed epoch fan-out on the desktop
    // side. A fan-out failure must never silently strand the newly-confirmed
    // phone permanently unable to decrypt, so this is logged (never key
    // material) and surfaced here for a future retry affordance.
    public bool $fanOutFailed = false;

    // #[Locked] — a client must never flip its side and confirm the peer's
    // column. The authoritative side is re-derived server-side in
    // PairingTokenService::confirm() from the caller's own device id.
    #[Locked]
    public string $side = '';

    /**
     * @var list<string>
     */
    public array $safetyWords = [];

    // Shown beside the words so the user confirms WHICH two devices are
    // pairing, not just that six words match on two screens.
    public string $selfDeviceName = '';

    public string $peerDeviceName = '';

    public function mount(bool $open = false): void
    {
        $this->open = $open;
    }

    // Rendered unconditionally so the hosting <flux:modal wire:model="open">
    // sees a real false->true transition, which is the only thing Flux shows
    // the dialog on. The flow resets first so a reopened modal never resumes a
    // cancelled handshake; a still-live one is picked back up below.
    #[On('open-pairing-modal')]
    public function openModal(
        CurrentUser $currentUser,
        Dispatcher $events,
        PairingGateway $gateway,
        PairingRelayCourier $relayCourier,
        LoggerInterface $logger,
        Session $session,
        DeviceRegistryService $registry,
    ): void {
        // A daemon started while the app was locked holds no transport keypair
        // and rejects every handshake. Opening this modal is an unlocked,
        // authenticated moment, and exactly when the listener must be ready.
        $events->dispatch(new SyncTransportCredentialsAvailable($currentUser->user()->id));

        $this->step = 'choose_direction';
        $this->pairingTokenId = '';
        $this->wordCode = '';
        $this->qrSvg = '';
        $this->expiresInSeconds = 600;
        $this->flashMessage = '';
        $this->awaitingPeer = false;
        $this->fanOutFailed = false;
        $this->side = '';
        $this->safetyWords = [];
        $this->open = true;

        $this->resumeInFlight($currentUser->user()->id, $gateway, $relayCourier, $logger, $session, $registry);
    }

    // Picks up a handshake that is still live. This modal's poll is the only
    // thing draining the relay, so a desktop that confirmed first and closed it
    // left the phone's PAIR_CONFIRM undelivered, and reopening started over.
    private function resumeInFlight(
        int $userId,
        PairingGateway $gateway,
        PairingRelayCourier $relayCourier,
        LoggerInterface $logger,
        Session $session,
        DeviceRegistryService $registry,
    ): void {
        try {
            $relayCourier->drainAndApply($userId);
        } catch (Throwable $e) {
            $logger->warning('PairingFlowModal: relay drain failed while resuming.', [
                'user_id' => $userId,
                'exception' => $e::class,
            ]);
        }

        $inFlight = $gateway->inFlightFor($userId);

        if ($inFlight === null) {
            return;
        }

        // Which side this device is, read off its OWN identity. It used to be
        // assumed to be the initiator, so a desktop resuming as the responder
        // confirmed toward its own device id and looked up its own name as the
        // peer's — the one thing currentDeviceId() exists to prevent.
        $side = PairingRowGuards::sideOwnedByIds(
            $inFlight['initiator_device_id'],
            $inFlight['responder_device_id'],
            $gateway->currentDeviceId($userId, $session) ?? '',
        );

        if ($side === null) {
            return;
        }

        $this->pairingTokenId = (string) $inFlight['id'];
        $this->safetyWords = $inFlight['safety_words'];
        $this->side = $side;

        // A confirmed handshake is finished, and re-presenting the trust gate
        // asked for a safety-number confirmation that confirm() then refuses
        // as already given — leaving the modal with no way forward and no way
        // to start again.
        $this->step = $inFlight['state'] === PairingState::Confirmed->value ? 'success' : 'confirm';

        $this->hydrateDeviceNames($gateway, $registry, $userId, $side === 'responder');
    }

    // Loads the identity, issues a token, builds the QR + word-code, and
    // advances to show_code.
    public function showMyCode(
        CurrentUser $currentUser,
        DeviceIdentityLoader $identityLoader,
        PairingTokenService $tokenService,
        QrPayloadBuilder $qrBuilder,
        WordCodeEncoder $wordEncoder,
        DatabaseManager $db,
        Session $session,
        RelayConfig $relayConfig,
        DeviceRegistryService $registry,
    ): void {
        $userId = $currentUser->user()->id;

        $identity = $identityLoader->load($userId, $session);
        if ($identity === null) {
            $this->flashMessage = Lang::get(self::IDENTITY_LOCKED_MESSAGE);

            return;
        }

        $token = $tokenService->issue(
            $userId,
            $identity->deviceId,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
        );

        // Carries this device's own relay endpoint (+ optional bearer token)
        // in the QR so a fresh phone can auto-configure its own transport
        // before the cross-device confirm handshake needs one. Null/absent
        // when no relay is configured on this device.
        $this->qrSvg = $qrBuilder->buildSvg(
            $identity->deviceId,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
            $token,
            // This device's own name, so the scanner can label it with what
            // it calls itself rather than the "Paired device" placeholder.
            $registry->localDeviceName($userId),
            new RelayBootstrap(
                $relayConfig->endpointUrl(),
                $relayConfig->authToken(),
                $relayConfig->pin(),
            ),
        );
        $this->wordCode = $wordEncoder->encode($token);
        $this->pairingTokenId = (string) $this->tokenRowId($db, $userId, $token);
        $this->side = 'initiator';
        $this->expiresInSeconds = 600;
        $this->flashMessage = '';
        $this->step = 'show_code';
    }

    public function enterACode(): void
    {
        $this->step = 'enter_code';
        $this->wordCode = '';
        $this->flashMessage = '';
        $this->side = 'responder';
    }

    // Decodes the typed word-code and accepts the peer's token, binding this
    // device's responder keys. On success advances to confirm and derives
    // the shared safety-number; on failure stays on enter_code with an error.
    public function submitCode(
        CurrentUser $currentUser,
        DeviceIdentityLoader $identityLoader,
        PairingTokenService $tokenService,
        WordCodeEncoder $wordEncoder,
        SafetyNumberDeriver $safetyDeriver,
        DatabaseManager $db,
        Session $session,
        PairingGateway $gateway,
        DeviceRegistryService $registry,
    ): void {
        $userId = $currentUser->user()->id;

        $identity = $identityLoader->load($userId, $session);
        if ($identity === null) {
            $this->flashMessage = Lang::get(self::IDENTITY_LOCKED_MESSAGE);

            return;
        }

        try {
            $tokenHex = $wordEncoder->decode($this->wordCode);
        } catch (\InvalidArgumentException) {
            $this->flashMessage = Lang::get('sync::pairing.invalid_code');

            return;
        }

        $accepted = $tokenService->accept(
            $tokenHex,
            $userId,
            $identity->deviceId,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
        );

        if ($accepted === false || ! $accepted instanceof \stdClass) {
            $this->flashMessage = Lang::get('sync::pairing.invalid_code');

            return;
        }

        $this->pairingTokenId = (string) (is_numeric($accepted->id) ? (int) $accepted->id : 0);
        $this->side = 'responder';
        $this->safetyWords = $this->deriveSafetyWords($db, $safetyDeriver, $userId);
        $this->hydrateDeviceNames($gateway, $registry, $userId, asResponder: true);
        $this->flashMessage = '';
        $this->step = 'confirm';
    }

    // Advances show_code -> confirm when the responder has accepted, and any
    // step -> success when both sides have confirmed. The side that confirms
    // FIRST learns of the completed both-confirm HERE (not from its own
    // confirmMatch() call), so the same auto-trigger must also run here.
    public function checkPairingState(
        CurrentUser $currentUser,
        DatabaseManager $db,
        SafetyNumberDeriver $safetyDeriver,
        Session $session,
        EncryptionMigrationService $migrationService,
        PairingGateway $gateway,
        LoggerInterface $logger,
        PairingRelayCourier $relayCourier,
        PreSyncHistoryCapture $historyCapture,
        DeviceRegistryService $registry,
    ): void {
        if ($this->pairingTokenId === '') {
            return;
        }

        $userId = $currentUser->user()->id;

        // Applies any inbound cross-device frame BEFORE re-reading local
        // state — this is what applies the phone's PAIR_RESPONDER_ACCEPT and
        // PAIR_CONFIRM. drainAndApply() is designed to never throw; the
        // try/catch here is defense-in-depth regardless.
        try {
            $relayCourier->drainAndApply($userId);
        } catch (Throwable $e) {
            $logger->warning('PairingFlowModal: cross-device relay drain failed during poll.', [
                'user_id' => $userId,
                'exception' => $e::class,
            ]);
        }

        $row = $db->connection()->table('pairing_tokens')
            ->where('id', (int) $this->pairingTokenId)
            ->where('user_id', $userId)
            ->first(['state']);

        if ($row === null) {
            return;
        }

        if ($row->state === PairingState::AwaitingConfirm->value && $this->step === 'show_code') {
            $this->safetyWords = $this->deriveSafetyWords($db, $safetyDeriver, $userId);
            $this->hydrateDeviceNames($gateway, $registry, $userId);
            $this->step = 'confirm';

            return;
        }

        if ($row->state === PairingState::Confirmed->value && $this->step !== 'success') {
            $this->step = 'success';

            try {
                $migrationService->migrate($currentUser->user(), $session);
            } catch (Throwable) {
                // Best-effort — mirrors confirmMatch()'s own
                // migration-failure handling below.
            }

            // Everything on this device that predates sync, captured before
            // the new peer asks for it. A device that enabled sync before
            // capture worked has an empty log and would hand over nothing.
            $historyCapture->capture($userId);

            // Fans out EVERY desktop epoch to the just-confirmed device —
            // ONLY reachable inside the state === CONFIRMED branch. migrate()
            // runs FIRST so a desktop that had never enabled encryption has
            // something to deliver.
            $this->fanOutToNewlyConfirmedDevice($db, $gateway, $logger, $userId, $session);

            $this->dispatch('pairing-confirmed');
        }
    }

    // Which name is "the peer" depends on the side this modal is playing:
    // showing a code makes it the initiator, typing one makes it the responder.
    private function hydrateDeviceNames(
        PairingGateway $gateway,
        DeviceRegistryService $registry,
        int $userId,
        bool $asResponder = false,
    ): void {
        $names = $gateway->deviceNamesFor((int) $this->pairingTokenId, $userId);
        $fallback = Lang::get('sync::devices.peer_default_name');

        $this->selfDeviceName = $registry->localDeviceName($userId) ?? $fallback;
        $this->peerDeviceName = ($asResponder ? $names['initiator'] : $names['responder']) ?? $fallback;
    }

    // Records this side's safety-number confirmation. PairingTokenService::
    // confirm sets device_registry.confirmed_at ONLY once bothConfirmed(). If
    // the peer has already confirmed, this advances to success; otherwise
    // this side waits (the poll flips to success).
    public function confirmMatch(
        CurrentUser $currentUser,
        DeviceIdentityLoader $identityLoader,
        PairingTokenService $tokenService,
        Session $session,
        EncryptionMigrationService $migrationService,
        DatabaseManager $db,
        PairingGateway $gateway,
        LoggerInterface $logger,
        PairingRelayCourier $relayCourier,
        RelayConfig $relayConfig,
        PreSyncHistoryCapture $historyCapture,
    ): void {
        if ($this->pairingTokenId === '') {
            return;
        }

        $userId = $currentUser->user()->id;

        // Binds the confirming side to THIS device's real identity — the
        // service derives the side from this device id, never from client state.
        $identity = $identityLoader->load($userId, $session);
        if ($identity === null) {
            $this->flashMessage = Lang::get(self::IDENTITY_LOCKED_MESSAGE);

            return;
        }

        // The service is the single source of truth for the trust decision:
        // it returns the resulting state, so we never re-read the row and
        // re-derive bothConfirmed() here.
        $state = $tokenService->confirm((int) $this->pairingTokenId, $userId, $identity->deviceId);

        // Sends this device's own signed PAIR_CONFIRM to the peer over the
        // relay — safe regardless of $state, since the frame is only ever
        // consumable once the peer's own local side has confirmed too.
        // No-op when no relay is configured — never dead-ends that path.
        $this->sendConfirmOverRelay($db, $relayCourier, $relayConfig, $identity, $logger, $userId);

        if ($state === PairingState::Confirmed->value) {
            $this->awaitingPeer = false;
            $this->step = 'success';

            // Mandatory auto-activation — no decline path. A migration
            // failure never undoes the just-completed pairing; the
            // encryption row simply keeps rendering its mandatory/off state
            // until the next successful pass.
            try {
                $migrationService->migrate($currentUser->user(), $session);
            } catch (Throwable) {
                // Best-effort — see the mandatory auto-activation
                // comment above; this never undoes the pairing.
            }

            // Same pre-sync capture as the poll path: whichever side reaches
            // CONFIRMED first is the one that must have a populated log.
            $historyCapture->capture($userId);

            // Fans out EVERY desktop epoch to the just-confirmed device —
            // ONLY reachable here, inside the state === CONFIRMED branch
            // (trust-gate ordering). migrate() runs FIRST so a desktop that
            // had never enabled encryption has something to deliver.
            $this->fanOutToNewlyConfirmedDevice($db, $gateway, $logger, $userId, $session);

            $this->dispatch('pairing-confirmed');

            return;
        }

        $this->awaitingPeer = true;
    }

    // Fans out every keyring epoch to EVERY confirmed peer, asking the
    // permanent device_registry rather than this transient token: prune()
    // drops that row on the next issue(), so resolving the recipient from it
    // delivered nothing once the ceremony outlived its own token.
    private function fanOutToNewlyConfirmedDevice(
        DatabaseManager $db,
        PairingGateway $gateway,
        LoggerInterface $logger,
        int $userId,
        Session $session,
    ): void {
        $recipients = $db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 0)
            ->whereNotNull('confirmed_at')
            ->pluck('id');

        if ($recipients->isEmpty()) {
            $this->fanOutFailed = true;
            $logger->warning('GDK epoch fan-out found no confirmed peer to deliver to — the peer cannot decrypt anything until it is admitted.', [
                'user_id' => $userId,
            ]);

            return;
        }

        $this->fanOutFailed = false;

        foreach ($recipients as $deviceRegistryId) {
            if (! is_numeric($deviceRegistryId)) {
                continue;
            }

            try {
                $gateway->deliverAllEpochsToDevice($userId, (int) $deviceRegistryId, $session);
            } catch (Throwable $e) {
                $this->fanOutFailed = true;
                $logger->warning('GDK epoch fan-out to newly-confirmed device failed.', [
                    'user_id' => $userId,
                    'device_registry_id' => (int) $deviceRegistryId,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    public function onCodeExpired(
        CurrentUser $currentUser,
        PairingTokenService $tokenService,
    ): void {
        // Marks the abandoned/expired token so it is not left dangling as
        // pending — the next issue() prunes it; expiring it now keeps the
        // row's state honest the moment the countdown hits zero.
        if ($this->pairingTokenId !== '') {
            $tokenService->expire((int) $this->pairingTokenId, $currentUser->user()->id);
        }

        $this->flashMessage = Lang::get('sync::pairing.code_expired');
        $this->expiresInSeconds = 0;
    }

    public function regenerateCode(
        CurrentUser $currentUser,
        DeviceIdentityLoader $identityLoader,
        PairingTokenService $tokenService,
        QrPayloadBuilder $qrBuilder,
        WordCodeEncoder $wordEncoder,
        DatabaseManager $db,
        Session $session,
        RelayConfig $relayConfig,
        DeviceRegistryService $registry,
    ): void {
        $this->showMyCode($currentUser, $identityLoader, $tokenService, $qrBuilder, $wordEncoder, $db, $session, $relayConfig, $registry);
    }

    // Cancels an IN-FLIGHT pairing: expires the still-live token and resets
    // the flow. The flux:modal @close hook can fire this a second time after
    // open=false is set, but reset() has already cleared pairingTokenId, so
    // the expire branch below is skipped on the second, idempotent call.
    public function cancelPairing(
        CurrentUser $currentUser,
        PairingTokenService $tokenService,
        DatabaseManager $db,
    ): void {
        if ($this->pairingTokenId !== '') {
            $userId = $currentUser->user()->id;
            $tokenId = (int) $this->pairingTokenId;

            $row = $db->connection()->table('pairing_tokens')
                ->where('id', $tokenId)
                ->where('user_id', $userId)
                ->first(['state']);

            // Only an unfinished handshake gets expired. A confirmed token is
            // a completed pairing — leave its terminal state intact.
            if ($row !== null && in_array($row->state, [
                PairingState::Pending->value,
                PairingState::AwaitingConfirm->value,
            ], true)) {
                $tokenService->expire($tokenId, $userId);
            }
        }

        $this->resetAndClose();
    }

    public function closeModal(): void
    {
        $this->resetAndClose();
    }

    private function resetAndClose(): void
    {
        $this->reset();
        $this->open = false;
        $this->dispatch('pairing-closed');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('sync::livewire.pairing-flow-modal');
    }

    // Delivers this device's Ed25519-signed PAIR_CONFIRM frame to the PEER
    // side of the in-flight token. Best-effort: a RuntimeException from the
    // courier (no relay configured) is caught and logged (ids/counts only),
    // not surfaced as a flash error.
    private function sendConfirmOverRelay(
        DatabaseManager $db,
        PairingRelayCourier $relayCourier,
        RelayConfig $relayConfig,
        DeviceIdentityDto $identity,
        LoggerInterface $logger,
        int $userId,
    ): void {
        if (! $relayConfig->isConfigured()) {
            return;
        }

        // Scoped even though $pairingTokenId is #[Locked] and every writer is
        // user-scoped, so no reachable state makes this cross-user. A read of
        // a user-owned table that does not say whose it is reads as an
        // oversight to the next person, and its twin in Mobile carries it.
        $row = $db->connection()->table('pairing_tokens')
            ->where('id', (int) $this->pairingTokenId)
            ->where('user_id', $userId)
            ->first(['token_hash', 'initiator_device_id', 'responder_device_id']);

        if ($row === null) {
            return;
        }

        $tokenHash = is_string($row->token_hash) ? $row->token_hash : null;
        $peerDeviceId = $this->peerDeviceId($row);

        if ($tokenHash === null || $peerDeviceId === null) {
            return;
        }

        try {
            $relayCourier->sendConfirm($identity, $peerDeviceId, $tokenHash);
        } catch (Throwable $e) {
            $logger->warning('PairingFlowModal: cross-device PAIR_CONFIRM relay delivery failed.', [
                'pairing_token_id' => $this->pairingTokenId,
                'exception' => $e::class,
            ]);
        }
    }
}
