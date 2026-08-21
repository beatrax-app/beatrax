<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Pairing\PairingOfferService;
use Modules\Sync\Internal\Transport\Concerns\AnswersInJson;

// Sits in front of the sync listener's WebSocket handler and answers one
// extra route: the pairing offer a device holding only a typed word-code
// needs. Everything else is handed to the WebSocket untouched, so this adds
// a route without adding a router dependency (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final readonly class PairingOfferRequestHandler implements RequestHandler
{
    use AnswersInJson;

    public const string OFFER_PATH = '/pair/offer';

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
            return $this->rateLimited();
        }

        $token = self::stringParam($this->queryParams($request), 'token');
        $offer = $this->offers->offerFor($token, $this->userId);

        if ($offer === null) {
            return $this->notFound();
        }

        // Public identity only. The QR may also carry relay endpoint, token
        // and pin because a camera is an out-of-band channel; this one is on
        // the wire the attacker is already on, so it never carries them.
        return $this->json(HttpStatus::OK, json_encode($offer, JSON_THROW_ON_ERROR));
    }
}
