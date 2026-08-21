<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

// How a peer's address was learned. Was three loose strings duplicated across
// the browsers, which is exactly the shape that lets a typo become a mode
// nothing matches on.
enum DiscoveryMode: string
{
    case Mdns = 'mdns';

    case Manual = 'manual';

    case Relay = 'relay';

    // Whether the address came from the local network rather than from
    // configuration or a relay. Discovery on the wire is unauthenticated, so
    // this marks an address as a candidate the handshake still has to prove.
    public function isFromNetwork(): bool
    {
        return $this === self::Mdns;
    }
}
