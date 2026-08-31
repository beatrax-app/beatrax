<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// The peer said, over its authenticated Noise session, that it no longer
// confirms this device. Distinct from a disconnect because retrying cannot
// help: the trust is gone rather than the network, so the caller tears the
// session down AND stops presenting the peer as one it still syncs with.
final class PeerRevokedException extends RuntimeException
{
    public static function toldByPeer(string $peerDeviceId): self
    {
        return new self("Sync peer {$peerDeviceId} no longer confirms this device.");
    }
}
