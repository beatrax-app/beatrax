<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

use Modules\Sync\Public\Enums\LanDiscoveryReach;
use Modules\Sync\Public\Transport\ProtocolTimings;

// The seam in front of multicast. A browse reaches a real network and always
// burns its whole timeout, so the callers need something they can cache behind
// and a test needs something it can answer for.
interface PeerDiscovery
{
    /**
     * @param  string  $serviceType  e.g. `_beatrax-sync._tcp`
     * @param  float  $timeoutSeconds  How long to keep collecting answers for.
     * @return list<DiscoveredPeer>
     */
    public function browse(string $serviceType, float $timeoutSeconds = ProtocolTimings::BROWSE_SECONDS): array;

    // Whether the last browse got its question onto the network, or — before
    // any browse — whether this runtime can ask at all. An empty list is
    // otherwise the same answer for "nobody is there" and "I am unable to
    // look", and those two want opposite things said to a reader.
    public function reach(): LanDiscoveryReach;
}
