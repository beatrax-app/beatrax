<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Pairing\PairingPeerOutbox;

// The return leg: only one side of a pairing listens, so a device that runs no
// server is never dialled and has to collect instead (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#the-two-roads-and-why-the-lan-one-had-to-be-built
 */
final readonly class PairingFramePullHandler implements RequestHandler
{
    public const string PULL_PATH = '/pair/frames';

    private const string JSON_CONTENT_TYPE = 'application/json';

    // A handshake has two frames in flight at the very most; this bounds one
    // answer without bounding how many times a device may come back.
    private const int MAX_FRAMES_PER_PULL = 8;

    private const string EMPTY_BODY = '{"frames":[]}';

    public function __construct(
        private RequestHandler $next,
        private PairingPeerOutbox $outbox,
        private PairingOfferRateLimiter $rateLimiter,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if ($request->getMethod() !== 'GET' || $request->getUri()->getPath() !== self::PULL_PATH) {
            return $this->next->handleRequest($request);
        }

        if (! $this->rateLimiter->allow($this->clientKey($request))) {
            return $this->json(HttpStatus::TOO_MANY_REQUESTS, '{"error":"rate_limited"}');
        }

        $deviceId = $this->deviceFrom($request);

        // An empty list, never a 404: whether anything is waiting for a given
        // device is exactly the thing a prober would like to learn, so a device
        // with nothing and a device that does not exist read identically.
        if ($deviceId === '') {
            return $this->json(HttpStatus::OK, self::EMPTY_BODY);
        }

        $frames = $this->outbox->takeFor($deviceId, self::MAX_FRAMES_PER_PULL);

        return $this->json(HttpStatus::OK, json_encode(['frames' => $frames], JSON_THROW_ON_ERROR));
    }

    // parse_str() builds nested arrays from `device[]=x`, so anything that is
    // not a plain string degrades to '' and is answered with the empty list.
    private function deviceFrom(Request $request): string
    {
        /** @var array<string, mixed> $params */
        $params = [];
        parse_str($request->getUri()->getQuery(), $params);

        $device = $params['device'] ?? null;

        return is_string($device) ? $device : '';
    }

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
