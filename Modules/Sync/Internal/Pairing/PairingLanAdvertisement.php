<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Modules\Sync\Internal\Transport\Discovery\SelfLanAddress;
use Modules\Sync\Public\Services\SyncPorts;

// What this device can tell a scanning responder about reaching it directly.
// The port is the one the listener actually binds, so the address in the QR
// and the address the mDNS record advertises name the same socket.
final readonly class PairingLanAdvertisement
{
    public function __construct(
        private SelfLanAddress $address,
        private SyncPorts $ports,
    ) {}

    // A host that could not be detected leaves the pair unadvertisable, which
    // is the honest answer: the QR then carries no address and the responder
    // falls back to the roads it already had.
    public function forQr(): LanBootstrap
    {
        return new LanBootstrap($this->address->detect(), $this->ports->lan());
    }
}
