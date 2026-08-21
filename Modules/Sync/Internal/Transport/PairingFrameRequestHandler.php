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
use Modules\Sync\Internal\Pairing\PairingFrameOutcome;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;

// Carries a responder's frames to the device that listens. The WebSocket cannot:
// its Noise session authenticates against the confirmed-device registry, and a
// device mid-pairing is not in it yet (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#the-two-roads-and-why-the-lan-one-had-to-be-built
 */
final readonly class PairingFrameRequestHandler implements RequestHandler
{
    public const string FRAME_PATH = '/pair/frame';

    private const string JSON_CONTENT_TYPE = 'application/json';

    // A frame is small. Anything larger is not one, and reading it would be
    // the cheapest denial-of-service on this listener.
    private const int MAX_BODY_BYTES = 8192;

    // Sized for the caller this route actually has: a phone re-emitting on a
    // three-second poll spends twenty a minute by itself, so the offer route's
    // human-sized allowance would throttle an honest handshake within half a
    // minute — and report it as a code that did not work.
    public const int MAX_PER_WINDOW = 60;

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

        // Compared against the case, never coerced: an enum instance is always
        // truthy, so negating it silently answered "applied" to every body that
        // merely parsed — refusals included.
        $outcome = $frame === null
            ? PairingFrameOutcome::Refused
            : $this->applier->apply($this->userId, $frame);

        if ($outcome === PairingFrameOutcome::Refused) {
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
            $payload = $request->getBody()->buffer(limit: self::MAX_BODY_BYTES);
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
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
