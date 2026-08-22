<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceNameDetector;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Enums\PairingOfferLookup;

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
    ) {}

    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null}|PairingOfferLookup
     */
    public function discoverInitiatorOnLan(string $wordCode): array|PairingOfferLookup
    {
        return $this->lanOfferFetcher->fetchForWordCode($wordCode);
    }

    public function configureRelayFromQr(?string $endpoint, ?string $authToken, ?string $pin): void
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

        // Without the pin a self-signed relay certificate cannot be verified at
        // all, so an endpoint arriving without one stays unusable.
        if ($pin !== null && $pin !== '') {
            $this->relayConfig->setPin($pin);
        }
    }

    // The responder's OWN keys always come from the LOCAL identity, never wire
    // content. Silent no-op when that identity is locked or unavailable.
    public function sendResponderAccept(int $userId, string $tokenHash, string $desktopDeviceId, Session $session): void
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            return;
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
    }

    // Reads token_hash from the local row so the caller never reconstructs trust
    // state itself. No-op when the identity is locked or $tokenId matches no row.
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

        $this->frameCourier->sendConfirm($identity, $peerDeviceId, $tokenHash);
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
