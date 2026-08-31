<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Transport\ProtocolTimings;

// The browse-and-dial every LAN pairing road runs. Discovery authenticates
// nothing, so the peer cap here and the timings it draws from ProtocolTimings
// are the whole defence: a responder can answer one browse many times over,
// and each answer would otherwise cost another request nothing can tell apart.

// Injected, not mixed in. As a trait this reached into $this->http and
// $this->discovery on whichever class used it, so each of the three roads
// declared two collaborators its own body never named — which reads, to a
// reader and to a static analyser alike, as two dependencies nothing uses.
final readonly class LanPeerBrowser
{
    // Enough for a caller that already knows which device it wants, since the
    // answers past the first are duplicates of that one device. A caller with
    // no device id to aim at has a different question to ask and passes its
    // own bound.
    private const int MAX_PEERS_TRIED = 4;

    public function __construct(
        private HttpFactory $http,
        private PeerDiscovery $discovery,
    ) {}

    /**
     * @param  ?string  $deviceId  Only peers advertising this id count against $max.
     * @return iterable<int, DiscoveredPeer>
     */
    public function eachConnectablePeer(int $max = self::MAX_PEERS_TRIED, ?string $deviceId = null): iterable
    {
        $tried = 0;

        foreach ($this->discovery->browse(MdnsAdvertiser::SERVICE_TYPE, ProtocolTimings::BROWSE_SECONDS) as $peer) {
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
    public function peerRequest(): PendingRequest
    {
        return $this->http->createPendingRequest()
            ->connectTimeout(ProtocolTimings::PAIRING_PROBE_CONNECT_SECONDS)
            ->timeout(ProtocolTimings::PAIRING_PROBE_REQUEST_SECONDS);
    }
}
