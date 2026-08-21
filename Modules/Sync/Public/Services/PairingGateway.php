<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Identity\DeviceNameDetector;
use Modules\Sync\Internal\Pairing\LanPairingFramePuller;
use Modules\Sync\Internal\Pairing\LanPairingOfferFetcher;
use Modules\Sync\Internal\Pairing\PairingFrameCourier;
use Modules\Sync\Internal\Pairing\PairingRowGuards;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenRowReader;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Enums\PairingOfferLookup;
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
        private readonly PairingTokenRowReader $rows,
        private readonly DeviceIdentityService $identityService,
        private readonly GdkRotationService $rotationService,
        private readonly RelayConfig $relayConfig,
        private readonly DeviceNameDetector $deviceNameDetector,
        private readonly PairingFrameCourier $relayCourier,
        private readonly LanPairingOfferFetcher $lanOfferFetcher,
        private readonly LanPairingFramePuller $lanFramePuller,
        private readonly DeviceRegistryService $devices,
    ) {}

    // A word-code carries the token alone, so a fresh responder has no local row to
    // accept against; this asks the LAN for the identity half the code cannot carry.
    // On failure it returns WHY, because the two reasons need opposite advice.
    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null}|PairingOfferLookup
     */
    public function discoverInitiatorOnLan(string $wordCode): array|PairingOfferLookup
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

        $tokenHash = $this->rows->tokenHash($tokenId, $userId);

        if ($tokenHash === null) {
            return;
        }

        $this->relayCourier->sendConfirm($identity, $peerDeviceId, $tokenHash);
    }

    // Call at the TOP of a poll handler, before re-reading local pairing state.
    // Never throws out of the poll: the courier catches and logs each failure.
    public function drainPairingFrames(int $userId): void
    {
        $this->relayCourier->drainAndApply($userId);

        // The other road home. Only one side of a pairing listens, so a device
        // that runs no server — every phone — is never pushed to and has to
        // ask. Without this the desktop's PAIR_CONFIRM never arrived on a LAN
        // with no relay, and the ceremony finished half-done.
        $ownDeviceId = $this->devices->localDeviceId($userId);

        if ($ownDeviceId !== null) {
            $this->lanFramePuller->pullAndApply($userId, $ownDeviceId);
        }
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
        return $this->rows->deviceNames($pairingTokenId, $userId);
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
            'safetyWords' => $this->rows->safetyWords($pairingTokenId, $userId),
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
        return $this->rows->inFlight($userId);
    }

    public function tokenState(int $tokenId, int $userId): ?string
    {
        return $this->rows->state($tokenId, $userId);
    }
}
