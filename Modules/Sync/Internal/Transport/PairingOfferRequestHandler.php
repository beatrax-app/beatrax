<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Pairing\PairingOfferService;

// Sits in front of the sync listener's WebSocket handler and answers one
// extra route: the pairing offer a device holding only a typed word-code
// needs. Everything else is handed to the WebSocket untouched, so this adds
// a route without adding a router dependency (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final readonly class PairingOfferRequestHandler implements RequestHandler
{
    public const string OFFER_PATH = '/pair/offer';

    private const string JSON_CONTENT_TYPE = 'application/json';

    // One body for every refusal. An unknown token, an expired one, one
    // belonging to another user and one whose ceremony already finished are
    // indistinguishable on purpose: an attacker probing this endpoint must
    // learn nothing beyond "no".
    private const string NOT_FOUND_BODY = '{"error":"not_found"}';

    public function __construct(
        private RequestHandler $websocket,
        private PairingOfferService $offers,
        private PairingOfferRateLimiter $rateLimiter,
        private int $userId,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if ($request->getMethod() !== 'GET' || $request->getUri()->getPath() !== self::OFFER_PATH) {
            return $this->websocket->handleRequest($request);
        }

        return $this->offerResponse($request);
    }

    private function offerResponse(Request $request): Response
    {
        // Throttle before the lookup, so a flood is refused on the cheapest
        // possible path and never reaches the database.
        if (! $this->rateLimiter->allow($this->clientKey($request))) {
            return $this->json(HttpStatus::TOO_MANY_REQUESTS, '{"error":"rate_limited"}');
        }

        $offer = $this->offers->offerFor($this->tokenFrom($request), $this->userId);

        if ($offer === null) {
            return $this->json(HttpStatus::NOT_FOUND, self::NOT_FOUND_BODY);
        }

        // Public identity only. The QR may also carry relay endpoint, token
        // and pin because a camera is an out-of-band channel; this one is on
        // the wire the attacker is already on, so it never carries them.
        return $this->json(HttpStatus::OK, json_encode($offer, JSON_THROW_ON_ERROR));
    }

    // parse_str() builds nested arrays from `token[]=x`, so anything that is
    // not a plain string degrades to '' and is refused by the lookup.
    private function tokenFrom(Request $request): string
    {
        /** @var array<string, mixed> $params */
        $params = [];
        parse_str($request->getUri()->getQuery(), $params);

        $token = $params['token'] ?? null;

        return is_string($token) ? $token : '';
    }

    // The source the throttle buckets on. Dropping the ephemeral port makes
    // every connection from one host share a single bucket.
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
