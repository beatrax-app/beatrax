<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

final readonly class DiscoveredPeer
{
    /**
     * @param  string  $deviceId  The peer's device_id (from the `did=` TXT record).
     * @param  string  $host  Resolved hostname or IP address.
     * @param  int  $port  WebSocket port the peer is listening on.
     * @param  DiscoveryMode  $discoveryMode  How this peer's address was learned.
     */
    public function __construct(
        public readonly string $deviceId,
        public readonly string $host,
        public readonly int $port,
        public readonly DiscoveryMode $discoveryMode,
    ) {}

    // Intentionally emits plaintext ws:// (not wss://): the Noise IK/XX
    // handshake already provides end-to-end confidentiality, integrity, and
    // peer auth — a WebSocket-layer TLS cert would add nothing over Noise.
    public function wsUrl(): string
    {
        return "ws://{$this->host}:{$this->port}/sync";
    }

    // dns-sd -B (macOS browse) yields a peer with host=''/port=0 because it
    // does not resolve host/port without a -L step — such peers are
    // filtered out by MdnsBrowser before reaching callers.
    public function isConnectable(): bool
    {
        return $this->host !== '' && $this->port > 0;
    }
}
