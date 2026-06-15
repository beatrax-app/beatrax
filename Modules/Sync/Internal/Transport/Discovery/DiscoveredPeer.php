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
     *
     * Intentionally emits plaintext `ws://` (not `wss://`) — WR-09. Transport TLS
     * is deliberately absent on the LAN-direct path: the Noise IK/XX handshake
     * provides end-to-end confidentiality, integrity, and peer authentication, so
     * it is the sole and sufficient protection. A WebSocket-layer TLS cert would
     * add no security over Noise and would require a LAN PKI the project does not
     * have.
     *
     * Callers must not call this on an unresolved peer (host==='' || port===0);
     * MdnsBrowser drops such peers before returning them (WR-09).
     */
    public function wsUrl(): string
    {
        return "ws://{$this->host}:{$this->port}/sync";
    }

    /**
     * Whether this peer has a usable host+port and can actually be connected to.
     *
     * dns-sd -B (macOS browse) yields a peer with host='' / port=0 because it does
     * not resolve host/port without a -L step. Such peers are non-connectable and
     * are filtered out by MdnsBrowser before reaching callers (WR-09).
     */
    public function isConnectable(): bool
    {
        return $this->host !== '' && $this->port > 0;
    }
}
