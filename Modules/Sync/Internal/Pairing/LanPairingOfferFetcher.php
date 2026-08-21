<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\Discovery\MulticastMdnsQuery;
use Modules\Sync\Internal\Transport\PairingOfferRequestHandler;
use Throwable;

// Recovers the initiator's public identity for a token the user typed, by
// asking every device advertising the sync service on this network for its
// pairing offer. Discovery authenticates nothing and neither does this: what
// comes back is a candidate the safety-number comparison still has to prove.
final readonly class LanPairingOfferFetcher
{
    // Long enough for a desktop on the same subnet to answer, short enough
    // that a phone with nothing to find says so rather than hanging.
    private const float BROWSE_TIMEOUT_SECONDS = 2.0;

    private const int CONNECT_TIMEOUT_SECONDS = 1;

    private const int REQUEST_TIMEOUT_SECONDS = 2;

    // Bounds the worst case: a hostile responder can answer a browse many
    // times over, and each answer would otherwise cost another request.
    private const int MAX_PEERS_TRIED = 8;

    private const int MAX_DEVICE_ID_BYTES = 128;

    private const int MAX_NAME_BYTES = 128;

    // A public key is exactly 64 hex characters; hexToRawKey() enforces that
    // too, but bounding the read keeps an oversized string out of the
    // comparison rather than relying on the validator to reject it.
    private const int MAX_KEY_HEX_BYTES = 64;

    public function __construct(
        private HttpFactory $http,
        private MulticastMdnsQuery $discovery,
        private WordCodeEncoder $wordEncoder,
    ) {}

    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null}|null
     */
    public function fetchForWordCode(string $wordCode): ?array
    {
        try {
            $tokenHex = $this->wordEncoder->decode($wordCode);
        } catch (InvalidArgumentException) {
            return null;
        }

        $tried = 0;

        foreach ($this->discovery->browse(MdnsAdvertiser::SERVICE_TYPE, self::BROWSE_TIMEOUT_SECONDS) as $peer) {
            if ($tried >= self::MAX_PEERS_TRIED) {
                break;
            }

            if (! $peer->isConnectable()) {
                continue;
            }

            $tried++;
            $offer = $this->offerFrom($peer, $tokenHex);

            if ($offer !== null) {
                return $offer;
            }
        }

        return null;
    }

    // Ask one discovered peer whether it holds this token. Null covers every
    // way that ends in "not this one" — refused, timed out, not holding it,
    // or answering with something that is not a well-formed identity.
    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null}|null
     */
    public function offerFrom(DiscoveredPeer $peer, string $tokenHex): ?array
    {
        // Plaintext http, exactly as the sync listener speaks it: the offer
        // carries public keys only, and the safety-number comparison — not
        // the transport — is what proves who answered.
        $url = "http://{$peer->host}:{$peer->port}".PairingOfferRequestHandler::OFFER_PATH;

        try {
            $response = $this->http->createPendingRequest()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get($url, ['token' => hash('sha256', $tokenHex)]);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();
        } catch (Throwable) {
            // A refused connection, a timeout, or a body that is not JSON all
            // mean the same thing here: this peer is not the one. Nothing is
            // logged — the token hash is in the request and the identity in the
            // reply, and neither belongs in a log file.
            return null;
        }

        return is_array($body) ? $this->identityFrom($body, $tokenHex) : null;
    }

    /**
     * @param  array<mixed>  $body
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null}|null
     */
    private function identityFrom(array $body, string $tokenHex): ?array
    {
        $deviceId = $this->boundedString($body, 'device_id', self::MAX_DEVICE_ID_BYTES);
        $ed25519 = $this->boundedString($body, 'ed25519', self::MAX_KEY_HEX_BYTES);
        $x25519 = $this->boundedString($body, 'x25519', self::MAX_KEY_HEX_BYTES);

        if ($deviceId === '') {
            return null;
        }

        // Both keys must decode before anything is handed on, so a hostile
        // answer cannot reach the seeding call with half an identity.
        try {
            SafetyNumberDeriver::hexToRawKey($ed25519);
            SafetyNumberDeriver::hexToRawKey($x25519);
        } catch (InvalidPublicKeyException) {
            return null;
        }

        $name = $this->boundedString($body, 'name', self::MAX_NAME_BYTES);

        return [
            'token' => $tokenHex,
            'deviceId' => $deviceId,
            'ed25519PubHex' => $ed25519,
            'x25519PubHex' => $x25519,
            // Cosmetic only, exactly as the scanned name is: it labels a row
            // and grants nothing, so a forged one is a wrong caption.
            'deviceName' => $name === '' ? null : $name,
            // Never carried here. The QR may bootstrap a relay because a
            // camera is out of band; this reply travelled the very network
            // an attacker would be sitting on.
            'relayEndpoint' => null,
            'relayAuthToken' => null,
            'relayPin' => null,
        ];
    }

    /**
     * @param  array<mixed>  $body
     */
    private function boundedString(array $body, string $key, int $maxBytes): string
    {
        $value = $body[$key] ?? null;

        return is_string($value) && $value !== '' && strlen($value) <= $maxBytes ? trim($value) : '';
    }
}
