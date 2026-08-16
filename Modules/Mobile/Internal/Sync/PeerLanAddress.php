<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Modules\Sync\Public\Services\RelayEndpointHost;

// Resolves the desktop's LAN address for a direct sync dial. The puller only
// attempts the LAN leg when given a host and port, and every caller passed
// neither — so the leg never ran, and the relay fallback drains a mailbox
// without applying rows, leaving the device at "0 of 0 records".
/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final readonly class PeerLanAddress
{
    // Mirrors config/sync.php 'port'. Duplicated because the config() global
    // helper is unavailable under phpstan L10's noGlobalLaravelFunction rule.
    public const SYNC_PORT = 51337;

    public function __construct(
        private RelayEndpointHost $relayHost,
    ) {}

    // The relay endpoint learned from the QR names the desktop that issued it,
    // so its host is the same machine `sync:serve` listens on. That is the
    // only address this device is ever told; the QR carries no other.
    public function host(): ?string
    {
        return $this->relayHost->host();
    }

    public function port(): int
    {
        return self::SYNC_PORT;
    }
}
