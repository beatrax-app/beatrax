<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

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
    public function browse(string $serviceType, float $timeoutSeconds = 2.0): array;
}
