<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Modules\Sync\Public\Services\RelayEndpointHost;

// The puller only attempts the LAN leg when given a host and port, and every
// caller passed neither — so the leg never ran, and the relay fallback drains
// a mailbox without applying rows, leaving the device at "0 of 0 records".
final readonly class PeerLanAddress
{
    // Mirrors config/sync.php 'port'. Duplicated because the config() global
    // helper is unavailable under phpstan L10's noGlobalLaravelFunction rule.
    public const SYNC_PORT = 51337;

    public function __construct(
        private RelayEndpointHost $relayHost,
    ) {}

    // The QR's relay endpoint names the desktop that issued it, so its host is
    // the machine `sync:serve` listens on — and the only address ever told.
    public function host(): ?string
    {
        return $this->relayHost->host();
    }

    public function port(): int
    {
        return self::SYNC_PORT;
    }
}
