<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;

// The browse-and-dial every LAN pairing road runs. Discovery authenticates
// nothing, so the bounds below are the whole defence: a responder can answer
// one browse many times over, and each answer would otherwise cost another
// request from a device that has no way to tell the answers apart.
trait BrowsesLanPeers
{
    // Long enough for a desktop on the same subnet to answer, short enough
    // that a phone with nothing to find says so rather than hanging.
    private const float BROWSE_TIMEOUT_SECONDS = 2.0;

    private const int CONNECT_TIMEOUT_SECONDS = 1;

    private const int REQUEST_TIMEOUT_SECONDS = 2;

    // Enough for a caller that already knows which device it wants, since the
    // answers past the first are duplicates of that one device. A caller with
    // no device id to aim at has a different question to ask and passes its
    // own bound.
    private const int MAX_PEERS_TRIED = 4;

    /**
     * @param  ?string  $deviceId  Only peers advertising this id count against $max.
     * @return iterable<int, DiscoveredPeer>
     */
    private function eachConnectablePeer(int $max = self::MAX_PEERS_TRIED, ?string $deviceId = null): iterable
    {
        $tried = 0;

        foreach ($this->discovery->browse(MdnsAdvertiser::SERVICE_TYPE, self::BROWSE_TIMEOUT_SECONDS) as $peer) {
            if ($tried >= $max) {
                break;
            }

            // A PTR answer without an SRV names an instance it cannot address,
            // and a peer advertising another id is not the one being asked.
            if (! $peer->isConnectable() || ($deviceId !== null && $peer->deviceId !== $deviceId)) {
                continue;
            }

            $tried++;

            yield $peer;
        }
    }

    // Plaintext http as the listener speaks it: everything these roads carry
    // is either signed or worthless to an eavesdropper, and the safety-number
    // comparison — never the transport — is the trust gate.
    private function peerRequest(): PendingRequest
    {
        return $this->http->createPendingRequest()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS);
    }
}
