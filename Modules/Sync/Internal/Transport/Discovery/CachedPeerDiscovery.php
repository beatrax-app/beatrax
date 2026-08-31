<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

use Modules\Sync\Public\Enums\LanDiscoveryReach;
use Modules\Sync\Public\Transport\ProtocolTimings;

// One pairing poll asks the network three times over — the frame puller and
// both courier deliveries — and each ask blocks for the full browse timeout.
// Peers do not move mid-ceremony, so one answer serves the whole poll.
final class CachedPeerDiscovery implements PeerDiscovery
{
    /** @var array<string, array{answeredAt: float, peers: list<DiscoveredPeer>}> */
    private array $answers = [];

    public function __construct(private readonly PeerDiscovery $inner) {}

    // Never cached alongside the peers. A cache hit skips the browse, so the
    // inner query's verdict is still the one from the browse those peers came
    // from — copying it here would only be a second place for it to go stale.
    public function reach(): LanDiscoveryReach
    {
        return $this->inner->reach();
    }

    /**
     * @return list<DiscoveredPeer>
     */
    public function browse(string $serviceType, float $timeoutSeconds = ProtocolTimings::BROWSE_SECONDS): array
    {
        $answer = $this->answers[$serviceType] ?? null;

        if ($answer !== null && (microtime(true) - $answer['answeredAt']) < ProtocolTimings::DISCOVERY_CACHE_TTL_SECONDS) {
            return $answer['peers'];
        }

        $peers = $this->inner->browse($serviceType, $timeoutSeconds);

        $this->answers[$serviceType] = ['answeredAt' => microtime(true), 'peers' => $peers];

        return $peers;
    }
}
