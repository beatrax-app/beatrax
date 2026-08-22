<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\Concerns\ReportsLocalPairingState;
use Modules\Sync\Internal\Pairing\PairingPeerLink;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenRowReader;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\LanDiscoveryReach;
use Modules\Sync\Public\Enums\PairingOfferLookup;
use stdClass;

// The one door into pairing for every screen on both clients, which is why it
// stays a single injectable rather than a family of them. The work behind it
// splits three ways — reaching the peer, reporting what is locally true, and
// the trust decisions, which are the only ones this body still spells out.
/**
 * @link ../../../../.docs/architecture/module-boundaries.md#a-public-facade-keeps-its-door-and-moves-its-work
 */
final class PairingGateway
{
    use ReportsLocalPairingState;

    public const string STATE_CONFIRMED = PairingState::Confirmed->value;

    // Re-exported so a polling caller can recognise the unhappy end without
    // reaching into Internal for the enum.
    public const string STATE_EXPIRED = PairingState::Expired->value;

    public function __construct(
        private readonly DeviceIdentityLoader $identityLoader,
        private readonly PairingTokenService $tokenService,
        private readonly WordCodeEncoder $wordEncoder,
        private readonly PairingTokenRowReader $rows,
        private readonly DeviceIdentityService $identityService,
        private readonly GdkRotationService $rotationService,
        private readonly PairingPeerLink $peerLink,
        private readonly SafetyNumberDeriver $safetyDeriver,
        private readonly PeerDiscovery $discovery,
    ) {}

    // The companion question to discoverInitiatorOnLan()'s NoPeerReached: could
    // this device have looked at all? An empty browse means "nobody answered"
    // only where the question reached the network, so a screen reading this
    // stops naming a platform to know what the silence meant.
    /**
     * @link ../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md
     */
    public function lanDiscoveryReach(): LanDiscoveryReach
    {
        return $this->discovery->reach();
    }

    // A word-code carries the token alone, so a fresh responder has no local row to
    // accept against; this asks the LAN for the identity half the code cannot carry.
    // On failure it returns WHY, because the two reasons need opposite advice.
    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null}|PairingOfferLookup
     */
    public function discoverInitiatorOnLan(string $wordCode): array|PairingOfferLookup
    {
        return $this->peerLink->discoverInitiatorOnLan($wordCode);
    }

    // No new trust decision: a relay learned from a QR is a delivery address,
    // and the human-verified safety words remain the sole anchor.
    public function configureRelayFromQr(?string $endpoint, ?string $authToken, ?string $pin = null): void
    {
        $this->peerLink->configureRelayFromQr($endpoint, $authToken, $pin);
    }

    /**
     * @throws \Throwable when no road home is open — the LAN peer was
     *                    unreachable, the relay unconfigured, refusing or not
     *                    answering, and the peer's holding space full; callers
     *                    must catch and offer a non-blocking retry.
     */
    public function sendResponderAccept(int $userId, string $tokenHash, string $desktopDeviceId, Session $session): void
    {
        $this->peerLink->sendResponderAccept($userId, $tokenHash, $desktopDeviceId, $session);
    }

    /**
     * @throws \Throwable see {@see self::sendResponderAccept()}.
     */
    public function sendConfirm(int $userId, int $tokenId, string $peerDeviceId, Session $session): void
    {
        $this->peerLink->sendConfirm($userId, $tokenId, $peerDeviceId, $session);
    }

    // Call at the TOP of a poll handler, before re-reading local pairing state.
    // Never throws out of the poll: the courier catches and logs each failure.
    // A null session takes the relay road only — see the @param.
    /**
     * @param  Session|null  $session  The LAN return leg has to prove with this
     *                                 device's own signing key that the waiting
     *                                 frames are addressed to it, and a locked
     *                                 or session-less caller holds no such key.
     */
    public function drainPairingFrames(int $userId, ?Session $session): void
    {
        $this->peerLink->drainPairingFrames($userId, $session);
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

    // The sole gate admitting a device to device_registry, and the only place
    // the two refusals below are distinguishable to a caller.
    /**
     * @param  string  $expectedSafetyDigest  Fingerprint of the six words the
     *                                        human compared, so a responder
     *                                        that rebinds between the reading
     *                                        and the tap cannot inherit it.
     * @return string|null The resulting pairing state, or null for either
     *                     refusal: this device owns neither side of the token,
     *                     or the keys behind those words are no longer the ones
     *                     the row binds. Both need a rendered message.
     */
    public function confirm(int $tokenId, int $userId, string $confirmingDeviceId, string $expectedSafetyDigest): ?string
    {
        return $this->tokenService->confirm($tokenId, $userId, $confirmingDeviceId, $expectedSafetyDigest);
    }

    // The one place the digest is taken, so a screen and the gate it feeds can
    // never drift into computing it two ways.
    /**
     * @param  list<string>  $words
     */
    public function safetyDigestOf(array $words): string
    {
        return $this->safetyDeriver->digestOfWords($words);
    }

    // Holding a device id at all is the proof this session is unlocked: the
    // loader behind it needs the app-lock KEK and answers null without one. So
    // this cannot fire on a locked app, which is the only gate the extension has
    // and the reason the screen it runs on must disclose it (see @link).
    /**
     * @link ../../../../.docs/features/sync/pairing-handshake.md#a-pairing-outlives-the-lock-that-interrupts-it
     *
     * @return bool whether a ceremony this device owns a side of was held open
     */
    public function holdCeremonyOpenAcrossLock(int $userId, Session $session): bool
    {
        $deviceId = $this->currentDeviceId($userId, $session);

        return $deviceId !== null && $this->tokenService->extendCeremonyAcrossLock($userId, $deviceId);
    }

    public function expire(int $tokenId, int $userId): void
    {
        $this->tokenService->expire($tokenId, $userId);
    }
}
