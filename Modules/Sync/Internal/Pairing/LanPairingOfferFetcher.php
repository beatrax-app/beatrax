<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\Discovery\MulticastMdnsQuery;
use Modules\Sync\Internal\Transport\PairingOfferRequestHandler;
use Modules\Sync\Public\Enums\PairingOfferLookup;
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

    // Returns the initiator's identity, or WHY it could not be had. It used to
    // return a bare null for every ending, so the screen that asks could only
    // ever say one thing — and what it said was "check your network", to a
    // reader whose network was fine and whose code had simply expired.
    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null}|PairingOfferLookup
     */
    public function fetchForWordCode(string $wordCode): array|PairingOfferLookup
    {
        try {
            $tokenHex = $this->wordEncoder->decode($wordCode);
        } catch (InvalidArgumentException) {
            // Not a word code at all — a truncated or over-long paste. Nothing
            // was asked of the network, so the network cannot be the answer.
            return PairingOfferLookup::CodeNotAccepted;
        }

        $tried = 0;
        $anyPeerAnswered = false;

        foreach ($this->discovery->browse(MdnsAdvertiser::SERVICE_TYPE, self::BROWSE_TIMEOUT_SECONDS) as $peer) {
            if ($tried >= self::MAX_PEERS_TRIED) {
                break;
            }

            if (! $peer->isConnectable()) {
                continue;
            }

            $tried++;
            $attempt = $this->attempt($peer, $tokenHex);

            if (is_array($attempt)) {
                return $attempt;
            }

            $anyPeerAnswered = $anyPeerAnswered || $attempt === PairingOfferLookup::CodeNotAccepted;
        }

        // A peer that answered and refused is the difference that matters: the
        // network reached it, so the code is the problem. Only when nothing
        // answered at all is "are both devices on the same network?" the
        // question worth asking.
        return $anyPeerAnswered ? PairingOfferLookup::CodeNotAccepted : PairingOfferLookup::NoPeerReached;
    }

    // Ask one discovered peer whether it holds this token. Null covers every
    // way that ends in "not this one" — refused, timed out, not holding it,
    // or answering with something that is not a well-formed identity.
    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null}|null
     */
    public function offerFrom(DiscoveredPeer $peer, string $tokenHex): ?array
    {
        $attempt = $this->attempt($peer, $tokenHex);

        return is_array($attempt) ? $attempt : null;
    }

    // Keeps WHICH ending happened: a peer that answered at all, even to refuse,
    // proves the network reached it. Plaintext http as the listener speaks it —
    // the offer carries public keys only.
    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null}|PairingOfferLookup
     */
    private function attempt(DiscoveredPeer $peer, string $tokenHex): array|PairingOfferLookup
    {
        $url = "http://{$peer->host}:{$peer->port}".PairingOfferRequestHandler::OFFER_PATH;

        try {
            $response = $this->http->createPendingRequest()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get($url, ['token' => hash('sha256', $tokenHex)]);
        } catch (Throwable) {
            // Refused or timed out. Nothing is logged — the token hash is in
            // the request and the identity in the reply, and neither belongs in
            // a log file.
            return PairingOfferLookup::NoPeerReached;
        }

        // It answered. Whatever it said, the network is not the story: a 404 is
        // this peer refusing the token, and the peer refuses an unknown, an
        // expired and another user's token identically on purpose.
        if (! $response->successful()) {
            return PairingOfferLookup::CodeNotAccepted;
        }

        try {
            $body = $response->json();
        } catch (Throwable) {
            return PairingOfferLookup::CodeNotAccepted;
        }

        if (! is_array($body)) {
            return PairingOfferLookup::CodeNotAccepted;
        }

        return $this->identityFrom($body, $tokenHex) ?? PairingOfferLookup::CodeNotAccepted;
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
