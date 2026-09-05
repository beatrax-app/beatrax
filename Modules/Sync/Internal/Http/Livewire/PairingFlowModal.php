<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
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
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\Concerns\ReadsPairingTokenRow;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\OpLog\PreSyncHistoryCapture;
use Modules\Sync\Internal\Pairing\PairingFrameCourier;
use Modules\Sync\Internal\Pairing\PairingLanAdvertisement;
use Modules\Sync\Internal\Pairing\PairingPeerErrands;
use Modules\Sync\Internal\Pairing\PairingRefusalCopy;
use Modules\Sync\Internal\Pairing\PairingRowGuards;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\PendingPairingCourier;
use Modules\Sync\Internal\Pairing\QrPayloadBuilder;
use Modules\Sync\Internal\Pairing\RelayBootstrap;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Dto\PairingPeerIdentity;
use Modules\Sync\Public\Enums\PairingOfferLookup;
use Modules\Sync\Public\Enums\PairingSide;
use Modules\Sync\Public\Enums\PairingWizardStep;
use Modules\Sync\Public\Events\SyncTransportCredentialsAvailable;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @phpstan-type TypedCodeInitiator array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayPin: null, lanHost?: string, lanPort?: int}
 */
final class PairingFlowModal extends Component
{
    use HoldsFlashMessage;
    use ReadsPairingTokenRow;

    public bool $open = false;

    // Stays a string on the wire: a public property is rehydrated straight from
    // the client payload with no enum coercion, so typing it would turn a
    // crafted step into a 500. currentStep() is the enum every reader uses.
    public string $step = PairingWizardStep::ChooseDirection->value;

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
    // column. A string on the wire because a public property is rehydrated
    // from the client payload uncoerced; the authoritative side is re-derived
    // server-side in PairingTokenService::confirm() from the caller's own id.
    #[Locked]
    public string $side = '';

    // #[Locked] — this is what the confirmation digest is taken from, so an
    // unlocked property would let the client decide what its own tap is bound
    // to. Every writer below is server-side.
    /**
     * @var list<string>
     */
    #[Locked]
    public array $safetyWords = [];

    // Shown beside the words so the user confirms WHICH two devices are
    // pairing, not just that six words match on two screens.
    public string $selfDeviceName = '';

    public string $peerDeviceName = '';

    public function mount(bool $open = false): void
    {
        $this->open = $open;
    }

    private function currentStep(): PairingWizardStep
    {
        return PairingWizardStep::tryFrom($this->step) ?? PairingWizardStep::ChooseDirection;
    }

    // Rendered unconditionally so the hosting <flux:modal wire:model="open">
    // sees a real false->true transition, which is the only thing Flux shows
    // the dialog on. The flow resets first so a reopened modal never resumes a
    // cancelled handshake; a still-live one is picked back up below.
    /**
     * @link ../../../../../.docs/features/sync/pairing-handshake.md#opening-the-pairing-screen-must-not-restart-the-listener
     */
    #[On('open-pairing-modal')]
    public function openModal(
        CurrentUser $currentUser,
        Dispatcher $events,
        PairingGateway $gateway,
        PairingFrameCourier $frameCourier,
        LoggerInterface $logger,
        Session $session,
        DeviceRegistryService $registry,
    ): void {
        $userId = $currentUser->user()->id;

        // Unconditional: skipping this whenever a handshake looked live let one
        // stale row suppress the only thing that re-credentialled the daemon.
        // The listener now restarts only when the identity would change, so
        // re-sending mid-ceremony costs nothing.
        $events->dispatch(new SyncTransportCredentialsAvailable($userId));

        $this->step = PairingWizardStep::ChooseDirection->value;
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

        $this->resumeInFlight($userId, $gateway, $frameCourier, $logger, $session, $registry);
    }

    // Picks up a handshake that is still live. This modal's poll is the only
    // thing draining the relay, so a desktop that confirmed first and closed it
    // left the phone's PAIR_CONFIRM undelivered, and reopening started over.
    private function resumeInFlight(
        int $userId,
        PairingGateway $gateway,
        PairingFrameCourier $frameCourier,
        LoggerInterface $logger,
        Session $session,
        DeviceRegistryService $registry,
    ): void {
        try {
            $frameCourier->drainAndApply($userId);
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
        $this->side = $side->value;
        $this->awaitingPeer = $inFlight['state'] !== PairingState::Confirmed->value
            && ($side === PairingSide::Initiator ? $inFlight['initiator_confirmed'] : $inFlight['responder_confirmed']);

        // A confirmed handshake is finished, and re-presenting the trust gate
        // asked for a safety-number confirmation that confirm() then refuses
        // as already given — leaving the modal with no way forward and no way
        // to start again.
        $this->step = ($inFlight['state'] === PairingState::Confirmed->value
            ? PairingWizardStep::Success
            : PairingWizardStep::Confirm)->value;

        $this->hydrateDeviceNames($gateway, $registry, $userId, $side);
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
        PairingRefusalCopy $refusalCopy,
        PairingLanAdvertisement $lanAdvertisement,
    ): void {
        $userId = $currentUser->user()->id;

        $identity = $identityLoader->load($userId, $session);
        if ($identity === null) {
            $this->flashMessage = $refusalCopy->identityUnavailable($userId, $session);

            return;
        }

        $token = $tokenService->issue(
            $userId,
            $identity->deviceId,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
        );

        // Carries this device's relay endpoint and pinned key so a fresh phone
        // can configure its transport before the confirm handshake needs one.
        // No credential travels with them: the scanner mints its own drain
        // token, and a relay-wide one here reached every peer that ever paired.
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
                $relayConfig->pin(),
            ),
            // The road a phone can take without browsing for it. iOS grants no
            // multicast entitlement, so a scanned code that names no address
            // and no relay leaves the responder nowhere to send its accept.
            $lanAdvertisement->forQr(),
        );
        $this->wordCode = $this->offersATypedCode() ? $wordEncoder->encode($token) : '';
        $this->pairingTokenId = (string) $this->tokenRowId($db, $userId, $token);
        $this->side = PairingSide::Initiator->value;
        $this->expiresInSeconds = 600;
        $this->flashMessage = '';
        $this->step = PairingWizardStep::ShowCode->value;
    }

    // Whether a code shown here can be TYPED into the other device. Recovering
    // one means asking the LAN for the issuer's pairing offer, and only a
    // device running the sync listener answers: no phone runs one, so a word
    // code minted there names a row no peer can look up (see @link).
    /**
     * @link ../../../../../.docs/features/sync/pairing-handshake.md#a-phone-can-only-be-scanned
     */
    private function offersATypedCode(): bool
    {
        return ! UserDataPathService::isMobileRuntime();
    }

    // On a phone this hands off to the camera-first pairing screen instead of
    // opening a text field: that surface has a scanner, and this modal has
    // never had one, so the offer to scan was only ever true over there.
    public function enterACode(UrlGenerator $urls): void
    {
        if (UserDataPathService::isMobileRuntime()) {
            $this->redirect($urls->route('mobile.pair'), navigate: false);

            return;
        }

        $this->step = PairingWizardStep::EnterCode->value;
        $this->wordCode = '';
        $this->flashMessage = '';
        $this->side = PairingSide::Responder->value;
    }

    // Recovers the initiator's identity from the LAN, seeds the local row that
    // identity is missing from, and accepts the peer's token onto it. On
    // success advances to confirm and derives the shared safety-number; on
    // failure stays on enter_code saying which of the four endings happened.
    public function submitCode(
        CurrentUser $currentUser,
        DeviceIdentityLoader $identityLoader,
        PairingTokenService $tokenService,
        Session $session,
        PairingGateway $gateway,
        DeviceRegistryService $registry,
        PairingRefusalCopy $refusalCopy,
        PairingPeerErrands $errands,
    ): void {
        $userId = $currentUser->user()->id;

        $identity = $identityLoader->load($userId, $session);
        if ($identity === null) {
            $this->flashMessage = $refusalCopy->identityUnavailable($userId, $session);

            return;
        }

        $initiator = $this->adoptTypedInitiator($gateway, $refusalCopy, $userId);

        if ($initiator === null) {
            return;
        }

        $accepted = $tokenService->accept(
            $initiator['token'],
            $userId,
            $identity->deviceId,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
        );

        if ($accepted === false || ! $accepted instanceof \stdClass) {
            // The offer above came back from the device that minted this code,
            // which is that device vouching for it. Calling it invalid or
            // expired here contradicted the step that produced the identity,
            // and sent the reader for a replacement they did not need.
            $this->flashMessage = $refusalCopy->acceptRefusal(
                $gateway->classifyAcceptRefusal($initiator['token'], $userId, issuerServedItsOffer: true),
            );

            return;
        }

        $this->pairingTokenId = (string) (is_numeric($accepted->id) ? (int) $accepted->id : 0);
        $this->side = PairingSide::Responder->value;
        $this->safetyWords = $gateway->safetyWordsFor((int) $this->pairingTokenId, $userId);
        $this->hydrateDeviceNames($gateway, $registry, $userId, PairingSide::Responder);
        $this->flashMessage = '';
        $this->step = PairingWizardStep::Confirm->value;

        $errands->announceResponderAccept($userId, hash('sha256', $initiator['token']), $initiator['deviceId'], $session);
    }

    // A typed code carries the token and nothing else, so the row it names has
    // only ever existed in the database of the device that issued it. Asking
    // that device for its pairing offer is what turns the token into an identity
    // a local row can be seeded from; without it accept() binds nothing.
    /**
     * @link ../../../../../.docs/features/sync/pairing-handshake.md#both-clients-have-to-ask
     *
     * @return TypedCodeInitiator|null Null when the code named no identity that
     *                                 could be read, the reason already flashed.
     */
    private function adoptTypedInitiator(PairingGateway $gateway, PairingRefusalCopy $refusalCopy, int $userId): ?array
    {
        $discovered = $gateway->discoverInitiatorOnLan($this->wordCode);

        if ($discovered instanceof PairingOfferLookup) {
            $this->flashMessage = $refusalCopy->offerLookupRefusal($discovered);

            return null;
        }

        // No trust decision: the seeded row is Pending and still faces the
        // whole ceremony, and the safety-number comparison remains the only
        // gate anything is admitted through.
        $gateway->seedResponderToken(
            $discovered['token'],
            new PairingPeerIdentity(
                $discovered['deviceId'],
                $discovered['ed25519PubHex'],
                $discovered['x25519PubHex'],
                // The offered name, so the peer is not admitted under the
                // "Paired device" placeholder the registry falls back to.
                $discovered['deviceName'],
                $discovered['lanHost'],
                $discovered['lanPort'],
            ),
            $userId,
        );

        return $discovered;
    }

    // Advances show_code -> confirm when the responder has accepted, and any
    // step -> success when both sides have confirmed. The side that confirms
    // FIRST learns of the completed both-confirm HERE (not from its own
    // confirmMatch() call), so the same auto-trigger must also run here.
    public function checkPairingState(
        CurrentUser $currentUser,
        DatabaseManager $db,
        DeviceIdentityLoader $identityLoader,
        Session $session,
        EncryptionMigrationService $migrationService,
        PairingGateway $gateway,
        LoggerInterface $logger,
        PendingPairingCourier $courier,
        PreSyncHistoryCapture $historyCapture,
        DeviceRegistryService $registry,
        PairingPeerErrands $errands,
    ): void {
        if ($this->pairingTokenId === '') {
            return;
        }

        $userId = $currentUser->user()->id;

        // The poll no longer moves frames. Collecting and re-emitting are one
        // mechanism now, running from the daemon and the request tail too — a
        // second copy here would be a second redelivery policy, free to
        // disagree. What is left below is this screen's job: the wizard.
        try {
            $courier->tick($userId, $identityLoader->load($userId, $session));
        } catch (Throwable $e) {
            $logger->warning('PairingFlowModal: pending-pairing courier tick failed during poll.', [
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

        if ($row->state === PairingState::AwaitingConfirm->value && $this->currentStep() === PairingWizardStep::ShowCode) {
            $this->safetyWords = $gateway->safetyWordsFor((int) $this->pairingTokenId, $userId);
            $this->hydrateDeviceNames($gateway, $registry, $userId, PairingSide::Initiator);
            $this->step = PairingWizardStep::Confirm->value;

            return;
        }

        if ($row->state === PairingState::Confirmed->value && $this->currentStep() !== PairingWizardStep::Success) {
            $this->enterSuccessStep($currentUser, $session, $migrationService, $historyCapture, $errands);
        }
    }

    // Which name is "the peer" depends on the side this modal is playing:
    // showing a code makes it the initiator, typing one makes it the responder.
    private function hydrateDeviceNames(
        PairingGateway $gateway,
        DeviceRegistryService $registry,
        int $userId,
        PairingSide $side,
    ): void {
        $names = $gateway->deviceNamesFor((int) $this->pairingTokenId, $userId);
        $fallback = Lang::get('sync::devices.peer_default_name');

        $this->selfDeviceName = $registry->localDeviceName($userId) ?? $fallback;
        $this->peerDeviceName = match ($side->peer()) {
            PairingSide::Initiator => $names['initiator'],
            PairingSide::Responder => $names['responder'],
        } ?? $fallback;
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
        PairingGateway $gateway,
        PairingRefusalCopy $refusalCopy,
        PairingPeerErrands $errands,
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
            $this->flashMessage = $refusalCopy->identityUnavailable($userId, $session);

            return;
        }

        // The service is the single source of truth for the trust decision, and
        // the tap is bound to the words on screen rather than to whatever the
        // row says now.
        $state = $tokenService->confirm(
            (int) $this->pairingTokenId,
            $userId,
            $identity->deviceId,
            $gateway->safetyDigestOf($this->safetyWords),
        );

        // Refused: the keys behind those words are not the keys on the row any
        // more. Say so and show the new ones — silence here reads as "waiting
        // for the other device" and leaves a responder that rebinds able to
        // stall the ceremony indefinitely without ever being seen.
        if ($state === null) {
            $this->awaitingPeer = false;
            $this->safetyWords = $gateway->safetyWordsFor((int) $this->pairingTokenId, $userId);
            $this->flashMessage = Lang::get('sync::pairing.safety_number_changed');

            return;
        }

        // Safe regardless of $state: the frame is only consumable once the
        // peer's own local side has confirmed too.
        $errands->sendConfirm($identity, (int) $this->pairingTokenId, $userId, $this->side);

        if ($state === PairingState::Confirmed->value) {
            $this->awaitingPeer = false;
            $this->enterSuccessStep($currentUser, $session, $migrationService, $historyCapture, $errands);
        } else {
            $this->awaitingPeer = true;
        }
    }

    // The tail of a completed ceremony, run by whichever side learns of the
    // both-confirm first — its own tap, or the poll seeing the peer's. Both
    // roads reach it, so a device that finished second is not left holding an
    // uncaptured log and an undelivered epoch.
    private function enterSuccessStep(
        CurrentUser $currentUser,
        Session $session,
        EncryptionMigrationService $migrationService,
        PreSyncHistoryCapture $historyCapture,
        PairingPeerErrands $errands,
    ): void {
        $userId = $currentUser->user()->id;

        $this->step = PairingWizardStep::Success->value;

        // Mandatory auto-activation — no decline path. A migration failure
        // never undoes the just-completed pairing; the encryption row simply
        // keeps rendering its mandatory/off state until the next successful
        // pass.
        try {
            $migrationService->migrate($currentUser->user(), $session);
        } catch (Throwable) {
            // Best-effort by the rule above — nothing in this tail may undo a
            // pairing that has already completed.
        }

        // Everything on this device that predates sync, captured before the
        // new peer asks for it. A device that enabled sync before capture
        // worked has an empty log and would hand over nothing.
        $historyCapture->capture($userId);

        // Fans out EVERY epoch to the just-confirmed device. migrate() runs
        // FIRST so a device that had never enabled encryption has something to
        // deliver, and this whole tail is reachable only from a CONFIRMED row.
        $this->fanOutFailed = ! $errands->fanOutEpochsToConfirmedPeers($userId, $session);

        $this->dispatch('pairing-confirmed');
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
        PairingRefusalCopy $refusalCopy,
        PairingLanAdvertisement $lanAdvertisement,
    ): void {
        $this->showMyCode($currentUser, $identityLoader, $tokenService, $qrBuilder, $wordEncoder, $db, $session, $relayConfig, $registry, $refusalCopy, $lanAdvertisement);
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

            $this->resetAndClose();

            return;
        }

        // Nothing was resumed, so a `pending` row is blocking this user with no
        // id for the branch above to name — inFlight() never reports that state.
        // Cancel is the reader's only way out of it.
        $tokenService->expireUnfinished($currentUser->user()->id);

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
        // Under its own name, not $step: view data cannot shadow a public
        // property, and the view needs the resolved enum rather than the raw
        // string the client last put on the wire.
        return $views->make('sync::livewire.pairing-flow-modal', [
            'wizardStep' => $this->currentStep(),
            'offersATypedCode' => $this->offersATypedCode(),
        ]);
    }
}
