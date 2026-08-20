<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

// What has been learned about each advertised instance so far. One responder
// answers across several records and sometimes several datagrams — the port
// arrives in an SRV, the device id in a TXT — so the parser needs somewhere
// to accumulate them per instance rather than three arrays passed by hand.
/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class MdnsInstanceTable
{
    /** @var array<string, int> */
    private array $ports = [];

    /** @var array<string, string> */
    private array $deviceIds = [];

    /** @var array<string, string> */
    private array $addresses = [];

    public function recordPort(string $instance, int $port): void
    {
        $this->ports[$instance] = $port;
    }

    public function recordDeviceId(string $instance, string $deviceId): void
    {
        $this->deviceIds[$instance] = $deviceId;
    }

    // First address wins. The datagram's sender is recorded as soon as an
    // instance is seen, and a later A record for the same instance must not
    // replace an address already proven reachable by a packet arriving from it.
    public function recordAddress(string $instance, string $address): void
    {
        if ($instance === '' || isset($this->addresses[$instance])) {
            return;
        }

        $this->addresses[$instance] = $address;
    }

    /** @return list<DiscoveredPeer> */
    public function peers(DiscoveryMode $discoveryMode): array
    {
        $peers = [];

        foreach ($this->ports as $instance => $port) {
            $address = $this->addresses[$instance] ?? '';
            $deviceId = $this->deviceIds[$instance] ?? '';

            // An instance missing either is not addressable: without the
            // device id there is nothing to match against the registry, and
            // without an address there is nowhere to dial.
            if ($address === '' || $deviceId === '') {
                continue;
            }

            $peers[] = new DiscoveredPeer(
                deviceId: $deviceId,
                host: $address,
                port: $port,
                discoveryMode: $discoveryMode,
            );
        }

        return $peers;
    }
}
