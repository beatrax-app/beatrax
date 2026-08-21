<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

// One pairing poll asks the network three times over — the frame puller and
// both courier deliveries — and each ask blocks for the full browse timeout.
// Peers do not move mid-ceremony, so one answer serves the whole poll.
final class CachedPeerDiscovery implements PeerDiscovery
{
    // Shorter than the three-second poll that drives it, so every poll still
    // gets a fresh answer while the calls inside one poll share it.
    private const float TTL_SECONDS = 2.5;

    /** @var array<string, array{answeredAt: float, peers: list<DiscoveredPeer>}> */
    private array $answers = [];

    public function __construct(private readonly PeerDiscovery $inner) {}

    /**
     * @return list<DiscoveredPeer>
     */
    public function browse(string $serviceType, float $timeoutSeconds = 2.0): array
    {
        $answer = $this->answers[$serviceType] ?? null;

        if ($answer !== null && (microtime(true) - $answer['answeredAt']) < self::TTL_SECONDS) {
            return $answer['peers'];
        }

        $peers = $this->inner->browse($serviceType, $timeoutSeconds);

        $this->answers[$serviceType] = ['answeredAt' => microtime(true), 'peers' => $peers];

        return $peers;
    }
}
