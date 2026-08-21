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
use Modules\Sync\Internal\Pairing\PairingPullAuthorizer;

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

    // Its own allowance rather than the frame route's: a phone collecting on a
    // three-second poll spends twenty a minute, and the two routes are asked
    // for by different callers at different rates.
    public const int MAX_PER_WINDOW = 60;

    public function __construct(
        private RequestHandler $next,
        private PairingPeerOutbox $outbox,
        private PairingOfferRateLimiter $rateLimiter,
        private PairingPullAuthorizer $authorizer,
        private int $userId,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if ($request->getMethod() !== 'GET' || $request->getUri()->getPath() !== self::PULL_PATH) {
            return $this->next->handleRequest($request);
        }

        if (! $this->rateLimiter->allow($this->clientKey($request))) {
            return $this->json(HttpStatus::TOO_MANY_REQUESTS, '{"error":"rate_limited"}');
        }

        $params = $this->queryOf($request);
        $deviceId = self::stringParam($params, 'device');

        // An empty list, never a 404: whether anything is waiting for a given
        // device is exactly the thing a prober would like to learn, so a device
        // with nothing, an unproven one and one that does not exist all read
        // identically.
        if (! $this->authorizer->mayCollect($this->userId, $deviceId, self::stringParam($params, 'proof'))) {
            return $this->json(HttpStatus::OK, self::EMPTY_BODY);
        }

        $waiting = $this->outbox->takeFor($deviceId, self::MAX_FRAMES_PER_PULL);

        $body = json_encode(['frames' => array_column($waiting, 'frame')], JSON_THROW_ON_ERROR);

        // Marked delivered only once the answer exists: confirming inside the
        // read destroyed the frame before it had been handed to anyone, and a
        // response that never serialised took the handshake with it.
        $this->outbox->confirmDelivered(array_column($waiting, 'id'));

        return $this->json(HttpStatus::OK, $body);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function queryOf(Request $request): array
    {
        $params = [];
        parse_str($request->getUri()->getQuery(), $params);

        return $params;
    }

    // parse_str() builds nested arrays from `device[]=x`, so anything that is
    // not a plain string degrades to '' and is answered with the empty list.
    /**
     * @param  array<array-key, mixed>  $params
     */
    private static function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        return is_string($value) ? $value : '';
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
