<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Identity\DeviceNameDetector;
use Modules\Sync\Internal\Pairing\InvalidPublicKeyException;
use Modules\Sync\Internal\Pairing\LanPairingOfferFetcher;
use Modules\Sync\Internal\Pairing\PairingRelayCourier;
use Modules\Sync\Internal\Pairing\PairingRowGuards;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use stdClass;

final class PairingGateway
{
    public const string STATE_AWAITING_CONFIRM = PairingState::AwaitingConfirm->value;

    public const string STATE_CONFIRMED = PairingState::Confirmed->value;

    // So a polling caller can recognise the unhappy end without reaching into Internal.
    public const string STATE_EXPIRED = PairingState::Expired->value;

    public function __construct(
        private readonly DeviceIdentityLoader $identityLoader,
        private readonly PairingTokenService $tokenService,
        private readonly WordCodeEncoder $wordEncoder,
        private readonly SafetyNumberDeriver $safetyDeriver,
        private readonly DatabaseManager $db,
        private readonly DeviceIdentityService $identityService,
        private readonly GdkRotationService $rotationService,
        private readonly RelayConfig $relayConfig,
        private readonly DeviceNameDetector $deviceNameDetector,
        private readonly PairingRelayCourier $relayCourier,
        private readonly Clock $clock,
        private readonly LanPairingOfferFetcher $lanOfferFetcher,
    ) {}

    // A word-code carries the token alone, so a fresh responder has no local row to
    // accept against; this asks the LAN for the identity half the code cannot carry.
    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null}|null
     */
    public function discoverInitiatorOnLan(string $wordCode): ?array
    {
        return $this->lanOfferFetcher->fetchForWordCode($wordCode);
    }

    // No new trust decision — the human-verified safety words remain the sole anchor.
    public function configureRelayFromQr(?string $endpoint, ?string $authToken, ?string $pin = null): void
    {
        if ($endpoint === null || $endpoint === '') {
            return;
        }

        // Storing an endpoint the transport would later refuse is how a phone ended
        // up holding a relay it could never send to.
        if (! $this->relayConfig->wouldAcceptEndpoint($endpoint)) {
            return;
        }

        $existing = $this->relayConfig->endpointUrl();

        // A camera-read endpoint may point this device at a LAN relay, but never
        // redirects an operator's self-hosted one.
        if ($existing !== null && $existing !== $endpoint && ! $this->relayConfig->isLanEndpoint($existing)) {
            return;
        }

        if ($existing !== $endpoint) {
            $this->relayConfig->setEndpointUrl($endpoint);
        }

        // Refreshed even when the endpoint is unchanged: bailing out on "already
        // configured" left a phone that scanned a first, broken QR unable to ever
        // receive the token and pin that endpoint needs.
        if ($authToken !== null && $authToken !== '') {
            $this->relayConfig->setAuthToken($authToken);
        }

        // Without the pin a self-signed relay cannot be verified at all.
        if ($pin !== null && $pin !== '') {
            $this->relayConfig->setPin($pin);
        }
    }

    // The responder's OWN keys always come from the LOCAL identity, never wire
    // content. Silent no-op when that identity is locked or unavailable.
    /**
     * @throws \RuntimeException when the relay is unconfigured or delivery fails;
     *                           callers must catch and offer a non-blocking retry.
     */
    public function sendResponderAccept(int $userId, string $tokenHash, string $desktopDeviceId, Session $session): void
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            return;
        }

        $this->relayCourier->sendResponderAccept(
            $identity->deviceId,
            $desktopDeviceId,
            $tokenHash,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
            // This device's own name, so the peer can label the row.
            $this->deviceNameDetector->detect(),
        );
    }

    // Reads token_hash from the local row so the caller never reconstructs trust
    // state itself. No-op when the identity is locked or $tokenId matches no row.
    /**
     * @throws \RuntimeException see {@see self::sendResponderAccept()}.
     */
    public function sendConfirm(int $userId, int $tokenId, string $peerDeviceId, Session $session): void
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            return;
        }

        $tokenHash = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->value('token_hash');

        if (! is_string($tokenHash) || $tokenHash === '') {
            return;
        }

        $this->relayCourier->sendConfirm($identity, $peerDeviceId, $tokenHash);
    }

    // Call at the TOP of a poll handler, before re-reading local pairing state.
    // Never throws out of the poll: the courier catches and logs each failure.
    public function drainPairingFrames(int $userId): void
    {
        $this->relayCourier->drainAndApply($userId);
    }

    // False both when the identity was never minted and when the app-lock holds the KEK.
    public function hasUsableIdentity(int $userId, Session $session): bool
    {
        return $this->identityLoader->load($userId, $session) !== null;
    }

    // Callers that mint must gate on this, not on a null from acceptToken() or the
    // loader — that null also means "locked".
    public function hasIdentityFile(int $userId): bool
    {
        return $this->identityLoader->exists($userId);
    }

    // Identity only: self-minting an epoch here collides with the desktop's epoch 1
    // and strands every desktop epoch-1 entry in quarantine. Must never be extended
    // to touch the GDK keyring or EncryptionMigrationService.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     */
    public function enableSyncIdentityWithoutEpoch(int $userId, Session $session): void
    {
        $this->identityService->generateAndPersist($userId, $session);
    }

    // Reachable only from the CONFIRMED both-confirm transition — never
    // speculatively, never on a pending/awaiting/expired/rejected token.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     */
    public function deliverAllEpochsToDevice(int $userId, int $newDeviceRegistryId, Session $session): void
    {
        $this->rotationService->fanOutAllEpochsToDevice($userId, $newDeviceRegistryId, $session);
    }

    // Either name may be null on a row written before that column existed.
    /**
     * @return array{initiator: ?string, responder: ?string}
     */
    public function deviceNamesFor(int $pairingTokenId, int $userId): array
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', $pairingTokenId)
            ->where('user_id', $userId)
            ->first(['initiator_name', 'responder_name']);

        return [
            'initiator' => $row !== null && is_string($row->initiator_name) ? $row->initiator_name : null,
            'responder' => $row !== null && is_string($row->responder_name) ? $row->responder_name : null,
        ];
    }

    // Closes the gap where acceptToken() finds no local row on a fresh device
    // database. The seeded row still requires the full accept + both-confirm ceremony.
    public function seedResponderToken(
        string $tokenHex,
        string $initiatorDeviceId,
        string $initiatorEd25519Hex,
        string $initiatorX25519Hex,
        int $userId,
        ?string $initiatorName = null,
    ): void {
        $this->tokenService->seedFromInitiator($userId, $initiatorDeviceId, $initiatorEd25519Hex, $initiatorX25519Hex, $tokenHex, $initiatorName);
    }

    // Raw hex — the QR path. The word-code path base32-encodes this same hex
    // purely so a human can type it.
    /**
     * @return array{pairingTokenId: string, safetyWords: list<string>}|null
     */
    public function acceptToken(string $tokenHex, int $userId, Session $session): ?array
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            return null;
        }

        $accepted = $this->tokenService->accept(
            $tokenHex,
            $userId,
            $identity->deviceId,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
        );

        if ($accepted === false || ! $accepted instanceof stdClass) {
            return null;
        }

        $pairingTokenId = (string) (is_numeric($accepted->id) ? (int) $accepted->id : 0);

        return [
            'pairingTokenId' => $pairingTokenId,
            'safetyWords' => $this->deriveSafetyWords($pairingTokenId, $userId),
        ];
    }

    /**
     * @return array{pairingTokenId: string, safetyWords: list<string>}|null
     */
    public function acceptWordCode(string $wordCode, int $userId, Session $session): ?array
    {
        try {
            $tokenHex = $this->wordEncoder->decode($wordCode);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $this->acceptToken($tokenHex, $userId, $session);
    }

    // The sole gate admitting a device to device_registry. Null when the caller
    // owns neither side of the token.
    public function confirm(int $tokenId, int $userId, string $confirmingDeviceId): ?string
    {
        return $this->tokenService->confirm($tokenId, $userId, $confirmingDeviceId);
    }

    public function expire(int $tokenId, int $userId): void
    {
        $this->tokenService->expire($tokenId, $userId);
    }

    // The confirming side must be derived from this device's own identity, never
    // from a client-supplied value. Null when locked or sync was never enabled.
    public function currentDeviceId(int $userId, Session $session): ?string
    {
        return $this->identityLoader->load($userId, $session)?->deviceId;
    }

    // Null means neither side: the row belongs to two other devices, or this one is
    // locked and cannot say who it is. Exposed so no caller derives the side twice.
    public function sideOwnedBySelf(
        ?string $initiatorDeviceId,
        ?string $responderDeviceId,
        int $userId,
        Session $session,
    ): ?string {
        return PairingRowGuards::sideOwnedByIds(
            $initiatorDeviceId,
            $responderDeviceId,
            $this->currentDeviceId($userId, $session) ?? '',
        );
    }

    // Screens hold their step in component state, which a reload wipes while the row
    // carries on, so callers resume from here rather than restarting the ceremony.
    /**
     * @return array{id: int, state: string, safety_words: list<string>, token_hash: string, peer_device_id: string, initiator_device_id: string|null, responder_device_id: string|null}|null
     */
    public function inFlightFor(int $userId): ?array
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->whereIn('state', [PairingState::AwaitingConfirm->value, PairingState::Confirmed->value])
            ->where('expires_at', '>', $this->clock->now()->toIso8601String())
            ->orderByDesc('id')
            ->first(['id', 'state', 'token_hash', 'initiator_device_id', 'responder_device_id']);

        if ($row === null || ! is_numeric($row->id) || ! is_string($row->state)) {
            return null;
        }

        // Derived, not read: reading a `safety_number_words` column here 500'd every
        // GET of the pairing screen — that column is on device_registry, and
        // pairing_tokens has never had one.
        $words = $this->deriveSafetyWords((string) $row->id, $userId);

        // token_hash and the device ids ride along so a resumed screen can re-emit its
        // responder accept; without them the one retry that heals a lost frame is
        // disarmed exactly when a frame is most likely to have been lost.
        return [
            'id' => (int) $row->id,
            'state' => $row->state,
            'safety_words' => $words,
            'token_hash' => is_string($row->token_hash) ? $row->token_hash : '',
            'peer_device_id' => is_string($row->initiator_device_id) ? $row->initiator_device_id : '',
            // peer_device_id stays the initiator's; the phone is always the responder.
            'initiator_device_id' => is_string($row->initiator_device_id) ? $row->initiator_device_id : null,
            'responder_device_id' => is_string($row->responder_device_id) ? $row->responder_device_id : null,
        ];
    }

    public function tokenState(int $tokenId, int $userId): ?string
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->first(['state']);

        return $row !== null && is_string($row->state) ? $row->state : null;
    }

    /**
     * @return list<string>
     */
    private function deriveSafetyWords(string $pairingTokenId, int $userId): array
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', (int) $pairingTokenId)
            ->where('user_id', $userId)
            ->first(['initiator_ed25519_pub_hex', 'responder_ed25519_pub_hex']);

        $initiatorEd = $row !== null && is_string($row->initiator_ed25519_pub_hex) ? $row->initiator_ed25519_pub_hex : null;
        $responderEd = $row !== null && is_string($row->responder_ed25519_pub_hex) ? $row->responder_ed25519_pub_hex : null;

        if ($initiatorEd === null || $responderEd === null) {
            return [];
        }

        try {
            return $this->safetyDeriver->deriveWords($initiatorEd, $responderEd);
        } catch (InvalidPublicKeyException) {
            return [];
        }
    }
}
