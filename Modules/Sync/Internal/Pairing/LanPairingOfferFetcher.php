<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\PeerAdvertisementLimits;
use Modules\Sync\Internal\Transport\PairingHttpStatus;
use Modules\Sync\Internal\Transport\PairingOfferRequestHandler;
use Modules\Sync\Public\Enums\PairingOfferLookup;
use Throwable;

// Recovers the initiator's public identity for a token the user typed, by
// asking every device advertising the sync service on this network for its
// pairing offer. Discovery authenticates nothing and neither does this: what
// comes back is a candidate the safety-number comparison still has to prove.
final readonly class LanPairingOfferFetcher
{
    // Twice the shared bound, and deliberately so: this browse has no device
    // id to aim at, because any peer on the network might be the one holding
    // the typed code. Asking too few asks the wrong ones, which reads to the
    // person typing as a code that did not work.
    private const int MAX_PEERS_ASKED_FOR_AN_OFFER = 8;

    private const int MAX_NAME_BYTES = 128;

    // A public key is exactly 64 hex characters; hexToRawKey() enforces that
    // too, but bounding the read keeps an oversized string out of the
    // comparison rather than relying on the validator to reject it.
    private const int MAX_KEY_HEX_BYTES = 64;

    public function __construct(
        private LanPeerBrowser $peers,
        private WordCodeEncoder $wordEncoder,
    ) {}

    // Returns the initiator's identity, or WHY it could not be had. It used to
    // return a bare null for every ending, so the screen that asks could only
    // ever say one thing — and what it said was "check your network", to a
    // reader whose network was fine and whose code had simply expired.
    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null, lanHost: string, lanPort: int}|PairingOfferLookup
     */
    public function fetchForWordCode(string $wordCode): array|PairingOfferLookup
    {
        try {
            $tokenHex = $this->wordEncoder->decode($wordCode);
        } catch (InvalidArgumentException) {
            // Not a word code at all — a truncated or over-long paste. Nothing
            // was asked of the network, so the network cannot be the answer.
            return PairingOfferLookup::CodeMalformed;
        }

        return $this->askEveryPeer($tokenHex);
    }

    // An identity ends the sweep where it is found, but WHICH refusal to report
    // is not known until every peer has been asked: one peer that answered at
    // all, or one that answered 429, changes the answer for all of them. That
    // is what the two flags carry past the loop.
    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null, lanHost: string, lanPort: int}|PairingOfferLookup
     */
    private function askEveryPeer(string $tokenHex): array|PairingOfferLookup
    {
        $anyPeerAnswered = false;
        $anyPeerLimited = false;

        foreach ($this->peers->eachConnectablePeer(self::MAX_PEERS_ASKED_FOR_AN_OFFER) as $peer) {
            $attempt = $this->attempt($peer, $tokenHex);

            if (is_array($attempt)) {
                return $attempt;
            }

            $anyPeerLimited = $anyPeerLimited || $attempt === PairingOfferLookup::RateLimited;
            $anyPeerAnswered = $anyPeerAnswered || $attempt !== PairingOfferLookup::NoPeerReached;
        }

        // A limit outranks a refusal: "wait a minute" is true whichever peer
        // holds the code, while "ask for a new one" is false for the limited
        // one and puts the next attempt in the same bucket.
        if ($anyPeerLimited) {
            return PairingOfferLookup::RateLimited;
        }

        // A peer that answered and refused is the difference that matters: the
        // network reached it, so the code is the problem. Only when nothing
        // answered at all is "are both devices on the same network?" the
        // question worth asking.
        return $anyPeerAnswered ? PairingOfferLookup::CodeNotAccepted : PairingOfferLookup::NoPeerReached;
    }

    // Keeps WHICH ending happened: a peer that answered at all, even to refuse,
    // proves the network reached it.
    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null, lanHost: string, lanPort: int}|PairingOfferLookup
     */
    private function attempt(DiscoveredPeer $peer, string $tokenHex): array|PairingOfferLookup
    {
        $url = "http://{$peer->host}:{$peer->port}".PairingOfferRequestHandler::OFFER_PATH;

        try {
            $response = $this->peers->peerRequest()
                ->get($url, ['token' => hash('sha256', $tokenHex)]);
        } catch (Throwable) {
            // Refused or timed out. Nothing is logged — the token hash is in
            // the request and the identity in the reply, and neither belongs in
            // a log file.
            return PairingOfferLookup::NoPeerReached;
        }

        // The rate limit is the one refusal that is neither the code nor the
        // network. Read off the same constant the handler answers with, so
        // the two cannot drift.
        $rateLimited = $response->status() === PairingHttpStatus::TOO_MANY_REQUESTS;
        $identity = $rateLimited ? null : $this->identityFrom(self::offeredBody($response), $tokenHex);

        if ($identity === null) {
            return $rateLimited ? PairingOfferLookup::RateLimited : PairingOfferLookup::CodeNotAccepted;
        }

        // The endpoint this request actually reached, never the reply — the
        // relay fields below are dropped for exactly that reason. Where the
        // offer came from is an observation of the transport, and the sync
        // that follows has no other way to learn it.
        return [...$identity, 'lanHost' => $peer->host, 'lanPort' => $peer->port];
    }

    // Empty for every answer that carries no readable offer, which identityFrom
    // refuses exactly as it refuses a hostile one. It answered, so the network
    // is not the story: a 404 is this peer refusing the token, and the peer
    // refuses an unknown, an expired and another user's token identically.
    /**
     * @return array<mixed>
     */
    private static function offeredBody(Response $response): array
    {
        if (! $response->successful()) {
            return [];
        }

        try {
            $body = $response->json();
        } catch (Throwable) {
            return [];
        }

        return is_array($body) ? $body : [];
    }

    /**
     * @param  array<mixed>  $body
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: null, relayAuthToken: null, relayPin: null}|null
     */
    private function identityFrom(array $body, string $tokenHex): ?array
    {
        $deviceId = $this->boundedString($body, 'device_id', PeerAdvertisementLimits::MAX_DEVICE_ID_BYTES);
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
