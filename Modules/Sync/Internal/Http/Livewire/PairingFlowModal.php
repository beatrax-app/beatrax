<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Pairing\InvalidPublicKeyException;
use Modules\Sync\Internal\Pairing\PairingRelayCourier;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\QrPayloadBuilder;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\PairingGateway;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class PairingFlowModal extends Component
{
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

    public string $flashMessage = '';

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

    public function mount(bool $open = false): void
    {
        $this->open = $open;
    }

    // The component is rendered unconditionally (always in the DOM) so the
    // hosting <flux:modal wire:model="open"> sees a real false->true
    // transition — Flux only shows the dialog on that transition. Reset the
    // flow so a reopened modal never resumes a stale/cancelled handshake.
    #[On('open-pairing-modal')]
    public function openModal(): void
    {
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
            $relayConfig->endpointUrl(),
            $relayConfig->authToken(),
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

            // Fans out EVERY desktop epoch to the just-confirmed device —
            // ONLY reachable inside the state === CONFIRMED branch. migrate()
            // runs FIRST so a desktop that had never enabled encryption has
            // something to deliver.
            $this->fanOutToNewlyConfirmedDevice($db, $gateway, $logger, $userId, $session);

            $this->dispatch('pairing-confirmed');
        }
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
        $this->sendConfirmOverRelay($db, $relayCourier, $relayConfig, $identity, $logger);

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

    // Resolves the just-confirmed RESPONDER device's device_registry.id from
    // this in-flight token, then fans out every keyring epoch to it.
    // Best-effort — a fan-out failure surfaces $fanOutFailed but NEVER undoes
    // the pairing; logged with device/epoch ids only, never key material.
    private function fanOutToNewlyConfirmedDevice(
        DatabaseManager $db,
        PairingGateway $gateway,
        LoggerInterface $logger,
        int $userId,
        Session $session,
    ): void {
        $tokenRow = $db->connection()->table('pairing_tokens')
            ->where('id', (int) $this->pairingTokenId)
            ->where('user_id', $userId)
            ->first(['responder_device_id']);

        $responderDeviceId = $tokenRow !== null && is_string($tokenRow->responder_device_id)
            ? $tokenRow->responder_device_id
            : null;

        if ($responderDeviceId === null) {
            return;
        }

        $deviceRegistryId = $db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $responderDeviceId)
            ->where('is_self', 0)
            ->value('id');

        if (! is_numeric($deviceRegistryId)) {
            return;
        }

        $this->fanOutFailed = false;

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
    ): void {
        $this->showMyCode($currentUser, $identityLoader, $tokenService, $qrBuilder, $wordEncoder, $db, $session, $relayConfig);
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
    ): void {
        if (! $relayConfig->isConfigured()) {
            return;
        }

        $row = $db->connection()->table('pairing_tokens')
            ->where('id', (int) $this->pairingTokenId)
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

    // Selects the PEER side's device id from the in-flight token row: an
    // initiator confirms toward the responder column and a responder toward
    // the initiator column. Null until that peer column is a bound string.
    private function peerDeviceId(\stdClass $row): ?string
    {
        $peerDeviceId = match ($this->side) {
            'initiator' => $row->responder_device_id,
            default => $row->initiator_device_id,
        };

        return is_string($peerDeviceId) ? $peerDeviceId : null;
    }

    private function tokenRowId(DatabaseManager $db, int $userId, string $token): int
    {
        $row = $db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', hash('sha256', $token))
            ->first(['id']);

        return $row !== null && is_numeric($row->id) ? (int) $row->id : 0;
    }

    /**
     * @return list<string>
     */
    private function deriveSafetyWords(
        DatabaseManager $db,
        SafetyNumberDeriver $safetyDeriver,
        int $userId,
    ): array {
        $keyPair = $this->pairingKeyPair($db, $userId);

        if ($keyPair === null) {
            return [];
        }

        // The stored hex is validated at the accept/issue trust boundary,
        // but guard the decode anyway: a malformed key yields the generic
        // invalid-code flash instead of an uncaught SodiumException 500.
        try {
            return $safetyDeriver->deriveWords($keyPair[0], $keyPair[1]);
        } catch (InvalidPublicKeyException) {
            return [];
        }
    }

    // Reads both parties' Ed25519 public-key hex from the in-flight token,
    // returning null when the row or either bound key is absent — collapsing
    // two failure guards so deriveSafetyWords keeps a single fallible decode.
    /**
     * @return array{0: string, 1: string}|null
     */
    private function pairingKeyPair(DatabaseManager $db, int $userId): ?array
    {
        $row = $db->connection()->table('pairing_tokens')
            ->where('id', (int) $this->pairingTokenId)
            ->where('user_id', $userId)
            ->first(['initiator_ed25519_pub_hex', 'responder_ed25519_pub_hex']);

        if ($row === null) {
            return null;
        }

        $initiatorEd = is_string($row->initiator_ed25519_pub_hex) ? $row->initiator_ed25519_pub_hex : null;
        $responderEd = is_string($row->responder_ed25519_pub_hex) ? $row->responder_ed25519_pub_hex : null;

        if ($initiatorEd === null || $responderEd === null) {
            return null;
        }

        return [$initiatorEd, $responderEd];
    }
}
