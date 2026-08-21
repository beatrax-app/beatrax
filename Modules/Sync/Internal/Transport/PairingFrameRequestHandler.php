<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use JsonException;
use Modules\Sync\Internal\Pairing\PairingFrameApplier;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;

// The second route in front of the WebSocket, and the counterpart of the first.
// `/pair/offer` lets a responder LEARN the initiator's identity over the LAN;
// this lets it DELIVER the two frames that finish the handshake there too.
//
// Without it the frames had only one road — the relay — so two devices on one
// wifi could not pair at all unless an internet relay was configured, which the
// design says is for devices that cannot see each other.
//
// The WebSocket cannot carry them: its Noise session authenticates against the
// confirmed-device registry, and a device mid-pairing is by definition not in it
// yet. That is the chicken-and-egg this route breaks.
//
// It is not a new trust boundary. These frames already travel a channel that
// authenticates nothing — the relay is deliberately zero-knowledge — so every
// guarantee lives inside the frame: PAIR_CONFIRM is Ed25519-signed under a
// domain-separated message, PAIR_RESPONDER_ACCEPT binds first-wins and grants
// nothing on its own, and neither can advance a row without the 128-bit
// single-use token hash. Carrying them over the LAN removes a third party
// rather than adding one.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final readonly class PairingFrameRequestHandler implements RequestHandler
{
    public const string FRAME_PATH = '/pair/frame';

    private const string JSON_CONTENT_TYPE = 'application/json';

    // A frame is small. Anything larger is not one, and reading it would be
    // the cheapest denial-of-service on this listener.
    private const int MAX_BODY_BYTES = 8192;

    // The same single refusal the offer route uses, for the same reason: an
    // unknown token, an expired one, a malformed frame and one belonging to
    // another user must be indistinguishable, so probing this endpoint tells
    // an attacker nothing about which pairings are in flight.
    private const string NOT_FOUND_BODY = '{"error":"not_found"}';

    public function __construct(
        private RequestHandler $next,
        private PairingFrameApplier $applier,
        private PairingOfferRateLimiter $rateLimiter,
        private int $userId,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if ($request->getMethod() !== 'POST' || $request->getUri()->getPath() !== self::FRAME_PATH) {
            return $this->next->handleRequest($request);
        }

        // Throttled before the body is read, so a flood costs this listener a
        // map lookup rather than a buffer and a database round trip.
        if (! $this->rateLimiter->allow($this->clientKey($request))) {
            return $this->json(HttpStatus::TOO_MANY_REQUESTS, '{"error":"rate_limited"}');
        }

        $frame = $this->decodeBody($request);

        if ($frame === null || ! $this->applier->apply($this->userId, $frame)) {
            return $this->json(HttpStatus::NOT_FOUND, self::NOT_FOUND_BODY);
        }

        // Nothing to say beyond "applied". The initiator learns the outcome by
        // reading its own row, never from a body this could put words in.
        return new Response(HttpStatus::NO_CONTENT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeBody(Request $request): ?array
    {
        try {
            $body = $request->getBody()->buffer(limit: self::MAX_BODY_BYTES);
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        // buffer() throws its own type when the limit is exceeded; a body that
        // large is not a pairing frame either way.
        catch (\Throwable) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($decoded) ? $decoded : null;
    }

    // Bucketed per host, not per connection: dropping the ephemeral port makes
    // every attempt from one machine share one budget.
    private function clientKey(Request $request): string
    {
        $address = $request->getClient()->getRemoteAddress();

        return $address instanceof InternetAddress ? $address->getAddress() : $address->toString();
    }

    private function json(int $status, string $body): Response
    {
        return new Response($status, ['content-type' => self::JSON_CONTENT_TYPE], $body);
    }
}
