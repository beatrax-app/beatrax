<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\Discovery\MulticastMdnsQuery;
use Modules\Sync\Internal\Transport\PairingFramePullHandler;
use Throwable;

// The phone's half of the return leg: it cannot be dialled, so it asks. Whatever
// answers goes through the same applier every road uses, so a hostile peer wastes
// this device's time and nothing else (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#the-two-roads-and-why-the-lan-one-had-to-be-built
 */
final readonly class LanPairingFramePuller
{
    private const float BROWSE_TIMEOUT_SECONDS = 2.0;

    private const int CONNECT_TIMEOUT_SECONDS = 1;

    private const int REQUEST_TIMEOUT_SECONDS = 2;

    private const int MAX_PEERS_TRIED = 4;

    // One answer cannot hand this device an unbounded amount of work.
    private const int MAX_FRAMES_APPLIED = 8;

    public function __construct(
        private HttpFactory $http,
        private MulticastMdnsQuery $discovery,
        private PairingFrameApplier $applier,
    ) {}

    // Returns how many frames were applied, so a caller can tell "nothing was
    // waiting" from "something arrived and moved the handshake on".
    public function pullAndApply(int $userId, string $ownDeviceId): int
    {
        if ($ownDeviceId === '') {
            return 0;
        }

        $applied = 0;
        $tried = 0;

        foreach ($this->discovery->browse(MdnsAdvertiser::SERVICE_TYPE, self::BROWSE_TIMEOUT_SECONDS) as $peer) {
            if ($tried >= self::MAX_PEERS_TRIED) {
                break;
            }

            if (! $peer->isConnectable()) {
                continue;
            }

            $tried++;

            foreach ($this->framesFrom($peer->host, $peer->port, $ownDeviceId) as $frame) {
                if ($applied >= self::MAX_FRAMES_APPLIED) {
                    break 2;
                }

                if ($this->applier->apply($userId, $frame) === PairingFrameOutcome::Applied) {
                    $applied++;
                }
            }
        }

        return $applied;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function framesFrom(string $host, int $port, string $ownDeviceId): array
    {
        $url = "http://{$host}:{$port}".PairingFramePullHandler::PULL_PATH;

        try {
            $response = $this->http->createPendingRequest()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get($url, ['device' => $ownDeviceId]);

            if (! $response->successful()) {
                return [];
            }

            $body = $response->json();
        } catch (Throwable) {
            // Refused, timed out, or not answering with JSON. Nothing is
            // logged: the device id is in the request and pairing material in
            // the reply, and neither belongs in a log file.
            return [];
        }

        if (! is_array($body) || ! isset($body['frames']) || ! is_array($body['frames'])) {
            return [];
        }

        $frames = [];

        foreach ($body['frames'] as $frame) {
            if (is_array($frame)) {
                /** @var array<string, mixed> $frame */
                $frames[] = $frame;
            }
        }

        return $frames;
    }
}
