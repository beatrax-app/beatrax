<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

/**
 * Immutable value object for a discovered sync peer.
 *
 * Returned by MdnsBrowser::browse() and MdnsBrowser::discoverPeers().
 *
 * @internal Plan 04.
 */
final readonly class DiscoveredPeer
{
    /**
     * @param  string  $deviceId  The peer's device_id (from the `did=` TXT record).
     * @param  string  $host  Resolved hostname or IP address.
     * @param  int  $port  WebSocket port the peer is listening on.
     * @param  string  $discoveryMode  How this peer was discovered: 'mdns' | 'manual' | 'relay'.
     */
    public function __construct(
        public readonly string $deviceId,
        public readonly string $host,
        public readonly int $port,
        public readonly string $discoveryMode,
    ) {}

    /**
     * The WebSocket URL to connect to this peer.
     */
    public function wsUrl(): string
    {
        return "ws://{$this->host}:{$this->port}/sync";
    }
}
