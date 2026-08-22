<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Pairing\PairingPeerOutbox;
use Modules\Sync\Internal\Pairing\PairingPullAuthorizer;
use Modules\Sync\Internal\Transport\Concerns\AnswersInJson;

// The return leg: only one side of a pairing listens, so a device that runs no
// server is never dialled and has to collect instead (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#the-two-roads-and-why-the-lan-one-had-to-be-built
 */
final readonly class PairingFramePullHandler implements RequestHandler
{
    use AnswersInJson;

    public const string PULL_PATH = '/pair/frames';

    // A handshake has two frames in flight at the very most; this bounds one
    // answer without bounding how many times a device may come back.
    private const int MAX_FRAMES_PER_PULL = 8;

    private const string EMPTY_BODY = '{"frames":[]}';

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
            return $this->rateLimited();
        }

        $params = $this->queryParams($request);

        return $this->framesFor(
            self::stringParam($params, 'device'),
            self::stringParam($params, 'proof'),
        );
    }

    // An empty list, never a 404: whether anything is waiting for a given
    // device is exactly the thing a prober would like to learn, so a device
    // with nothing, an unproven one and one that does not exist all read
    // identically.
    private function framesFor(string $deviceId, string $proofSigHex): Response
    {
        if (! $this->authorizer->mayCollect($this->userId, $deviceId, $proofSigHex)) {
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
}
