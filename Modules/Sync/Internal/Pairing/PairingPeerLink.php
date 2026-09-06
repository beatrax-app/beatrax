<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceNameDetector;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Enums\PairingFrameSend;
use Modules\Sync\Public\Enums\PairingOfferLookup;
use Modules\Sync\Public\Support\PeerAddress;

// Every road PairingGateway has to the other device, with the collaborators
// that carry them. Injected, not mixed in: as a trait on the gateway these
// would have been declared there and named nowhere in its own body, which
// reads to a reader and to a static analyser alike as fields nothing uses.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final readonly class PairingPeerLink
{
    public function __construct(
        private DeviceIdentityLoader $identityLoader,
        private PairingTokenRowReader $rows,
        private RelayConfig $relayConfig,
        private DeviceNameDetector $deviceNameDetector,
        private PairingFrameCourier $frameCourier,
        private LanPairingOfferFetcher $lanOfferFetcher,
        private LanPairingFramePuller $lanFramePuller,
        private ScannedPeerAddress $scannedAddress,
    ) {}

    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayPin: null, lanHost: string, lanPort: int}|PairingOfferLookup
     */
    public function discoverInitiatorOnLan(string $wordCode, ?PeerAddress $typed = null): array|PairingOfferLookup
    {
        return $this->lanOfferFetcher->fetchForWordCode($wordCode, $typed);
    }

    public function hasRelayRoad(): bool
    {
        return $this->relayConfig->isConfigured();
    }

    public function knowsWhereToReach(string $tokenHash, string $peerDeviceId): bool
    {
        return $this->scannedAddress->forTokenHash($tokenHash, $peerDeviceId) !== null;
    }

    public function configureRelayFromQr(?string $endpoint, ?string $pin): void
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
        // receive the pin that endpoint needs. Without it a self-signed relay
        // certificate cannot be verified at all, so the endpoint stays unusable.
        if ($pin !== null && $pin !== '') {
            $this->relayConfig->setPin($pin);
        }
    }

    // The responder's OWN keys always come from the LOCAL identity, never wire
    // content. Answers which ending this was rather than returning quietly: a
    // caller polling every three seconds has no other way to tell a frame that
    // went out from one a locked identity could never have signed.
    public function sendResponderAccept(int $userId, string $tokenHash, string $desktopDeviceId, Session $session): PairingFrameSend
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            return PairingFrameSend::NoUsableIdentity;
        }

        $this->frameCourier->sendResponderAccept(
            $identity->deviceId,
            $desktopDeviceId,
            $tokenHash,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
            // This device's own name, so the peer labels the row with what this
            // machine calls itself rather than a placeholder.
            $this->deviceNameDetector->detect(),
        );

        return PairingFrameSend::Sent;
    }

    // Reads token_hash from the local row so the caller never reconstructs trust
    // state itself. Its two refusals are named for the same reason the accept's
    // is: they are the ones no exception marks, and the screen above has to say
    // something true about a confirmation that never left.
    public function sendConfirm(int $userId, int $tokenId, string $peerDeviceId, Session $session): PairingFrameSend
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            return PairingFrameSend::NoUsableIdentity;
        }

        $tokenHash = $this->rows->tokenHash($tokenId, $userId);

        if ($tokenHash === null) {
            return PairingFrameSend::NoLocalCeremony;
        }

        $this->frameCourier->sendConfirm($identity, $peerDeviceId, $tokenHash);

        return PairingFrameSend::Sent;
    }

    public function drainPairingFrames(int $userId, ?Session $session): void
    {
        $this->frameCourier->drainAndApply($userId);

        // The other road home. Only one side of a pairing listens, so a device
        // that runs no server — every phone — is never pushed to and has to
        // ask. Without this the desktop's PAIR_CONFIRM never arrived on a LAN
        // with no relay, and the ceremony finished half-done.
        $identity = $session === null ? null : $this->identityLoader->load($userId, $session);

        if ($identity !== null) {
            $this->lanFramePuller->pullAndApply($userId, $identity);
        }
    }
}
